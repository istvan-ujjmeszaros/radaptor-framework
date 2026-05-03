<?php

declare(strict_types=1);

final class RuntimeDiagnosticsRedactor
{
	public const string MASK = '[redacted]';

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function redactArray(array $data): array
	{
		$redacted = [];

		foreach ($data as $key => $value) {
			$redacted[$key] = self::redactValue((string) $key, $value);
		}

		return $redacted;
	}

	public static function redactValue(string $key, mixed $value): mixed
	{
		if (self::isDsnKey($key) && is_string($value)) {
			return self::redactDsn($value);
		}

		if (self::isSecretKey($key)) {
			return self::MASK;
		}

		if (is_array($value)) {
			$redacted = [];

			foreach ($value as $child_key => $child_value) {
				$redacted[$child_key] = self::redactValue((string) $child_key, $child_value);
			}

			return $redacted;
		}

		return $value;
	}

	public static function isSecretKey(string $key): bool
	{
		$normalized = self::normalizeKey($key);

		return preg_match('/(^|_)(password|token|secret|api_key|dsn)$/', $normalized) === 1;
	}

	public static function isDsnKey(string $key): bool
	{
		return preg_match('/(^|_)dsn$/', self::normalizeKey($key)) === 1;
	}

	public static function redactDsn(string $dsn): string
	{
		$dsn = trim($dsn);

		if ($dsn === '') {
			return '';
		}

		if (str_contains($dsn, '://')) {
			return self::redactUrlDsn($dsn);
		}

		return self::redactPdoDsn($dsn);
	}

	/**
	 * @return array{
	 *     driver: string|null,
	 *     host: string|null,
	 *     port: int|null,
	 *     database: string|null,
	 *     username: string|null,
	 *     password: string|null,
	 *     redacted_dsn: string
	 * }
	 */
	public static function parseDsn(string $dsn): array
	{
		$dsn = trim($dsn);
		$parsed = str_contains($dsn, '://')
			? self::parseUrlDsn($dsn)
			: self::parsePdoDsn($dsn);

		$parsed['redacted_dsn'] = self::redactDsn($dsn);

		return $parsed;
	}

	private static function redactPdoDsn(string $dsn): string
	{
		$colon_position = strpos($dsn, ':');

		if ($colon_position === false) {
			return self::MASK;
		}

		$driver = substr($dsn, 0, $colon_position);
		$body = substr($dsn, $colon_position + 1);
		$parts = explode(';', $body);
		$redacted_parts = [];

		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}

			$key_value = explode('=', $part, 2);

			if (count($key_value) !== 2) {
				$redacted_parts[] = $part;

				continue;
			}

			$key = trim($key_value[0]);
			$value = $key_value[1];
			$redacted_parts[] = strtolower($key) === 'password'
				? $key . '=' . self::MASK
				: $key . '=' . $value;
		}

		return $driver . ':' . implode(';', $redacted_parts);
	}

	private static function redactUrlDsn(string $dsn): string
	{
		$parts = parse_url($dsn);

		if (!is_array($parts) || !isset($parts['scheme'])) {
			return self::MASK;
		}

		$user_info = '';

		if (isset($parts['user'])) {
			$user_info = rawurlencode((string) $parts['user']);

			if (isset($parts['pass'])) {
				$user_info .= ':' . self::MASK;
			}

			$user_info .= '@';
		}

		$host = (string) ($parts['host'] ?? '');
		$port = isset($parts['port']) ? ':' . (string) $parts['port'] : '';
		$path = (string) ($parts['path'] ?? '');
		$query = isset($parts['query']) ? '?' . self::redactQueryString((string) $parts['query']) : '';
		$fragment = isset($parts['fragment']) ? '#' . (string) $parts['fragment'] : '';

		return (string) $parts['scheme'] . '://' . $user_info . $host . $port . $path . $query . $fragment;
	}

	private static function redactQueryString(string $query): string
	{
		parse_str($query, $params);
		$params = self::redactArray($params);

		return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
	}

	private static function normalizeKey(string $key): string
	{
		return str_replace('-', '_', strtolower(trim($key)));
	}

	/**
	 * @return array{
	 *     driver: string|null,
	 *     host: string|null,
	 *     port: int|null,
	 *     database: string|null,
	 *     username: string|null,
	 *     password: string|null
	 * }
	 */
	private static function parsePdoDsn(string $dsn): array
	{
		$result = self::emptyParsedDsn();
		$colon_position = strpos($dsn, ':');

		if ($colon_position === false) {
			return $result;
		}

		$result['driver'] = substr($dsn, 0, $colon_position) ?: null;
		$body = substr($dsn, $colon_position + 1);

		foreach (explode(';', $body) as $part) {
			$key_value = explode('=', $part, 2);

			if (count($key_value) !== 2) {
				continue;
			}

			$key = strtolower(trim($key_value[0]));
			$value = trim($key_value[1]);

			match ($key) {
				'host' => $result['host'] = $value !== '' ? $value : null,
				'port' => $result['port'] = is_numeric($value) ? (int) $value : null,
				'dbname', 'database' => $result['database'] = $value !== '' ? $value : null,
				'user', 'username' => $result['username'] = $value !== '' ? $value : null,
				'password' => $result['password'] = $value !== '' ? self::MASK : null,
				default => null,
			};
		}

		return $result;
	}

	/**
	 * @return array{
	 *     driver: string|null,
	 *     host: string|null,
	 *     port: int|null,
	 *     database: string|null,
	 *     username: string|null,
	 *     password: string|null
	 * }
	 */
	private static function parseUrlDsn(string $dsn): array
	{
		$result = self::emptyParsedDsn();
		$parts = parse_url($dsn);

		if (!is_array($parts)) {
			return $result;
		}

		$result['driver'] = isset($parts['scheme']) ? (string) $parts['scheme'] : null;
		$result['host'] = isset($parts['host']) ? (string) $parts['host'] : null;
		$result['port'] = isset($parts['port']) ? (int) $parts['port'] : null;
		$result['username'] = isset($parts['user']) ? (string) $parts['user'] : null;
		$result['password'] = isset($parts['pass']) ? self::MASK : null;
		$path = trim((string) ($parts['path'] ?? ''), '/');
		$result['database'] = $path !== '' ? $path : null;

		return $result;
	}

	/**
	 * @return array{
	 *     driver: string|null,
	 *     host: string|null,
	 *     port: int|null,
	 *     database: string|null,
	 *     username: string|null,
	 *     password: string|null
	 * }
	 */
	private static function emptyParsedDsn(): array
	{
		return [
			'driver' => null,
			'host' => null,
			'port' => null,
			'database' => null,
			'username' => null,
			'password' => null,
		];
	}
}
