<?php

declare(strict_types=1);

/**
 * I18n runtime — request-scoped locale, shared catalog with mtime invalidation.
 *
 * Reads compiled catalogs from generated/i18n/{locale}.php.
 * The catalog array is shared across requests (static), keyed by locale,
 * and invalidated per-locale when the file mtime changes (hot reload after i18n:build).
 *
 * Fallback chain: current locale → APP_DEFAULT_LOCALE → key slug
 */
class I18nRuntime
{
	/** @var array<string, array<string, string>> locale → [key → text] */
	private static array $_catalog = [];

	/** @var array<string, int> locale → file mtime at last load */
	private static array $_catalogMtime = [];

	private static ?bool $_localesTableExists = null;
	private static ?bool $_i18nTranslationsTableExists = null;

	public static function t(string $key, array $params = []): string
	{
		$locale = LocaleService::canonicalize(Kernel::getLocale());
		$default_locale = LocaleService::getDefaultLocale();
		self::_ensureCatalogLoaded($locale);

		if ($locale !== $default_locale) {
			self::_ensureCatalogLoaded($default_locale);
		}

		$text = self::$_catalog[$locale][$key]
			?? self::$_catalog[$default_locale][$key]
			?? $key;

		return empty($params) ? $text : self::_format($text, $params, $locale);
	}

	private static function _ensureCatalogLoaded(string $locale): void
	{
		$locale = LocaleService::canonicalize($locale);
		$path = DEPLOY_ROOT . 'generated/i18n/' . $locale . '.php';

		if (!file_exists($path)) {
			self::$_catalog[$locale] = [];
			self::$_catalogMtime[$locale] = 0;

			return;
		}

		$mtime = filemtime($path);

		if (isset(self::$_catalog[$locale]) && (self::$_catalogMtime[$locale] ?? 0) === $mtime) {
			return;
		}

		self::$_catalog[$locale] = require $path;
		self::$_catalogMtime[$locale] = $mtime;
	}

	/**
	 * Return locales that currently exist in translation data.
	 *
	 * @return string[]
	 */
	public static function getAvailableLocaleCodes(): array
	{
		try {
			$pdo = Db::instance();
		} catch (Throwable) {
			return [LocaleService::getDefaultLocale()];
		}

		if (self::_localesTableExists($pdo)) {
			try {
				$stmt = $pdo->prepare('SELECT `locale` FROM `locales` WHERE `is_enabled` = 1 ORDER BY `sort_order`, `locale`');
				$stmt->execute();

				return self::_normalizeLocaleRows($stmt->fetchAll(\PDO::FETCH_ASSOC));
			} catch (Throwable $exception) {
				self::_logAvailableLocaleFailure($exception);

				throw $exception;
			}
		}

		if (!self::_i18nTranslationsTableExists($pdo)) {
			return [LocaleService::getDefaultLocale()];
		}

		try {
			$stmt = $pdo->prepare('SELECT DISTINCT `locale` FROM `i18n_translations` ORDER BY `locale`');
			$stmt->execute();
			$locales = self::_normalizeLocaleRows($stmt->fetchAll(\PDO::FETCH_ASSOC));

			if ($locales !== []) {
				return $locales;
			}
		} catch (Throwable $exception) {
			self::_logAvailableLocaleFailure($exception);

			throw $exception;
		}

		return [LocaleService::getDefaultLocale()];
	}

	private static function _localesTableExists(object $pdo): bool
	{
		if (self::$_localesTableExists !== null) {
			return self::$_localesTableExists;
		}

		try {
			return self::$_localesTableExists = $pdo->query("SHOW TABLES LIKE 'locales'")->rowCount() > 0;
		} catch (Throwable) {
			// Installation and doctor-safe flows may run before i18n tables exist.
			return self::$_localesTableExists = false;
		}
	}

	private static function _i18nTranslationsTableExists(object $pdo): bool
	{
		if (self::$_i18nTranslationsTableExists !== null) {
			return self::$_i18nTranslationsTableExists;
		}

		try {
			return self::$_i18nTranslationsTableExists = $pdo->query("SHOW TABLES LIKE 'i18n_translations'")->rowCount() > 0;
		} catch (Throwable) {
			// Installation and doctor-safe flows may run before i18n tables exist.
			return self::$_i18nTranslationsTableExists = false;
		}
	}

	private static function _logAvailableLocaleFailure(Throwable $exception): void
	{
		if (class_exists(Kernel::class) && method_exists(Kernel::class, 'logException')) {
			Kernel::logException($exception, 'I18nRuntime::getAvailableLocaleCodes failed after locale tables were detected');

			return;
		}

		error_log('I18nRuntime::getAvailableLocaleCodes failed after locale tables were detected: ' . $exception->getMessage());
	}

	/**
	 * Return locales that currently exist in translation data,
	 * formatted as FormInputSelect option arrays.
	 *
	 * @return list<array{value: string, label: string}>
	 */
	public static function getAvailableLocales(): array
	{
		return LocaleRegistry::buildSelectOptions(self::getAvailableLocaleCodes());
	}

	/**
	 * Return locales that currently exist in translation data,
	 * formatted as a select-friendly associative array.
	 *
	 * @return array<string, string>
	 */
	public static function getAvailableLocaleOptionMap(): array
	{
		return LocaleRegistry::buildOptionMap(self::getAvailableLocaleCodes());
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<string>
	 */
	private static function _normalizeLocaleRows(array $rows): array
	{
		$locales = [];

		foreach ($rows as $row) {
			$locale = LocaleService::tryCanonicalize((string) ($row['locale'] ?? ''));

			if ($locale !== null) {
				$locales[$locale] = true;
			}
		}

		$locales = array_keys($locales);
		sort($locales);

		return $locales;
	}

	private static function _format(string $text, array $params, string $locale): string
	{
		if (extension_loaded('intl')) {
			$fmt = new MessageFormatter(LocaleService::toIntlLocale($locale), $text);
			$result = $fmt->format($params);

			return $result !== false ? $result : $text;
		}

		return preg_replace_callback(
			'/\{(\w+)\}/',
			fn ($m) => isset($params[$m[1]]) ? (string) $params[$m[1]] : $m[0],
			$text
		);
	}
}
