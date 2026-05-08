<?php

class Migration_20260508_100000_resource_locale_home
{
	private const string LOCALE_COLUMN = 'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin';

	public function run(): void
	{
		$pdo = Db::instance();

		if ($pdo->query("SHOW COLUMNS FROM `resource_tree` LIKE 'locale'")->rowCount() === 0) {
			$pdo->exec("ALTER TABLE `resource_tree`
				ADD COLUMN `locale` " . self::LOCALE_COLUMN . " NULL AFTER `node_type`");
		} else {
			$pdo->exec("ALTER TABLE `resource_tree`
				MODIFY COLUMN `locale` " . self::LOCALE_COLUMN . " NULL");
		}

		$this->canonicalizeResourceLocales($pdo);
		$this->seedResourceLocales($pdo);

		$pdo->exec("CREATE TABLE IF NOT EXISTS `locale_home_resources` (
			`site_context` VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
			`locale` " . self::LOCALE_COLUMN . " NOT NULL,
			`computed_resource_id` INT(10) UNSIGNED NULL,
			`manual_resource_id` INT(10) UNSIGNED NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`site_context`, `locale`),
			INDEX `idx_locale_home_computed_resource` (`computed_resource_id`),
			INDEX `idx_locale_home_manual_resource` (`manual_resource_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$pdo->exec("ALTER TABLE `locale_home_resources`
			MODIFY COLUMN `computed_resource_id` INT(10) UNSIGNED NULL,
			MODIFY COLUMN `manual_resource_id` INT(10) UNSIGNED NULL");

		$this->addForeignKeyIfMissing($pdo, 'resource_tree', 'fk_resource_tree_locale', 'locale', 'locales', 'locale', '');
		$this->addForeignKeyIfMissing($pdo, 'locale_home_resources', 'fk_locale_home_resources_locale', 'locale', 'locales', 'locale', 'ON UPDATE CASCADE');
		$this->addForeignKeyIfMissing($pdo, 'locale_home_resources', 'fk_locale_home_resources_computed_resource', 'computed_resource_id', 'resource_tree', 'node_id', 'ON DELETE SET NULL');
		$this->addForeignKeyIfMissing($pdo, 'locale_home_resources', 'fk_locale_home_resources_manual_resource', 'manual_resource_id', 'resource_tree', 'node_id', 'ON DELETE SET NULL');

		if (class_exists(LocaleHomeResourceService::class)) {
			LocaleHomeResourceService::refreshAll();
		}
	}

	private function addForeignKeyIfMissing(
		PDO $pdo,
		string $table,
		string $constraint,
		string $column,
		string $targetTable,
		string $targetColumn,
		string $action
	): void {
		if (
			!$this->tableExists($pdo, $table)
			|| !$this->tableExists($pdo, $targetTable)
			|| !$this->columnExists($pdo, $table, $column)
			|| !$this->columnExists($pdo, $targetTable, $targetColumn)
		) {
			return;
		}

		$stmt = $pdo->prepare(
			"SELECT 1
			FROM information_schema.TABLE_CONSTRAINTS
			WHERE CONSTRAINT_SCHEMA = DATABASE()
				AND TABLE_NAME = ?
				AND CONSTRAINT_NAME = ?
				AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
		);
		$stmt->execute([$table, $constraint]);

		if ($stmt->fetchColumn()) {
			return;
		}

		$pdo->exec("ALTER TABLE `{$table}`
			ADD CONSTRAINT `{$constraint}`
			FOREIGN KEY (`{$column}`) REFERENCES `{$targetTable}` (`{$targetColumn}`)
			{$action}");
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

	private function columnExists(PDO $pdo, string $table, string $column): bool
	{
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

	private function canonicalizeResourceLocales(PDO $pdo): void
	{
		$rows = $pdo->query("SELECT DISTINCT `locale` FROM `resource_tree` WHERE `locale` IS NOT NULL AND `locale` <> ''")->fetchAll(PDO::FETCH_ASSOC);

		foreach ($rows as $row) {
			$raw = (string) ($row['locale'] ?? '');
			$canonical = LocaleService::tryCanonicalize($raw);

			if ($canonical === null) {
				throw new RuntimeException("resource_tree.locale contains unsupported locale '{$raw}'. Canonicalize or clear it before rerunning migrations.");
			}

			if ($canonical !== $raw) {
				$stmt = $pdo->prepare('UPDATE `resource_tree` SET `locale` = ? WHERE `locale` = ?');
				$stmt->execute([$canonical, $raw]);
			}
		}
	}

	private function seedResourceLocales(PDO $pdo): void
	{
		if (!$this->tableExists($pdo, 'locales')) {
			return;
		}

		$rows = $pdo->query("SELECT DISTINCT `locale` FROM `resource_tree` WHERE `locale` IS NOT NULL AND `locale` <> ''")->fetchAll(PDO::FETCH_ASSOC);

		foreach ($rows as $row) {
			$locale = LocaleService::tryCanonicalize((string) ($row['locale'] ?? ''));

			if ($locale === null) {
				continue;
			}

			$stmt = $pdo->prepare(
				"INSERT INTO `locales` (`locale`, `label`, `native_label`, `is_enabled`, `sort_order`)
				VALUES (?, ?, ?, 0, 1000)
				ON DUPLICATE KEY UPDATE `locale` = VALUES(`locale`)"
			);
			$stmt->execute([
				$locale,
				$this->getDisplayLabel($locale),
				$this->getNativeName($locale),
			]);
		}
	}

	private function getDisplayLabel(string $locale): string
	{
		try {
			return LocaleRegistry::getDisplayLabel($locale);
		} catch (Throwable) {
			return $locale;
		}
	}

	private function getNativeName(string $locale): string
	{
		try {
			return LocaleRegistry::getNativeName($locale);
		} catch (Throwable) {
			return $locale;
		}
	}
}
