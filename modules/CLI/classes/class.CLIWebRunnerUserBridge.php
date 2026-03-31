<?php

declare(strict_types=1);

/**
 * Signs the current web user into one CLI subprocess invocation.
 *
 * The payload is carried in environment variables only for the lifetime of the
 * spawned process. It never persists into the shared CLI session storage.
 */
class CLIWebRunnerUserBridge
{
	private const string ENV_USER_ID = 'RADAPTOR_WEB_RUNNER_USER_ID';
	private const string ENV_TIMESTAMP = 'RADAPTOR_WEB_RUNNER_TS';
	private const string ENV_NONCE = 'RADAPTOR_WEB_RUNNER_NONCE';
	private const string ENV_SIGNATURE = 'RADAPTOR_WEB_RUNNER_SIG';
	private const int MAX_TOKEN_AGE_SECONDS = 60;

	/**
	 * Build a signed one-shot environment payload for the current request user.
	 *
	 * @return array<string, string>
	 */
	public static function exportCurrentUserEnvironment(): array
	{
		$current_user = User::getCurrentUser();
		$user_id = (int) ($current_user['user_id'] ?? 0);

		if ($user_id <= 0) {
			return [];
		}

		return self::buildEnvironmentForUserId($user_id);
	}

	/**
	 * Build a signed environment payload for a specific user id.
	 *
	 * @return array<string, string>
	 */
	public static function buildEnvironmentForUserId(int $user_id): array
	{
		$timestamp = time();
		$nonce = bin2hex(random_bytes(16));

		return [
			self::ENV_USER_ID => (string) $user_id,
			self::ENV_TIMESTAMP => (string) $timestamp,
			self::ENV_NONCE => $nonce,
			self::ENV_SIGNATURE => self::signPayload($user_id, $timestamp, $nonce),
		];
	}

	/**
	 * Resolve a trusted user id from the current process environment.
	 */
	public static function resolveTrustedUserIdFromEnvironment(): ?int
	{
		$user_id = self::getEnvironmentInteger(self::ENV_USER_ID);
		$timestamp = self::getEnvironmentInteger(self::ENV_TIMESTAMP);
		$nonce = getenv(self::ENV_NONCE);
		$signature = getenv(self::ENV_SIGNATURE);

		if ($user_id === null || $user_id <= 0 || $timestamp === null) {
			return null;
		}

		if (!is_string($nonce) || preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1) {
			return null;
		}

		if (!is_string($signature) || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
			return null;
		}

		if (abs(time() - $timestamp) > self::MAX_TOKEN_AGE_SECONDS) {
			return null;
		}

		$expected_signature = self::signPayload($user_id, $timestamp, $nonce);

		if (!hash_equals($expected_signature, $signature)) {
			return null;
		}

		return $user_id;
	}

	/**
	 * Resolve the trusted current user record, if present.
	 */
	public static function resolveTrustedCurrentUserFromEnvironment(): ?array
	{
		$user_id = self::resolveTrustedUserIdFromEnvironment();

		if ($user_id === null) {
			return null;
		}

		$user = User::getUserFromId($user_id);

		return isset($user['user_id']) ? $user : null;
	}

	private static function signPayload(int $user_id, int $timestamp, string $nonce): string
	{
		return hash_hmac(
			'sha256',
			self::buildPayload($user_id, $timestamp, $nonce),
			self::getSigningKey()
		);
	}

	private static function buildPayload(int $user_id, int $timestamp, string $nonce): string
	{
		return implode('|', [
			(string) $user_id,
			(string) $timestamp,
			$nonce,
			(string) ApplicationConfig::APP_APPLICATION_IDENTIFIER,
			defined('DEPLOY_ROOT') ? rtrim(DEPLOY_ROOT, '/') : getcwd(),
		]);
	}

	private static function getSigningKey(): string
	{
		$configured = getenv('APP_CLI_RUNNER_SIGNING_SECRET');

		if (is_string($configured) && trim($configured) !== '') {
			return trim($configured);
		}

		return hash(
			'sha256',
			implode('|', [
				(string) Config::DB_DEFAULT_DSN->value(),
				(string) ApplicationConfig::APP_APPLICATION_IDENTIFIER,
				defined('DEPLOY_ROOT') ? rtrim(DEPLOY_ROOT, '/') : getcwd(),
			])
		);
	}

	private static function getEnvironmentInteger(string $name): ?int
	{
		$value = getenv($name);

		if (!is_string($value) || $value === '' || preg_match('/^-?\d+$/', $value) !== 1) {
			return null;
		}

		return (int) $value;
	}
}
