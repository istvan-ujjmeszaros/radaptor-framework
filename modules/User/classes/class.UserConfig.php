<?php

class UserConfig extends UserBase
{
	/**
	 * Resolve the active user-config storage.
	 *
	 * Canonical storage is `config_user`. `userconfig` is legacy fallback only.
	 *
	 * @return array{table: string, key_column: string}|null
	 */
	private static function _getConfigStorage(): ?array
	{
		$cache = &RequestContextHolder::current()->inMemoryCache;
		$cache_key = __CLASS__ . '::configStorage';

		if (array_key_exists($cache_key, $cache)) {
			/** @var array{table: string, key_column: string}|null $storage */
			$storage = $cache[$cache_key];

			return $storage;
		}

		$pdo = Db::instance();

		try {
			if ($pdo->query("SHOW TABLES LIKE 'config_user'")->rowCount() > 0) {
				$cache[$cache_key] = [
					'table' => 'config_user',
					'key_column' => 'config_key',
				];

				return $cache[$cache_key];
			}

			if ($pdo->query("SHOW TABLES LIKE 'userconfig'")->rowCount() > 0) {
				$key_column = 'config_key';

				if ($pdo->query("SHOW COLUMNS FROM `userconfig` LIKE 'config_key'")->rowCount() === 0) {
					$key_column = 'setting_key';
				}

				$cache[$cache_key] = [
					'table' => 'userconfig',
					'key_column' => $key_column,
				];

				return $cache[$cache_key];
			}
		} catch (Exception) {
			$cache[$cache_key] = null;

			return null;
		}

		$cache[$cache_key] = null;

		return null;
	}

	private static function _initConfigData(int $user_id, bool $forced_reload = false): void
	{
		$cache = &RequestContextHolder::current()->userConfigCache;

		if (!$user_id || (isset($cache[$user_id]) && !$forced_reload)) {
			return;
		}

		unset($cache[$user_id]);
		$cache[$user_id] = [];

		$storage = self::_getConfigStorage();

		if ($storage === null) {
			return;
		}

		$result = DbHelper::selectMany($storage['table'], ['user_id' => $user_id]);

		foreach ($result as $row) {
			$key = (string) ($row[$storage['key_column']] ?? '');

			if ($key === '') {
				continue;
			}

			$cache[$user_id][$key] = (string) ($row['value'] ?? '');
		}
	}

	public static function getConfig(string $key, $user_id = null): ?string
	{
		$user_id ??= User::getCurrentUserId();

		self::_initConfigData($user_id);

		return RequestContextHolder::current()->userConfigCache[$user_id][$key] ?? null;
	}

	public static function setConfig(string $key, string $value, $user_id = null): int|null
	{
		$user_id ??= User::getCurrentUserId();
		$storage = self::_getConfigStorage();

		if ($storage === null) {
			SystemMessages::_error('No user config storage table found. Expected config_user or legacy userconfig.');

			return null;
		}

		$savedata = [
			'user_id' => $user_id,
			$storage['key_column'] => $key,
			'value' => $value,
		];

		$result = DbHelper::insertOrUpdateHelper($storage['table'], $savedata);

		self::_initConfigData($user_id, true);

		return $result;
	}

	public static function listConfigs(int $user_id): array
	{
		self::_initConfigData($user_id, true);

		return RequestContextHolder::current()->userConfigCache[$user_id] ?? [];
	}

	public static function removeConfig(string $key, $user_id = null): bool
	{
		$user_id ??= User::getCurrentUserId();
		$storage = self::_getConfigStorage();

		if ($storage === null) {
			return false;
		}

		$stmt = Db::instance()->prepare(
			"DELETE FROM `{$storage['table']}` WHERE `user_id` = ? AND `{$storage['key_column']}` = ? LIMIT 1"
		);
		$stmt->execute([(int) $user_id, $key]);

		self::_initConfigData((int) $user_id, true);

		return $stmt->rowCount() > 0;
	}
}
