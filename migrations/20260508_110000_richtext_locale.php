<?php

class Migration_20260508_110000_richtext_locale
{
	private const string LOCALE_COLUMN = 'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin';

	public function run(): void
	{
		$pdo = Db::instance();

		if (!$this->tableExists($pdo, 'richtext')) {
			return;
		}

		$this->ensureLocaleColumn($pdo);
		$this->canonicalizeLocaleValues($pdo);
		$this->seedRichTextLocales($pdo);
		$this->backfillMissingLocale($pdo);
		$this->assertNoDuplicateNames($pdo);
		$this->dropLegacyNameUniqueKeyIfPresent($pdo);

		$pdo->exec('ALTER TABLE `richtext` MODIFY COLUMN `locale` ' . self::LOCALE_COLUMN . ' NOT NULL');
		$this->addUniqueKeyIfMissing($pdo);
		$this->addForeignKeyIfMissing($pdo);
	}

	private function ensureLocaleColumn(PDO $pdo): void
	{
		if ($this->columnExists($pdo, 'richtext', 'locale')) {
			$pdo->exec('ALTER TABLE `richtext` MODIFY COLUMN `locale` ' . self::LOCALE_COLUMN . ' NULL');

			return;
		}

		$pdo->exec('ALTER TABLE `richtext` ADD COLUMN `locale` ' . self::LOCALE_COLUMN . ' NULL AFTER `content_type`');
	}

	private function canonicalizeLocaleValues(PDO $pdo): void
	{
		$rows = $pdo->query("SELECT DISTINCT `locale` FROM `richtext` WHERE `locale` IS NOT NULL AND `locale` <> ''")->fetchAll(PDO::FETCH_ASSOC);

		foreach ($rows as $row) {
			$raw = (string) ($row['locale'] ?? '');
			$canonical = LocaleService::tryCanonicalize($raw);

			if ($canonical === null) {
				throw new RuntimeException("richtext.locale contains unsupported locale '{$raw}'. Canonicalize or clear it before rerunning migrations.");
			}

			if ($canonical === $raw) {
				continue;
			}

			$stmt = $pdo->prepare('UPDATE `richtext` SET `locale` = ? WHERE `locale` = ?');
			$stmt->execute([$canonical, $raw]);
		}
	}

	private function seedRichTextLocales(PDO $pdo): void
	{
		if (!$this->tableExists($pdo, 'locales')) {
			return;
		}

		$rows = $pdo->query("SELECT DISTINCT `locale` FROM `richtext` WHERE `locale` IS NOT NULL AND `locale` <> ''")->fetchAll(PDO::FETCH_ASSOC);

		foreach ($rows as $row) {
			$locale = LocaleService::tryCanonicalize((string) ($row['locale'] ?? ''));

			if ($locale !== null) {
				$this->ensureLocaleRow($pdo, $locale);
			}
		}
	}

	private function backfillMissingLocale(PDO $pdo): void
	{
		$missing = (int) $pdo->query("SELECT COUNT(*) FROM `richtext` WHERE `locale` IS NULL OR `locale` = ''")->fetchColumn();

		if ($missing === 0) {
			return;
		}

		$enabled = $this->getEnabledLocales($pdo);

		if (count($enabled) !== 1) {
			throw new RuntimeException('RichText locale migration cannot infer locale while multiple locales are enabled. Assign richtext.locale explicitly before rerunning migrations.');
		}

		$locale = $enabled[0];
		$this->ensureLocaleRow($pdo, $locale);

		$stmt = $pdo->prepare("UPDATE `richtext` SET `locale` = ? WHERE `locale` IS NULL OR `locale` = ''");
		$stmt->execute([$locale]);
	}

