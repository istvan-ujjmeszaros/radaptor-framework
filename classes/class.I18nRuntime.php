<?php

declare(strict_types=1);

/**
 * I18n runtime — request-scoped locale, shared catalog with mtime invalidation.
 *
 * Reads compiled catalogs from generated/i18n/{locale}.php.
 * The catalog array is shared across requests (static), keyed by locale,
 * and invalidated per-locale when the file mtime changes (hot reload after i18n:build).
 *
 * Fallback chain: current locale → en_US → key slug
 */
class I18nRuntime
{
	/** @var array<string, array<string, string>> locale → [key → text] */
	private static array $_catalog = [];

	/** @var array<string, int> locale → file mtime at last load */
	private static array $_catalogMtime = [];

	public static function t(string $key, array $params = []): string
	{
		$locale = Kernel::getLocale();
		self::_ensureCatalogLoaded($locale);

		if ($locale !== 'en_US') {
			self::_ensureCatalogLoaded('en_US');
		}

		$text = self::$_catalog[$locale][$key]
			?? self::$_catalog['en_US'][$key]
			?? $key;

		return empty($params) ? $text : self::_format($text, $params, $locale);
	}

	private static function _ensureCatalogLoaded(string $locale): void
	{
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
		$stmt = Db::instance()->prepare('SELECT DISTINCT `locale` FROM `i18n_translations` ORDER BY `locale`');
		$stmt->execute();
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		return array_map(
			static fn (array $row): string => (string) $row['locale'],
			$rows
		);
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

	private static function _format(string $text, array $params, string $locale): string
	{
		if (extension_loaded('intl')) {
			$fmt = new MessageFormatter($locale, $text);
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
