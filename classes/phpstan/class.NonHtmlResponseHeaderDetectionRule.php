<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<ArrayDimFetch>
 */
final class NonHtmlResponseHeaderDetectionRule implements Rule
{
	private const array RESPONSE_TYPE_HEADERS = [
		'HTTP_ACCEPT' => true,
		'http_accept' => true,
		'HTTP_X_REQUESTED_WITH' => true,
		'http_x_requested_with' => true,
		'HTTP_HX_REQUEST' => true,
		'http_hx_request' => true,
	];

	private const array ALLOWED_PATH_SUFFIXES = [
		// Canonical helper. See ai-task-manager discussion:
		// docs/discussions/2026-05-05-non-html-response-detection.md
		'/classes/class.Request.php',
	];

	public function getNodeType(): string
	{
		return ArrayDimFetch::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		if (!$node instanceof ArrayDimFetch || !$node->dim instanceof String_) {
			return [];
		}

		if (!isset(self::RESPONSE_TYPE_HEADERS[$node->dim->value])) {
			return [];
		}

		if (self::isAllowedFile($scope->getFile())) {
			return [];
		}

		if (!self::isServerHeaderRead($node->var)) {
			return [];
		}

		return [
			RuleErrorBuilder::message('Use Request::wantsNonHtmlResponse() instead of manually detecting response type from request headers.')
				->identifier('radaptor.request.nonHtmlResponseDetection')
				->build(),
		];
	}

	private static function isAllowedFile(string $file): bool
	{
		$file = str_replace('\\', '/', $file);

		foreach (self::ALLOWED_PATH_SUFFIXES as $suffix) {
			if (str_ends_with($file, $suffix)) {
				return true;
			}
		}

		return false;
	}

	private static function isServerHeaderRead(Expr $expr): bool
	{
		if ($expr instanceof Variable && in_array($expr->name, ['_SERVER', 'server', 'SERVER'], true)) {
			return true;
		}

		if (!$expr instanceof PropertyFetch) {
			return false;
		}

		if ($expr->name instanceof Identifier) {
			return $expr->name->toString() === 'SERVER';
		}

		return false;
	}
}
