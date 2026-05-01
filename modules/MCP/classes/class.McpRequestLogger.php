<?php

declare(strict_types=1);

class McpRequestLogger
{
	/**
	 * @param array<string, mixed>|null $args
	 */
	public static function log(
		string $request_id,
		?int $user_id,
		?int $token_id,
		?string $tool_name,
		?array $args,
		string $result_status,
		?string $error_code,
		int $duration_ms,
		?string $ip_address,
		?string $user_agent
	): void {
		try {
			$redacted = $args === null ? null : self::redact($args);
			$args_json = $redacted === null
				? null
				: json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			$args_hash = $args === null
				? null
				: hash('sha256', json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

			$stmt = Db::instance()->prepare(
				"INSERT INTO mcp_audit
					(request_id, user_id, token_id, tool_name, args_hash, args_redacted_json, result_status, error_code, duration_ms, ip_address, user_agent)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
			);
			$stmt->execute([
				$request_id,
				$user_id,
				$token_id,
				$tool_name,
				$args_hash,
				$args_json,
				$result_status,
				$error_code,
				$duration_ms,
				$ip_address,
				$user_agent,
			]);
		} catch (Throwable $exception) {
			error_log('MCP audit failure: ' . $exception->getMessage());
			// MCP logging must never break tool execution.
		}
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	public static function redact(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}

		$return = [];

		foreach ($value as $key => $item) {
			$key_string = is_string($key) ? $key : (string) $key;
			$return[$key] = self::isSensitiveKey($key_string)
				? '[REDACTED]'
				: self::redact($item);
		}

		return $return;
	}

	private static function isSensitiveKey(string $key): bool
	{
		return preg_match('/(^password$|^password_|_password$|^token$|^token_|_token$|^secret$|^secret_|_secret$|^api_key$|^api_key_|_api_key$)/i', $key) === 1;
	}
}
