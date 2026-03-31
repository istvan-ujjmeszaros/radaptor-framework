<?php

declare(strict_types=1);

final class LocaleRegistry
{
	/** @var null|array<string, array{native: string, english: string}> */
	private static ?array $_known_locales = null;

	/**
	 * @return array<string, array{native: string, english: string}>
	 */
	public static function getKnownLocales(): array
	{
		if (self::$_known_locales !== null) {
			return self::$_known_locales;
		}

		if (!extension_loaded('intl') || !class_exists(ResourceBundle::class) || !class_exists(Locale::class)) {
			throw new RuntimeException('LocaleRegistry requires the PHP intl extension.');
		}

		$knownLocales = [];

		foreach (ResourceBundle::getLocales('') as $locale) {
			if (!self::_isSupportedLocaleCode($locale)) {
				continue;
			}

			$english = self::_normalizeDisplayName(Locale::getDisplayLanguage($locale, 'en'));
			$native = self::_normalizeDisplayName(Locale::getDisplayLanguage($locale, $locale));

			$knownLocales[$locale] = [
				'english' => $english !== '' ? $english : $locale,
				'native' => $native !== '' ? $native : ($english !== '' ? $english : $locale),
			];
		}

		ksort($knownLocales);
		self::$_known_locales = $knownLocales;

		return self::$_known_locales;
	}

	/**
	 * @return string[]
	 */
	public static function getKnownLocaleCodes(): array
	{
		return array_keys(self::getKnownLocales());
	}

	public static function isKnownLocale(string $locale): bool
	{
		$locale = trim($locale);

		if ($locale === '') {
			return false;
		}

		return isset(self::getKnownLocales()[$locale]);
	}

	public static function getNativeName(string $locale): string
	{
		return self::getKnownLocales()[$locale]['native'] ?? $locale;
	}

	public static function getDisplayLabel(string $locale): string
	{
		return self::getNativeName($locale) . ' (' . $locale . ')';
	}

	/**
	 * @param string[] $localeCodes
	 * @return list<array{value: string, label: string}>
	 */
	public static function buildSelectOptions(array $localeCodes): array
	{
		$options = [];

		foreach (self::_normalizeLocaleCodes($localeCodes) as $locale) {
			$options[] = [
				'value' => $locale,
				'label' => self::getDisplayLabel($locale),
			];
		}

		return $options;
	}

	/**
	 * @param string[] $localeCodes
	 * @return array<string, string>
	 */
	public static function buildOptionMap(array $localeCodes): array
	{
		$options = [];

		foreach (self::_normalizeLocaleCodes($localeCodes) as $locale) {
			$options[$locale] = self::getDisplayLabel($locale);
		}

		return $options;
	}

	/**
	 * @param string[] $localeCodes
	 * @return string[]
	 */
	private static function _normalizeLocaleCodes(array $localeCodes): array
	{
		$unique = [];

		foreach ($localeCodes as $locale) {
			$locale = trim((string) $locale);

			if ($locale === '') {
				continue;
			}

			$unique[$locale] = true;
		}

		$normalized = array_keys($unique);
		usort($normalized, fn (string $a, string $b): int => strcmp(self::getDisplayLabel($a), self::getDisplayLabel($b)));

		return $normalized;
	}

	private static function _isSupportedLocaleCode(string $locale): bool
	{
		$locale = trim($locale);

		if ($locale === '' || !str_contains($locale, '_') || strlen($locale) > 10) {
			return false;
		}

		return preg_match('/^[a-z]{2,3}(?:_[A-Za-z0-9]{2,4}){1,2}$/', $locale) === 1;
	}

	private static function _normalizeDisplayName(string|false $displayName): string
	{
		if (!is_string($displayName)) {
			return '';
		}

		$displayName = trim($displayName);

		if ($displayName === '') {
			return '';
		}

		if (function_exists('mb_convert_case')) {
			return mb_convert_case($displayName, MB_CASE_TITLE, 'UTF-8');
		}

		return ucfirst($displayName);
	}
}
