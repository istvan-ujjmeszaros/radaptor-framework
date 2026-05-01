<?php

declare(strict_types=1);

class McpAuthenticator
{
	/**
	 * @param array<string, mixed> $headers
	 * @return array{token: array<string, mixed>, user: array<string, mixed>}|null
	 */
	public static function authenticateBearer(array $headers): ?array
	{
		$authorization = McpHttpHeaders::firstScalar($headers, 'authorization');

		if ($authorization === null || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
			return null;
		}

		return McpTokenService::authenticate(trim($matches[1]));
	}

	/**
	 * @param array<string, mixed> $headers
	 */
	public static function validateOrigin(array $headers): bool
	{
		$origin = McpHttpHeaders::firstScalar($headers, 'origin');

		if ($origin === null || $origin === '') {
			return true;
		}

		$origin = rtrim($origin, '/');
		$allowed = self::allowedOrigins();

		return in_array($origin, $allowed, true);
	}

	/**
	 * @return list<string>
	 */
	private static function allowedOrigins(): array
	{
		$configured = getenv('APP_MCP_ALLOWED_ORIGINS');

		if (is_string($configured) && trim($configured) !== '') {
			return array_values(array_filter(array_map(
				static fn (string $origin): string => rtrim(trim($origin), '/'),
				explode(',', $configured)
			)));
		}

		$port = getenv('APP_MCP_PORT') ?: '9512';

		return [
			'http://127.0.0.1',
			'https://127.0.0.1',
			'http://[::1]',
			'https://[::1]',
			'http://localhost',
			'https://localhost',
			"http://127.0.0.1:{$port}",
			"https://127.0.0.1:{$port}",
			"http://[::1]:{$port}",
			"https://[::1]:{$port}",
			"http://localhost:{$port}",
			"https://localhost:{$port}",
		];
	}
}
