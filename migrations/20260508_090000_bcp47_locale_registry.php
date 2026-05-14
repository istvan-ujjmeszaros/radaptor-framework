<?php

class Migration_20260508_090000_bcp47_locale_registry
{
	private const string LOCALE_COLUMN = 'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin';

	public function run(): void
	{
		$pdo = Db::instance();
		$default_locale = LocaleService::getDefaultLocale();

		$this->createLocalesTable($pdo);
		$this->mergeCanonicalizationCollisions($pdo, $default_locale);
		$this->assertCanonicalizationIsSafe($pdo, $default_locale);
		$this->widenLocaleColumns($pdo, $default_locale);
		$this->canonicalizeExistingLocaleValues($pdo, $default_locale);
		$this->seedLocales($pdo, $default_locale);
		$this->addForeignKeys($pdo);
	}

	private function createLocalesTable(PDO $pdo): void
	{
		$pdo->exec("CREATE TABLE IF NOT EXISTS `locales` (
			`locale` " . self::LOCALE_COLUMN . " NOT NULL,
			`label` VARCHAR(255) NOT NULL DEFAULT '',
			`native_label` VARCHAR(255) NOT NULL DEFAULT '',
			`is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
			`sort_order` INT NOT NULL DEFAULT 100,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`locale`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	}

	private function canonicalizeExistingLocaleValues(PDO $pdo, string $default_locale): void
	{
		foreach ([
			['users', 'locale'],
			['i18n_translations', 'locale'],
			['i18n_build_state', 'locale'],
			['i18n_tm_entries', 'source_locale'],
			['i18n_tm_entries', 'target_locale'],
		] as [$table, $column]) {
			if (!$this->columnExists($pdo, $table, $column)) {
				continue;
			}

			$rows = $pdo->query("SELECT DISTINCT `{$column}` AS locale FROM `{$table}` WHERE `{$column}` IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);

			foreach ($rows as $row) {
				$raw = (string) ($row['locale'] ?? '');
				$canonical = $this->canonicalizeLegacyLocaleValue($raw, $default_locale);

				if ($canonical === $raw) {
					continue;
				}

				$stmt = $pdo->prepare("UPDATE `{$table}` SET `{$column}` = ? WHERE `{$column}` = ?");
				$stmt->execute([$canonical, $raw]);
			}
		}
	}

	private function assertCanonicalizationIsSafe(PDO $pdo, string $default_locale): void
	{
		$this->assertPrimaryLocaleCanonicalizationIsSafe($pdo, 'i18n_build_state', ['locale'], $default_locale);
		$this->assertPrimaryLocaleCanonicalizationIsSafe($pdo, 'i18n_translations', ['domain', 'key', 'context', 'locale'], $default_locale);
	}

	private function mergeCanonicalizationCollisions(PDO $pdo, string $default_locale): void
	{
		$this->mergeBuildStateLocaleCollisions($pdo, $default_locale);
		$this->mergeTranslationLocaleCollisions($pdo, $default_locale);
	}

	private function mergeBuildStateLocaleCollisions(PDO $pdo, string $default_locale): void
	{
		if (!$this->tableExists($pdo, 'i18n_build_state') || !$this->columnExists($pdo, 'i18n_build_state', 'locale')) {
			return;
		}

		$columns = ['locale'];

		foreach (['catalog_hash', 'built_at'] as $column) {
			if ($this->columnExists($pdo, 'i18n_build_state', $column)) {
				$columns[] = $column;
			}
		}

		$rows = $pdo->query('SELECT `' . implode('`, `', $columns) . '` FROM `i18n_build_state`')->fetchAll(PDO::FETCH_ASSOC);
		$groups = [];

		foreach ($rows as $row) {
			$canonical = $this->canonicalizeLegacyLocaleValue((string) ($row['locale'] ?? ''), $default_locale);
			$groups[$canonical][] = $row;
		}

		foreach ($groups as $canonical => $group) {
			if (count($group) <= 1) {
				continue;
			}

			$winner = $this->chooseBuildStateWinner($group, $canonical);
			$winner['locale'] = $canonical;
			$this->replaceRows(
				$pdo,
				'i18n_build_state',
				['locale'],
				$columns,
				$group,
				$winner
			);
		}
	}

	private function mergeTranslationLocaleCollisions(PDO $pdo, string $default_locale): void
	{
		if (!$this->tableExists($pdo, 'i18n_translations')) {
			return;
		}

		foreach (['domain', 'key', 'context', 'locale'] as $column) {
			if (!$this->columnExists($pdo, 'i18n_translations', $column)) {
				return;
			}
		}

		$columns = ['domain', 'key', 'context', 'locale'];

		foreach (['text', 'human_reviewed', 'allow_source_match', 'source_hash_snapshot'] as $column) {
			if ($this->columnExists($pdo, 'i18n_translations', $column)) {
				$columns[] = $column;
			}
		}

		$rows = $pdo->query('SELECT `' . implode('`, `', $columns) . '` FROM `i18n_translations`')->fetchAll(PDO::FETCH_ASSOC);
		$groups = [];

		foreach ($rows as $row) {
			$canonical = $this->canonicalizeLegacyLocaleValue((string) ($row['locale'] ?? ''), $default_locale);
			$key = implode("\0", [
				(string) ($row['domain'] ?? ''),
				(string) ($row['key'] ?? ''),
				(string) ($row['context'] ?? ''),
				$canonical,
			]);
			$groups[$key][] = $row;
		}

		foreach ($groups as $group) {
			if (count($group) <= 1) {
				continue;
			}

			$canonical = $this->canonicalizeLegacyLocaleValue((string) ($group[0]['locale'] ?? ''), $default_locale);
			$winner = $this->chooseTranslationWinner($group, $canonical);
			$winner['locale'] = $canonical;
			$this->replaceRows(
				$pdo,
				'i18n_translations',
				['domain', 'key', 'context', 'locale'],
				$columns,
				$group,
				$winner
			);
		}
	}

	/**
	 * @param list<array<string, mixed>> $group
	 * @return array<string, mixed>
	 */
	private function chooseBuildStateWinner(array $group, string $canonical): array
	{
		usort($group, static function (array $left, array $right) use ($canonical): int {
			$built_at_compare = strcmp((string) ($right['built_at'] ?? ''), (string) ($left['built_at'] ?? ''));

			if ($built_at_compare !== 0) {
				return $built_at_compare;
			}

			$left_canonical = (string) ($left['locale'] ?? '') === $canonical ? 1 : 0;
			$right_canonical = (string) ($right['locale'] ?? '') === $canonical ? 1 : 0;

			return $right_canonical <=> $left_canonical;
		});

		return $group[0];
	}

	/**
	 * @param list<array<string, mixed>> $group
	 * @return array<string, mixed>
	 */
	private function chooseTranslationWinner(array $group, string $canonical): array
	{
		usort($group, static function (array $left, array $right) use ($canonical): int {
			$left_reviewed = (int) ($left['human_reviewed'] ?? 0);
			$right_reviewed = (int) ($right['human_reviewed'] ?? 0);

			if ($left_reviewed !== $right_reviewed) {
				return $right_reviewed <=> $left_reviewed;
			}

			$left_has_text = trim((string) ($left['text'] ?? '')) !== '' ? 1 : 0;
			$right_has_text = trim((string) ($right['text'] ?? '')) !== '' ? 1 : 0;

			if ($left_has_text !== $right_has_text) {
				return $right_has_text <=> $left_has_text;
			}

			$left_canonical = (string) ($left['locale'] ?? '') === $canonical ? 1 : 0;
			$right_canonical = (string) ($right['locale'] ?? '') === $canonical ? 1 : 0;

			return $right_canonical <=> $left_canonical;
		});

		return $group[0];
	}

	/**
	 * @param list<string> $primary_columns
	 * @param list<string> $columns
	 * @param list<array<string, mixed>> $existing_rows
	 * @param array<string, mixed> $replacement
	 */
	private function replaceRows(PDO $pdo, string $table, array $primary_columns, array $columns, array $existing_rows, array $replacement): void
	{
		$where = implode(' AND ', array_map(
			static fn (string $column): string => "`{$column}` = ?",
			$primary_columns
		));
		$delete = $pdo->prepare("DELETE FROM `{$table}` WHERE {$where}");

		foreach ($existing_rows as $row) {
			$delete->execute(array_map(
				static fn (string $column): mixed => $row[$column] ?? '',
				$primary_columns
			));
		}

		$insert_columns = '`' . implode('`, `', $columns) . '`';
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$insert = $pdo->prepare("INSERT INTO `{$table}` ({$insert_columns}) VALUES ({$placeholders})");
		$insert->execute(array_map(
			static fn (string $column): mixed => $replacement[$column] ?? '',
			$columns
		));
	}

	/**
	 * @param list<string> $primary_columns
	 */
	private function assertPrimaryLocaleCanonicalizationIsSafe(PDO $pdo, string $table, array $primary_columns, string $default_locale): void
	{
		if (!$this->tableExists($pdo, $table)) {
			return;
		}

		foreach ($primary_columns as $column) {
			if (!$this->columnExists($pdo, $table, $column)) {
				return;
			}
		}

		$rows = $pdo->query('SELECT `' . implode('`, `', $primary_columns) . "` FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
		$seen = [];

		foreach ($rows as $row) {
			$key_parts = [];

			foreach ($primary_columns as $column) {
				$value = (string) ($row[$column] ?? '');

				if ($column === 'locale') {
					$value = $this->canonicalizeLegacyLocaleValue($value, $default_locale);
				}

				$key_parts[] = $value;
			}

			$key = implode("\0", $key_parts);

			if (isset($seen[$key])) {
				throw new RuntimeException("Cannot canonicalize {$table}.locale because multiple rows would collapse into the same primary key.");
			}

			$seen[$key] = true;
		}
	}

	private function widenLocaleColumns(PDO $pdo, string $default_locale): void
	{
		$this->modifyColumnIfExists($pdo, 'users', 'locale', self::LOCALE_COLUMN . ' NOT NULL DEFAULT ' . $pdo->quote($default_locale));
		$this->modifyColumnIfExists($pdo, 'i18n_translations', 'locale', self::LOCALE_COLUMN . ' NOT NULL');
		$this->modifyColumnIfExists($pdo, 'i18n_build_state', 'locale', self::LOCALE_COLUMN . ' NOT NULL');
		$this->modifyColumnIfExists($pdo, 'i18n_tm_entries', 'source_locale', self::LOCALE_COLUMN . ' NOT NULL');
		$this->modifyColumnIfExists($pdo, 'i18n_tm_entries', 'target_locale', self::LOCALE_COLUMN . ' NOT NULL');
	}

	private function seedLocales(PDO $pdo, string $default_locale): void
	{
		$locales = [$default_locale => true];

		foreach ([
			['users', 'locale'],
			['i18n_translations', 'locale'],
			['i18n_build_state', 'locale'],
			['i18n_tm_entries', 'source_locale'],
			['i18n_tm_entries', 'target_locale'],
		] as [$table, $column]) {
			if (!$this->columnExists($pdo, $table, $column)) {
				continue;
			}

			$rows = $pdo->query("SELECT DISTINCT `{$column}` AS locale FROM `{$table}` WHERE `{$column}` IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);

			foreach ($rows as $row) {
				$locales[$this->canonicalizeLegacyLocaleValue((string) ($row['locale'] ?? ''), $default_locale)] = true;
			}
		}

		ksort($locales);
		$sort_order = 10;

		foreach (array_keys($locales) as $locale) {
			$stmt = $pdo->prepare(
				"INSERT INTO `locales` (`locale`, `label`, `native_label`, `is_enabled`, `sort_order`)
				VALUES (?, ?, ?, 1, ?)
				ON DUPLICATE KEY UPDATE
					`label` = IF(`label` = '', VALUES(`label`), `label`),
					`native_label` = IF(`native_label` = '', VALUES(`native_label`), `native_label`),
					`is_enabled` = IF(`locale` = ?, 1, `is_enabled`)"
			);
			$stmt->execute([
				$locale,
				$this->getDisplayLabel($locale),
				$this->getNativeName($locale),
				$sort_order,
				$default_locale,
			]);
			$sort_order += 10;
		}
	}

	private function canonicalizeLegacyLocaleValue(string $value, string $default_locale): string
	{
		$canonical = LocaleService::tryCanonicalize($value);

		if ($canonical === null || $canonical === 'und') {
			return $default_locale;
		}

		return $canonical;
	}

	private function addForeignKeys(PDO $pdo): void
	{
		$this->addForeignKeyIfMissing($pdo, 'users', 'fk_users_locale', 'locale');
		$this->addForeignKeyIfMissing($pdo, 'i18n_translations', 'fk_i18n_translations_locale', 'locale');
		$this->addForeignKeyIfMissing($pdo, 'i18n_build_state', 'fk_i18n_build_state_locale', 'locale');
		$this->addForeignKeyIfMissing($pdo, 'i18n_tm_entries', 'fk_i18n_tm_entries_source_locale', 'source_locale');
		$this->addForeignKeyIfMissing($pdo, 'i18n_tm_entries', 'fk_i18n_tm_entries_target_locale', 'target_locale');
	}

	private function addForeignKeyIfMissing(PDO $pdo, string $table, string $constraint, string $column): void
	{
		if (!$this->columnExists($pdo, $table, $column) || $this->foreignKeyExists($pdo, $table, $constraint)) {
			return;
		}

		$pdo->exec("ALTER TABLE `{$table}`
			ADD CONSTRAINT `{$constraint}`
			FOREIGN KEY (`{$column}`) REFERENCES `locales` (`locale`)
			ON UPDATE CASCADE");
	}

	private function modifyColumnIfExists(PDO $pdo, string $table, string $column, string $definition): void
	{
		if ($this->columnExists($pdo, $table, $column)) {
			$pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}");
		}
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