	private function assertNoDuplicateNames(PDO $pdo): void
	{
		$row = $pdo->query(
			"SELECT `locale`, `name`, COUNT(*) AS count_rows
			FROM `richtext`
			WHERE `name` IS NOT NULL AND `name` <> ''
			GROUP BY `locale`, `name`
			HAVING COUNT(*) > 1
			LIMIT 1"
		)->fetch(PDO::FETCH_ASSOC);

		if (is_array($row)) {
			throw new RuntimeException("RichText locale migration found duplicate name '{$row['name']}' for locale '{$row['locale']}'. Resolve duplicates before rerunning migrations.");
		}
	}

	/**
	 * @return list<string>
	 */
	private function getEnabledLocales(PDO $pdo): array
	{
		if (!$this->tableExists($pdo, 'locales')) {
			return [LocaleService::getDefaultLocale()];
		}

		$rows = $pdo->query("SELECT `locale` FROM `locales` WHERE `is_enabled` = 1 ORDER BY `sort_order`, `locale`")->fetchAll(PDO::FETCH_ASSOC);
		$locales = [];

		foreach ($rows as $row) {
			$locale = LocaleService::tryCanonicalize((string) ($row['locale'] ?? ''));

			if ($locale !== null) {
				$locales[$locale] = true;
			}
		}

		return array_keys($locales);
	}

	private function ensureLocaleRow(PDO $pdo, string $locale): void
	{
		if (!$this->tableExists($pdo, 'locales')) {
			return;
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

	private function addUniqueKeyIfMissing(PDO $pdo): void
	{
		if ($this->indexExists($pdo, 'richtext', 'uq_richtext_locale_name')) {
			return;
		}

		$pdo->exec('ALTER TABLE `richtext` ADD UNIQUE KEY `uq_richtext_locale_name` (`locale`, `name`)');
	}

	private function dropLegacyNameUniqueKeyIfPresent(PDO $pdo): void
	{
		foreach ($this->getSingleColumnUniqueIndexes($pdo, 'richtext', 'name') as $index) {
			$pdo->exec('ALTER TABLE `richtext` DROP INDEX `' . str_replace('`', '``', $index) . '`');
		}
	}

	private function addForeignKeyIfMissing(PDO $pdo): void
	{
		if (
			!$this->tableExists($pdo, 'locales')
			|| $this->foreignKeyExists($pdo, 'richtext', 'fk_richtext_locale')
		) {
			return;
		}

		$pdo->exec("ALTER TABLE `richtext`
			ADD CONSTRAINT `fk_richtext_locale`
			FOREIGN KEY (`locale`) REFERENCES `locales` (`locale`)
			ON UPDATE CASCADE");
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

	private function indexExists(PDO $pdo, string $table, string $index): bool
	{
		$stmt = $pdo->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = ?");
		$stmt->execute([$index]);

		return $stmt->rowCount() > 0;
	}

	/**
	 * @return list<string>
	 */
	private function getSingleColumnUniqueIndexes(PDO $pdo, string $table, string $column): array
	{
		$stmt = $pdo->prepare(
			"SELECT `INDEX_NAME`
			FROM information_schema.STATISTICS
			WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = ?
				AND NON_UNIQUE = 0
				AND INDEX_NAME <> 'PRIMARY'
			GROUP BY `INDEX_NAME`
			HAVING COUNT(*) = 1
				AND MAX(`COLUMN_NAME` = ?) = 1"
		);
		$stmt->execute([$table, $column]);

		return array_map(
			static fn (array $row): string => (string) ($row['INDEX_NAME'] ?? ''),
			$stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
		);
	}

	private function foreignKeyExists(PDO $pdo, string $table, string $constraint): bool
	{
		$stmt = $pdo->prepare(
			"SELECT 1
			FROM information_schema.TABLE_CONSTRAINTS
			WHERE CONSTRAINT_SCHEMA = DATABASE()
				AND TABLE_NAME = ?
				AND CONSTRAINT_NAME = ?
				AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
		);
		$stmt->execute([$table, $constraint]);

		return (bool) $stmt->fetchColumn();
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
