<?php

class Migration_20260508_112000_users_locale_default_from_config
{
	private const string LOCALE_COLUMN = 'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL';

	public function run(): void
	{
		$pdo = Db::instance();

		if (!$this->columnExists($pdo, 'users', 'locale')) {
			return;
		}

		if (class_exists(LocaleAdminService::class)) {
			LocaleAdminService::ensureDefaultLocaleRegistered();
		}

		$default_locale = LocaleService::getDefaultLocale();
		$pdo->exec(
			'ALTER TABLE `users` MODIFY COLUMN `locale` ' . self::LOCALE_COLUMN
			. ' DEFAULT ' . $pdo->quote($default_locale)
			. " COMMENT 'Preferred BCP 47 locale for UI (e.g. en-US, hu-HU)'"
		);
	}

	private function columnExists(PDO $pdo, string $table, string $column): bool
	{
		if (!$this->tableExists($pdo, $table)) {
			return false;
		}

		$stmt = $pdo->prepare(
			"SELECT 1
			FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = ?
				AND COLUMN_NAME = ?"
		);
		$stmt->execute([$table, $column]);

		return (bool) $stmt->fetchColumn();
	}

	private function tableExists(PDO $pdo, string $table): bool
	{
		$stmt = $pdo->prepare(
			"SELECT 1
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = ?"
		);
		$stmt->execute([$table]);

		return (bool) $stmt->fetchColumn();
	}
}
