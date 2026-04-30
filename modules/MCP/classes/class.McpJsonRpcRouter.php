<?php

declare(strict_types=1);

class McpJsonRpcRouter
{
	private const string SUPPORTED_PROTOCOL_VERSION = '2025-11-25';
	private const string BACKWARD_COMPATIBILITY_PROTOCOL_VERSION = '2025-03-26';
	private const int HEADER_MISMATCH_ERROR_CODE = -32001;

	private McpApiEventResolver $resolver;

	public function __construct()
	{
		$this->resolver = new McpApiEventResolver();
	}

	/**
	 * @param array<string, mixed> $headers
	 * @param array<string, mixed> $server
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public function handle(string $body, array $headers, array $server): array
	{
		$request_id = self::uuid();
		$started = microtime(true);
		$response_headers = [
			'Content-Type' => 'application/json',
			'X-Radaptor-MCP-Request-Id' => $request_id,
		];

		if (!McpAuthenticator::validateOrigin($headers)) {
			McpRequestLogger::log($request_id, null, null, null, null, 'rejected', 'invalid_origin', self::durationMs($started), self::ip($server), self::userAgent($headers));

			return [
				'status' => 403,
				'headers' => $response_headers,
				'body' => json_encode(['error' => 'Invalid Origin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			];
		}

		try {
			$payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			McpRequestLogger::log($request_id, null, null, null, null, 'protocol_error', 'parse_error', self::durationMs($started), self::ip($server), self::userAgent($headers));

			return $this->jsonRpcResponse(200, $response_headers, self::error(null, -32700, 'Parse error'));
		}

		if (!is_array($payload) || array_is_list($payload)) {
			McpRequestLogger::log($request_id, null, null, null, null, 'protocol_error', 'invalid_request', self::durationMs($started), self::ip($server), self::userAgent($headers));

			return $this->jsonRpcResponse(200, $response_headers, self::error(null, -32600, 'Invalid Request'));
		}

		$id = $payload['id'] ?? null;
		$method = (string) ($payload['method'] ?? '');

		if (($payload['jsonrpc'] ?? null) !== '2.0' || $method === '') {
			McpRequestLogger::log($request_id, null, null, null, null, 'protocol_error', 'invalid_request', self::durationMs($started), self::ip($server), self::userAgent($headers));

			return $this->jsonRpcResponse(200, $response_headers, self::error($id, -32600, 'Invalid Request'));
		}

		if (str_starts_with($method, 'notifications/') && array_key_exists('id', $payload)) {
			McpRequestLogger::log($request_id, null, null, self::toolNameFromPayload($payload), self::argumentsFromPayload($payload), 'protocol_error', 'invalid_notification_id', self::durationMs($started), self::ip($server), self::userAgent($headers));

			return $this->jsonRpcResponse(200, $response_headers, self::error(self::isValidRequestId($id) ? $id : null, -32600, 'Notifications must not include an id.'));
		}

		if (!str_starts_with($method, 'notifications/') && !self::hasValidRequestId($payload)) {
			McpRequestLogger::log($request_id, null, null, self::toolNameFromPayload($payload), self::argumentsFromPayload($payload), 'protocol_error', 'invalid_request_id', self::durationMs($started), self::ip($server), self::userAgent($headers));

			return $this->jsonRpcResponse(200, $response_headers, self::error(null, -32600, 'Invalid Request id'));
		}

		$header_error = self::validateTransportHeaders($headers, $payload);

		if ($header_error !== null) {
			McpRequestLogger::log($request_id, null, null, self::toolNameFromPayload($payload), self::argumentsFromPayload($payload), 'protocol_error', $header_error['code'], self::durationMs($started), self::ip($server), self::userAgent($headers));

			return $this->jsonRpcResponse(400, $response_headers, self::error($id, self::HEADER_MISMATCH_ERROR_CODE, $header_error['message']));
		}

		$auth = McpAuthenticator::authenticateBearer($headers);

		if ($auth === null) {
			McpRequestLogger::log($request_id, null, null, self::toolNameFromPayload($payload), self::argumentsFromPayload($payload), 'auth_failed', 'invalid_token', self::durationMs($started), self::ip($server), self::userAgent($headers));

			return [
				'status' => 401,
				'headers' => $response_headers,
				'body' => json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			];
		}

		$user = $auth['user'];
		$token = $auth['token'];
		User::bootstrapTrustedCurrentUser($user);

		if ($method === 'initialize') {
			return $this->jsonRpcResponse(200, $response_headers, self::success($id, [
				'protocolVersion' => '2025-11-25',
				'capabilities' => [
					'tools' => new stdClass(),
				],
				'serverInfo' => [
					'name' => 'radaptor-mcp',
					'version' => '0.1.0',
				],
			]));
		}

		if (str_starts_with($method, 'notifications/')) {
			return [
				'status' => 202,
				'headers' => $response_headers,
				'body' => '',
			];
		}

		try {
			$result = match ($method) {
				'ping' => new stdClass(),
				'tools/list' => [
					'tools' => $this->resolver->listTools(),
				],
				'tools/call' => $this->handleToolCall($payload),
				default => throw new BadMethodCallException("Unknown method: {$method}"),
			};
		} catch (BadMethodCallException $exception) {
			McpRequestLogger::log($request_id, (int) $user['user_id'], (int) $token['mcp_token_id'], null, null, 'protocol_error', 'method_not_found', self::durationMs($started), self::ip($server), self::userAgent($headers));

			return $this->jsonRpcResponse(200, $response_headers, self::error($id, -32601, $exception->getMessage()));
		} catch (InvalidArgumentException $exception) {
			McpRequestLogger::log($request_id, (int) $user['user_id'], (int) $token['mcp_token_id'], self::toolNameFromPayload($payload), self::argumentsFromPayload($payload), 'protocol_error', 'invalid_params', self::durationMs($started), self::ip($server), self::userAgent($headers));

			return $this->jsonRpcResponse(200, $response_headers, self::error($id, -32602, $exception->getMessage()));
		} catch (Throwable $exception) {
			McpRequestLogger::log($request_id, (int) $user['user_id'], (int) $token['mcp_token_id'], self::toolNameFromPayload($payload), self::argumentsFromPayload($payload), 'server_error', 'internal_error', self::durationMs($started), self::ip($server), self::userAgent($headers));

			return $this->jsonRpcResponse(200, $response_headers, self::error($id, -32603, 'Internal error'));
		}

		$is_error = is_array($result) && ($result['isError'] ?? false) === true;
		$error_code = $is_error ? (string) ($result['_mcp_error_code'] ?? 'tool_error') : null;

		if (is_array($result)) {
			unset($result['_mcp_error_code']);
		}

		McpRequestLogger::log(
			$request_id,
			(int) $user['user_id'],
			(int) $token['mcp_token_id'],
			self::toolNameFromPayload($payload),
			self::argumentsFromPayload($payload),
			$is_error ? 'tool_error' : 'success',
			$error_code,
			self::durationMs($started),
			self::ip($server),
			self::userAgent($headers)
		);

		return $this->jsonRpcResponse(200, $response_headers, self::success($id, $result));
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function handleToolCall(array $payload): array
	{
		$params = $payload['params'] ?? null;

		if (!is_array($params)) {
			throw new InvalidArgumentException('Missing tools/call params.');
		}

		$name = (string) ($params['name'] ?? '');
		$arguments = $params['arguments'] ?? [];

		if ($name === '') {
			throw new InvalidArgumentException('Missing tool name.');
		}

		// Associative arrays are expected. Empty list [] is also acceptable as no arguments.
		if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
			throw new InvalidArgumentException('Tool arguments must be an object.');
		}

		return $this->resolver->callTool($name, $arguments);
	}

	/**
	 * @param array<string, mixed> $headers
	 * @param array<string, mixed> $payload
	 * @return array{code: string, message: string}|null
	 */
	private static function validateTransportHeaders(array $headers, array $payload): ?array
	{
		$method = (string) ($payload['method'] ?? '');
		$protocol_version = self::header($headers, 'MCP-Protocol-Version');

		if ($protocol_version !== null && $protocol_version !== self::SUPPORTED_PROTOCOL_VERSION) {
			return [
				'code' => 'unsupported_protocol_version',
				'message' => "Unsupported MCP protocol version: {$protocol_version}",
			];
		}

		if ($method !== 'initialize' && $protocol_version === null) {
			return [
				'code' => 'unsupported_protocol_version',
				'message' => 'Missing MCP-Protocol-Version header. This server requires ' . self::SUPPORTED_PROTOCOL_VERSION . '; missing headers imply ' . self::BACKWARD_COMPATIBILITY_PROTOCOL_VERSION . ', which is not supported.',
			];
		}

		// 2025-11-25 clients do not send these draft transport headers. When a
		// client/proxy does send them, reject mismatches so headers cannot route
		// one operation while the body executes another.
		$mcp_method = self::header($headers, 'Mcp-Method');

		if ($mcp_method !== null && $mcp_method !== '' && $mcp_method !== $method) {
			return [
				'code' => 'mcp_method_mismatch',
				'message' => "Mcp-Method header '{$mcp_method}' does not match JSON-RPC method '{$method}'.",
			];
		}

		$expected_name = self::expectedMcpName($payload);

		if ($expected_name === null) {
			return null;
		}

		$mcp_name = self::header($headers, 'Mcp-Name');

		if ($mcp_name !== null && $mcp_name !== '' && $mcp_name !== $expected_name) {
			return [
				'code' => 'mcp_name_mismatch',
				'message' => "Mcp-Name header '{$mcp_name}' does not match JSON-RPC request name '{$expected_name}'.",
			];
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function hasValidRequestId(array $payload): bool
	{
		if (!array_key_exists('id', $payload)) {
			return false;
		}

		return self::isValidRequestId($payload['id']);
	}

	private static function isValidRequestId(mixed $id): bool
	{
		return is_int($id) || is_string($id);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function expectedMcpName(array $payload): ?string
	{
		$method = (string) ($payload['method'] ?? '');
		$params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

		return match ($method) {
			'tools/call', 'prompts/get' => is_string($params['name'] ?? null) ? (string) $params['name'] : '',
			'resources/read' => is_string($params['uri'] ?? null) ? (string) $params['uri'] : '',
			default => null,
		};
	}

	/**
	 * @param array<string, mixed> $headers
	 */
	private static function header(array $headers, string $name): ?string
	{
		foreach ($headers as $key => $value) {
			if (strtolower((string) $key) === strtolower($name)) {
				$value = is_array($value) ? reset($value) : $value;

				return trim((string) $value);
			}
		}

		return null;
	}

	/**
	 * @param array<string, string> $headers
	 * @param array<string, mixed> $payload
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private function jsonRpcResponse(int $status, array $headers, array $payload): array
	{
		return [
			'status' => $status,
			'headers' => $headers,
			'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function success(mixed $id, mixed $result): array
	{
		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'result' => $result,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function error(mixed $id, int $code, string $message): array
	{
		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'error' => [
				'code' => $code,
				'message' => $message,
			],
		];
	}

	private static function uuid(): string
	{
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}

	private static function durationMs(float $started): int
	{
		return (int) round((microtime(true) - $started) * 1000);
	}

	/**
	 * @param array<string, mixed> $server
	 */
	private static function ip(array $server): ?string
	{
		if (isset($server['REMOTE_ADDR'])) {
			return (string) $server['REMOTE_ADDR'];
		}

		return isset($server['remote_addr']) ? (string) $server['remote_addr'] : null;
	}

	/**
	 * @param array<string, mixed> $headers
	 */
	private static function userAgent(array $headers): ?string
	{
		foreach ($headers as $key => $value) {
			if (strtolower((string) $key) === 'user-agent') {
				return is_array($value) ? (string) reset($value) : (string) $value;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function toolNameFromPayload(array $payload): ?string
	{
		$params = $payload['params'] ?? null;

		return is_array($params) && isset($params['name']) ? (string) $params['name'] : null;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|null
	 */
	private static function argumentsFromPayload(array $payload): ?array
	{
		$params = $payload['params'] ?? null;

		if (!is_array($params) || !is_array($params['arguments'] ?? null)) {
			return null;
		}

		return $params['arguments'];
	}
}
