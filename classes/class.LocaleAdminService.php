<?php

declare(strict_types=1);

final class LocaleAdminService
{
	/**
	 * @return list<array<string, mixed>>
	 */
	public static function listLocales(): array
	{
		if (!self::tableExists('locales')) {
			$default_locale = self::getDefaultLocaleSafely();

			return [[
				'locale' => $default_locale,
				'label' => self::getDisplayLabel($default_locale),
				'native_label' => self::getNativeName($default_locale),
				'is_enabled' => true,
				'is_default' => true,
				'sort_order' => 10,
				'usage' => [],
				'effective_home_count' => 0,
			]];
		}

		self::ensureDefaultLocaleRegistered();

		$rows = Db::instance()->query(
			"SELECT `locale`, `label`, `native_label`, `is_enabled`, `sort_order`
			FROM `locales`
			ORDER BY `sort_order`, `locale`"
		)->fetchAll(PDO::FETCH_ASSOC) ?: [];
		$usage = self::loadUsageCounts();
		$home_counts = self::loadEffectiveHomeCounts();
		$default_locale = self::getDefaultLocaleSafely();
		$result = [];

		foreach ($rows as $row) {
			$locale = LocaleService::canonicalize((string) ($row['locale'] ?? ''));
			$result[] = [
				'locale' => $locale,
				'label' => (string) ($row['label'] ?? LocaleRegistry::getDisplayLabel($locale)),
				'native_label' => (string) ($row['native_label'] ?? LocaleRegistry::getNativeName($locale)),
				'is_enabled' => (bool) ($row['is_enabled'] ?? false),
				'is_default' => $locale === $default_locale,
				'sort_order' => (int) ($row['sort_order'] ?? 100),
				'usage' => $usage[$locale] ?? [],
				'effective_home_count' => $home_counts[$locale] ?? 0,
			];
		}

		return $result;
	}

	public static function ensureLocale(string $locale, bool $enabled = false): string
	{
		$locale = LocaleService::canonicalize($locale);

		if (!self::tableExists('locales')) {
			throw new RuntimeException('The locales table does not exist.');
		}

		$stmt = Db::instance()->prepare(
			"INSERT INTO `locales` (`locale`, `label`, `native_label`, `is_enabled`, `sort_order`)
			VALUES (?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE
				`label` = IF(`label` = '', VALUES(`label`), `label`),
				`native_label` = IF(`native_label` = '', VALUES(`native_label`), `native_label`),
				`is_enabled` = IF(VALUES(`is_enabled`) = 1, 1, `is_enabled`)"
		);
		$stmt->execute([
			$locale,
			self::getDisplayLabel($locale),
			self::getNativeName($locale),
			$enabled ? 1 : 0,
			self::nextSortOrder(),
		]);

		return $locale;
	}

	public static function setEnabled(string $locale, bool $enabled): string
	{
		$locale = LocaleService::canonicalize($locale);
		$default_locale = LocaleService::getDefaultLocale();

		if (!$enabled && $locale === $default_locale) {
			throw new RuntimeException('APP_DEFAULT_LOCALE cannot be disabled.');
		}

		$locale = self::ensureLocale($locale, $enabled);

		$stmt = Db::instance()->prepare("UPDATE `locales` SET `is_enabled` = ? WHERE `locale` = ?");
		$stmt->execute([$enabled ? 1 : 0, $locale]);

		return $locale;
	}

	public static function ensureDefaultLocaleRegistered(): void
	{
		if (!self::tableExists('locales')) {
			return;
		}

		$default_locale = LocaleService::getDefaultLocale();
		self::ensureLocale($default_locale, true);

		$stmt = Db::instance()->prepare("UPDATE `locales` SET `is_enabled` = 1 WHERE `locale` = ?");
		$stmt->execute([$default_locale]);
	}

	private static function nextSortOrder(): int
	{
		if (!self::tableExists('locales')) {
			return 100;
		}

		return ((int) Db::instance()->query('SELECT COALESCE(MAX(`sort_order`), 90) FROM `locales`')->fetchColumn()) + 10;
	}

	/**
	 * @return array<string, array<string, int>>
	 */
	private static function loadUsageCounts(): array
	{
		$result = [];

		foreach ([
			'users' => ['users', 'locale'],
			'resource_tree' => ['resource_tree', 'locale'],
			'richtext' => ['richtext', 'locale'],
			'i18n_translations' => ['i18n_translations', 'locale'],
			'i18n_build_state' => ['i18n_build_state', 'locale'],
		] as $key => [$table, $column]) {
			if (!self::columnExists($table, $column)) {
				continue;
			}

			$rows = Db::instance()->query(
				"SELECT `{$column}` AS locale, COUNT(*) AS row_count
				FROM `{$table}`
				WHERE `{$column}` IS NOT NULL AND `{$column}` <> ''
				GROUP BY `{$column}`"
			)->fetchAll(PDO::FETCH_ASSOC) ?: [];

			foreach ($rows as $row) {
				$locale = LocaleService::tryCanonicalize((string) ($row['locale'] ?? ''));

				if ($locale === null) {
					continue;
				}

				$result[$locale][$key] = (int) ($row['row_count'] ?? 0);
			}
		}

		return $result;
	}

	/**
	 * @return array<string, int>
	 */
	private static function loadEffectiveHomeCounts(): array
	{
		if (!self::tableExists('locale_home_resources')) {
			return [];
		}

		$rows = Db::instance()->query(
			"SELECT `locale`, COUNT(*) AS row_count
			FROM `locale_home_resources`
			WHERE COALESCE(`manual_resource_id`, `computed_resource_id`) IS NOT NULL
			GROUP BY `locale`"
		)->fetchAll(PDO::FETCH_ASSOC) ?: [];
		$result = [];

		foreach ($rows as $row) {
			$locale = LocaleService::tryCanonicalize((string) ($row['locale'] ?? ''));

			if ($locale !== null) {
				$result[$locale] = (int) ($row['row_count'] ?? 0);
			}
		}

		return $result;
	}

	private static function getDefaultLocaleSafely(): string
	{
		try {
			return LocaleService::getDefaultLocale();
		} catch (Throwable) {
			return LocaleService::FALLBACK_DEFAULT_LOCALE;
		}
	}

	private static function getDisplayLabel(string $locale): string
	{
		try {
			return LocaleRegistry::getDisplayLabel($locale);
		} catch (Throwable) {
			return $locale;
		}
	}

	private static function getNativeName(string $locale): string
	{
		try {
			return LocaleRegistry::getNativeName($locale);
		} catch (Throwable) {
			return $locale;
		}
	}

	private static function tableExists(string $table): bool
	{
		return DbSchemaHelper::tableExists($table);
	}

	private static function columnExists(string $table, string $column): bool
	{
		return DbSchemaHelper::columnExists($table, $column);
	}
}
