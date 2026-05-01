<?php

declare(strict_types=1);

class EventMcpTokenCreate extends AbstractEvent implements iBrowserEventDocumentable
{
	public function authorize(PolicyContext $policyContext): PolicyDecision
	{
		return User::getCurrentUserId() > 0
			? PolicyDecision::allow()
			: PolicyDecision::deny('login required');
	}

	public static function describeBrowserEvent(): array
	{
		return [
			'event_name' => 'mcp.token-create',
			'group' => 'MCP',
			'name' => 'Create personal MCP token',
			'summary' => 'Creates a personal MCP token for the current user.',
			'description' => 'The token secret is returned once at creation time and only the hash is stored.',
			'request' => [
				'method' => 'POST',
				'params' => [
					BrowserEventDocumentationHelper::param('name', 'body', 'string', false, 'Human-facing token label.'),
					BrowserEventDocumentationHelper::param('days', 'body', 'int', false, 'Expiry in days. Use 0 for no expiry.'),
					BrowserEventDocumentationHelper::param('format', 'body', 'string', false, 'Use json for JSON output; defaults to HTML panel.'),
				],
			],
			'response' => [
				'kind' => 'json-or-html',
				'content_type' => 'application/json or text/html',
				'description' => 'Returns the created token once for JSON callers or the refreshed token panel for the GUI.',
			],
			'authorization' => [
				'visibility' => 'current-user',
				'description' => 'Any logged-in user can create their own MCP token.',
			],
			'side_effects' => BrowserEventDocumentationHelper::lines('Inserts one mcp_tokens row.'),
		];
	}

	public function run(): void
	{
		$user_id = User::getCurrentUserId();

		if (Request::getMethod() !== 'POST') {
			header('Allow: POST');

			if (self::wantsJson()) {
				ApiResponse::renderError('METHOD_NOT_ALLOWED', 'This endpoint accepts POST requests only.', 405);

				return;
			}

			http_response_code(405);
			McpTokenPanelView::renderPanelForUser($user_id, null, 'This endpoint accepts POST requests only.');

			return;
		}

		$name = trim((string) Request::_POST('name', 'MCP token'));
		$days = self::parseDays(Request::_POST('days', (string) McpTokenService::DEFAULT_EXPIRY_DAYS));

		try {
			$result = McpTokenService::createToken($user_id, $name, $days, $user_id);
		} catch (Throwable $exception) {
			if (self::wantsJson()) {
				ApiResponse::renderError('MCP_TOKEN_CREATE_FAILED', $exception->getMessage(), 400);

				return;
			}

			McpTokenPanelView::renderPanelForUser($user_id, null, $exception->getMessage());

			return;
		}

		if (self::wantsJson()) {
			ApiResponse::renderSuccess($result);

			return;
		}

		McpTokenPanelView::renderPanelForUser($user_id, $result);
	}

	private static function parseDays(mixed $value): int
	{
		if (is_int($value)) {
			return max(0, min(3650, $value));
		}

		if (is_string($value)) {
			$value = trim($value);

			if ($value !== '' && ctype_digit($value)) {
				return min(3650, (int) $value);
			}
		}

		return McpTokenService::DEFAULT_EXPIRY_DAYS;
	}

	private static function wantsJson(): bool
	{
		return Request::_GET('format', '') === 'json'
			|| Request::_POST('format', '') === 'json';
	}
}
