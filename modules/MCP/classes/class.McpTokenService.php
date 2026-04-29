<?php

declare(strict_types=1);

class McpTokenService
{
	public const int DEFAULT_EXPIRY_DAYS = 90;

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function listTokensForUser(int $user_id): array
	{
		$rows = DbHelper::selectManyFromQuery(
			"SELECT
				mcp_token_id,
				user_id,
				name,
				prefix,
				expires_at,
				revoked_at,
				last_used_at,
				created_at
			FROM mcp_tokens
			WHERE user_id = ?
			ORDER BY IF(revoked_at IS NULL, 0, 1), created_at DESC, mcp_token_id DESC",
			[$user_id]
		);

		$return = [];
		$now = time();

		foreach ($rows as $row) {
			$expires_at = (string) ($row['expires_at'] ?? '');
			$revoked_at = (string) ($row['revoked_at'] ?? '');
			$is_expired = $expires_at !== '' && strtotime($expires_at) < $now;
			$is_revoked = $revoked_at !== '';

			$row['display_token'] = 'mcp_' . (string) ($row['prefix'] ?? '') . '_...';
			$row['is_revoked'] = $is_revoked;
			$row['is_expired'] = $is_expired;
			$row['status'] = match (true) {
				$is_revoked => 'revoked',
				$is_expired => 'expired',
				default => 'active',
			};

			$return[] = $row;
		}

		return $return;
	}

	/**
	 * @return array{token: string, prefix: string, token_id: int, expires_at: ?string}
	 */
	public static function createToken(int $user_id, string $name, ?int $expires_in_days = null, ?int $created_by_user_id = null): array
	{
		$user = User::getUserFromId($user_id);

		if (!isset($user['user_id'])) {
			throw new InvalidArgumentException("User not found: {$user_id}");
		}

		if (array_key_exists('is_active', $user) && (int) $user['is_active'] !== 1) {
			throw new InvalidArgumentException("User is inactive: {$user_id}");
		}

		$name = trim($name) !== '' ? trim($name) : 'MCP token';
		$name = mb_substr($name, 0, 190);
		$expires_in_days ??= self::DEFAULT_EXPIRY_DAYS;
		$expires_at = $expires_in_days > 0
			? gmdate('Y-m-d H:i:s', time() + ($expires_in_days * 86400))
			: null;

		for ($attempt = 0; $attempt < 5; ++$attempt) {
			$prefix = self::base64Url(random_bytes(6));
			$secret = self::base64Url(random_bytes(32));
			$token = "mcp_{$prefix}_{$secret}";
			$hash = hash('sha256', $token);

			try {
				$stmt = Db::instance()->prepare(
					"INSERT INTO mcp_tokens
						(user_id, name, prefix, token_hash, expires_at, created_by_user_id)
					VALUES (?, ?, ?, ?, ?, ?)"
				);
				$stmt->execute([
					$user_id,
					$name,
					$prefix,
					$hash,
					$expires_at,
					$created_by_user_id,
				]);

				return [
					'token' => $token,
					'prefix' => $prefix,
					'token_id' => (int) Db::instance()->lastInsertId(),
					'expires_at' => $expires_at,
				];
			} catch (PDOException $exception) {
				if (!str_contains($exception->getMessage(), 'uniq_mcp_tokens_prefix')) {
					throw $exception;
				}
			}
		}

		throw new RuntimeException('Unable to create a unique MCP token prefix.');
	}

	public static function revokeTokenForUser(int $user_id, int $token_id): bool
	{
		$row = DbHelper::fetch(
			"SELECT mcp_token_id, revoked_at FROM mcp_tokens WHERE mcp_token_id = ? AND user_id = ? LIMIT 1",
			[$token_id, $user_id]
		);

		if (!is_array($row)) {
			return false;
		}

		if (!empty($row['revoked_at'])) {
			return true;
		}

		$stmt = Db::instance()->prepare(
			"UPDATE mcp_tokens
			SET revoked_at = NOW()
			WHERE mcp_token_id = ?
				AND user_id = ?
				AND revoked_at IS NULL"
		);
		$stmt->execute([$token_id, $user_id]);

		return $stmt->rowCount() > 0;
	}

	/**
	 * @return array{token: array<string, mixed>, user: array<string, mixed>}|null
	 */
	public static function authenticate(string $token): ?array
	{
		$prefix = self::extractPrefix($token);

		if ($prefix === null) {
			return null;
		}

		$row = DbHelper::fetch(
			"SELECT * FROM mcp_tokens WHERE prefix = ? LIMIT 1",
			[$prefix]
		);

		if (!is_array($row)) {
			return null;
		}

		if (!hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
			return null;
		}

		if (!empty($row['revoked_at'])) {
			return null;
		}

		if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
			return null;
		}

		$user = User::getUserFromId((int) $row['user_id']);

		if (!isset($user['user_id'])) {
			return null;
		}

		if (array_key_exists('is_active', $user) && (int) $user['is_active'] !== 1) {
			return null;
		}

		DbHelper::prexecute(
			"UPDATE mcp_tokens SET last_used_at = NOW() WHERE mcp_token_id = ?",
			[(int) $row['mcp_token_id']]
		);

		return [
			'token' => $row,
			'user' => $user,
		];
	}

	public static function extractPrefix(string $token): ?string
	{
		if (preg_match('/^mcp_([A-Za-z0-9_-]{8})_[A-Za-z0-9_-]{43}$/', $token, $matches) !== 1) {
			return null;
		}

		return $matches[1];
	}

	private static function base64Url(string $bytes): string
	{
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}
}
