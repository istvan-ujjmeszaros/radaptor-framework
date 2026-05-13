<?php

declare(strict_types=1);

class McpApiEventResolver
{
	/**
	 * @return list<array<string, mixed>>
	 */
	public function listTools(): array
	{
		$tools = [];

		foreach ($this->getEnabledEventDocs() as $meta) {
			$tools[] = McpToolSchemaBuilder::buildTool($meta);
		}

		usort($tools, static fn (array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name']));

		return $tools;
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @return array<string, mixed>
	 */
	public function callTool(string $tool_name, array $arguments): array
	{
		$meta = $this->getEventMetaForTool($tool_name);

		if ($meta === null) {
			throw new InvalidArgumentException("Unknown MCP tool: {$tool_name}");
		}

		if (class_exists(RuntimeSiteCutoverGuard::class) && RuntimeSiteCutoverGuard::shouldBlockMcpTool($meta)) {
			return self::toolError('site_cutover_readonly', RuntimeSiteCutoverGuard::readonlyMessage());
		}

		try {
			[$get, $post] = $this->mapArguments($meta, $arguments);
		} catch (InvalidArgumentException $exception) {
			return self::toolError('validation_failed', $exception->getMessage());
		}

		$ctx = RequestContextHolder::current();
		$snapshot = [
			'GET' => $ctx->GET,
			'POST' => $ctx->POST,
			'SERVER' => $ctx->SERVER,
			'currentEvent' => $ctx->currentEvent,
			'apiResponseCaptureEnabled' => $ctx->apiResponseCaptureEnabled,
			'capturedApiResponse' => $ctx->capturedApiResponse,
			'capturedApiResponseHttpCode' => $ctx->capturedApiResponseHttpCode,
		];

		try {
			return $this->dispatchTool($tool_name, $meta, $get, $post, $ctx);
		} finally {
			$ctx->GET = $snapshot['GET'];
			$ctx->POST = $snapshot['POST'];
			$ctx->SERVER = $snapshot['SERVER'];
			$ctx->currentEvent = $snapshot['currentEvent'];
			$ctx->apiResponseCaptureEnabled = $snapshot['apiResponseCaptureEnabled'];
			$ctx->capturedApiResponse = $snapshot['capturedApiResponse'];
			$ctx->capturedApiResponseHttpCode = $snapshot['capturedApiResponseHttpCode'];
		}
	}

	/**
	 * @param array<string, mixed> $meta
	 * @param array<string, mixed> $get
	 * @param array<string, mixed> $post
	 * @return array<string, mixed>
	 */
	private function dispatchTool(string $tool_name, array $meta, array $get, array $post, RequestContext $ctx): array
	{
		$ctx->GET = $get;
		$ctx->POST = $post;
		$request_method = strtoupper((string) ($meta['request']['method'] ?? 'POST'));

		if (!in_array($request_method, ['GET', 'POST'], true)) {
			$request_method = 'POST';
		}

		$ctx->SERVER['REQUEST_METHOD'] = $request_method;
		$ctx->SERVER['request_method'] = strtolower($request_method);
		$ctx->capturedApiResponse = null;
		$ctx->capturedApiResponseHttpCode = null;
		$ctx->apiResponseCaptureEnabled = false;

		$class_name = (string) ($meta['class'] ?? '');

		if ($class_name === '' || !class_exists($class_name)) {
			throw new RuntimeException("MCP event class is not loadable for tool: {$tool_name}");
		}

		$event = new $class_name();

		if (!($event instanceof iEvent)) {
			throw new RuntimeException("MCP event class does not implement iEvent: {$class_name}");
		}

		$ctx->currentEvent = $event;

		if (!($event instanceof iAuthorizable)) {
			return self::toolError('authorization_denied', 'Event does not implement authorization.');
		}

		$decision = $event->authorize(PolicyContext::fromEvent($event));

		if (!$decision->allow) {
			return self::toolError('authorization_denied', $decision->reason);
		}

		try {
			$ctx->apiResponseCaptureEnabled = true;
			$event->run();
		} catch (RequestParamException $exception) {
			return self::toolError('validation_failed', $exception->getMessage());
		} catch (InvalidArgumentException $exception) {
			return self::toolError('validation_failed', $exception->getMessage());
		} catch (RuntimeException $exception) {
			return self::toolError('execution_failed', $exception->getMessage());
		} finally {
			$ctx->apiResponseCaptureEnabled = false;
		}

		$response = $ctx->capturedApiResponse;

		if (!is_array($response)) {
			return self::toolError('missing_structured_response', 'MCP Event did not produce an ApiResponse.');
		}

		if (($response['ok'] ?? null) === false) {
			$error = is_array($response['error'] ?? null) ? $response['error'] : [];
			$error_code = (string) ($error['code'] ?? $error['code_id'] ?? 'tool_error');
			$message = (string) ($error['message'] ?? $response['message'] ?? 'Tool returned an error.');

			return [
				'isError' => true,
				'content' => self::contentBlocks($message, $response),
				'structuredContent' => $response,
				'_mcp_error_code' => $error_code,
			];
		}

		return [
			'content' => self::contentBlocks((string) ($response['message'] ?? 'OK'), $response),
			'structuredContent' => $response,
		];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function getEnabledEventDocs(): array
	{
		$enabled = [];

		foreach (BrowserEventDocsRegistry::getAllEvents() as $slug => $meta) {
			$mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];

			if (($mcp['enabled'] ?? false) !== true) {
				continue;
			}

			$enabled[$slug] = $meta;
		}

		return $enabled;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function getEventMetaForTool(string $tool_name): ?array
	{
		foreach ($this->getEnabledEventDocs() as $meta) {
			if (McpToolSchemaBuilder::getToolName($meta) === $tool_name) {
				return $meta;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $meta
	 * @param array<string, mixed> $arguments
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function mapArguments(array $meta, array $arguments): array
	{
		$params = $meta['request']['params'] ?? [];
		$get = [];
		$post = [];

		if (!is_array($params)) {
			return [$get, $post];
		}

		foreach ($params as $param) {
			if (!is_array($param)) {
				continue;
			}

			$name = (string) ($param['name'] ?? '');

			if ($name === '') {
				continue;
			}

			if (($param['required'] ?? false) === true && !array_key_exists($name, $arguments)) {
				throw new InvalidArgumentException("Missing required argument: {$name}");
			}

			if (!array_key_exists($name, $arguments)) {
				continue;
			}

			$source = (string) ($param['source'] ?? 'body');

			if ($source === 'query') {
				$get[$name] = $arguments[$name];
			} else {
				$post[$name] = $arguments[$name];
			}
		}

		return [$get, $post];
	}

	/**
	 * Content blocks include the human summary and a JSON mirror of
	 * structuredContent, per MCP 2025-11-25 back-compat guidance for clients
	 * that don't read structuredContent.
	 *
	 * @param array<string, mixed> $structured
	 * @return list<array<string, string>>
	 */
	private static function contentBlocks(string $summary, array $structured): array
	{
		$blocks = [
			[
				'type' => 'text',
				'text' => $summary,
			],
		];

		try {
			$json = json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			$blocks[] = [
				'type' => 'text',
				'text' => $json,
			];
		} catch (JsonException) {
			// Skip the JSON mirror if the structured content is not encodable;
			// the summary block already conveys the result and structuredContent
			// will still ship at the result level.
		}

		return $blocks;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function toolError(string $error_code, string $reason): array
	{
		$structured = [
			'error_code' => $error_code,
			'reason' => $reason,
		];

		return [
			'isError' => true,
			'content' => self::contentBlocks($reason, $structured),
			'structuredContent' => $structured,
			'_mcp_error_code' => $error_code,
		];
	}
}
