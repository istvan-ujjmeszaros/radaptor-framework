<?php

declare(strict_types=1);

final class LocaleService
{
	public const string FALLBACK_DEFAULT_LOCALE = 'en-US';
	private const string STORAGE_PATTERN = '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-(?:[A-Z]{2}|[0-9]{3}))?$/';

	public static function canonicalize(string $locale): string
	{
		$locale = trim(str_replace('_', '-', $locale));

		if ($locale === '') {
			throw new InvalidArgumentException('Locale is required.');
		}

		if (class_exists(Locale::class)) {
			$locale = str_replace('_', '-', Locale::canonicalize($locale) ?: $locale);
		}

		$parts = array_values(array_filter(explode('-', $locale), static fn (string $part): bool => $part !== ''));

		if ($parts === []) {
			throw new InvalidArgumentException('Locale is required.');
		}

		$language = strtolower(array_shift($parts));

		if (!preg_match('/^[a-z]{2,3}$/', $language)) {
			throw new InvalidArgumentException("Unsupported locale language subtag: {$locale}");
		}

		$canonical = [$language];
		$script = null;
		$region = null;

		foreach ($parts as $part) {
			if ($script === null && preg_match('/^[A-Za-z]{4}$/', $part)) {
				$script = ucfirst(strtolower($part));
				$canonical[] = $script;

				continue;
			}

			if ($region === null && preg_match('/^(?:[A-Za-z]{2}|[0-9]{3})$/', $part)) {
				$region = strtoupper($part);
				$canonical[] = $region;

				continue;
			}

			throw new InvalidArgumentException("Unsupported locale subtag '{$part}' in {$locale}.");
		}

		$result = implode('-', $canonical);

		if (!self::isSupportedStorageLocale($result)) {
			throw new InvalidArgumentException("Unsupported storage locale: {$locale}");
		}

		return $result;
	}

	public static function tryCanonicalize(string $locale): ?string
	{
		try {
			return self::canonicalize($locale);
		} catch (InvalidArgumentException) {
			return null;
		}
	}

	public static function isCanonicalBcp47(string $locale): bool
	{
		$locale = trim($locale);

		return $locale !== '' && self::tryCanonicalize($locale) === $locale;
	}

	public static function isSupportedStorageLocale(string $locale): bool
	{
		return preg_match(self::STORAGE_PATTERN, $locale) === 1;
	}

	public static function toIntlLocale(string $locale): string
	{
		return str_replace('-', '_', self::canonicalize($locale));
	}

	public static function toPosixLocale(string $locale): string
	{
		return self::toIntlLocale($locale) . '.UTF-8';
	}

	public static function getDefaultLocale(): string
	{
		try {
			return self::validateConfiguredDefaultLocale();
		} catch (Throwable $exception) {
			if (self::isDoctorSafeBootstrap()) {
				return self::FALLBACK_DEFAULT_LOCALE;
			}

			throw $exception;
		}
	}

	public static function validateConfiguredDefaultLocale(): string
	{
		$value = self::getConfiguredDefaultLocaleRaw();
		$locale = self::canonicalize((string) $value);

		if (!self::isSupportedStorageLocale($locale)) {
			throw new RuntimeException("Invalid APP_DEFAULT_LOCALE: {$value}");
		}

		return $locale;
	}

	private static function getConfiguredDefaultLocaleRaw(): string
	{
		$value = getenv('APP_DEFAULT_LOCALE');

		if ($value === false || trim((string) $value) === '') {
			$value = defined(ApplicationConfig::class . '::APP_DEFAULT_LOCALE')
				? (string) constant(ApplicationConfig::class . '::APP_DEFAULT_LOCALE')
				: self::FALLBACK_DEFAULT_LOCALE;
		}

		return (string) $value;
	}

	private static function isDoctorSafeBootstrap(): bool
	{
		if (defined('RADAPTOR_DOCTOR_SAFE') && (bool) constant('RADAPTOR_DOCTOR_SAFE')) {
			return true;
		}

		return defined('RADAPTOR_CLI')
			&& class_exists(CLICommandResolver::class)
			&& CLICommandResolver::getCommandSlugFromArgv() === 'i18n:doctor';
	}

	/**
	 * @return list<string>
	 */
	public static function enabledForUserChoice(): array
	{
		return self::loadLocales(true);
	}

	/**
	 * @return list<string>
	 */
	public static function enabledForNewContent(): array
	{
		return self::enabledForUserChoice();
	}

	public static function isEnabled(string $locale): bool
	{
		$locale = self::tryCanonicalize($locale);

		return $locale !== null && in_array($locale, self::enabledForUserChoice(), true);
	}

	/**
	 * @return list<string>
	 */
	public static function allForExistingContentEditing(?string $currentLocale = null): array
	{
		$locales = self::loadLocales(false);
		$current = $currentLocale !== null ? self::tryCanonicalize($currentLocale) : null;

		if ($current !== null && !in_array($current, $locales, true)) {
			$locales[] = $current;
		}

		return $locales;
	}

	/**
	 * @return list<string>
	 */
	public static function allForI18nMaintenance(): array
	{
		return self::loadLocales(false);
	}

	/**
	 * @param list<string> $enabledLocales
	 */
	public static function matchEnabledLocaleFromAcceptLanguage(string $header, array $enabledLocales, ?string $defaultLocale = null): string
	{
		$enabled = self::normalizeLocaleList($enabledLocales);
		$defaultLocale = $defaultLocale !== null ? self::canonicalize($defaultLocale) : self::getDefaultLocale();

		if ($enabled === []) {
			return $defaultLocale;
		}

		foreach (self::parseAcceptLanguage($header) as $requested) {
			if (isset($enabled[$requested])) {
				return $requested;
			}

			$match = self::matchByScriptOrLanguage($requested, array_keys($enabled));

			if ($match !== null) {
				return $match;
			}
		}

		return $defaultLocale;
	}

	/**
	 * @return list<string>
	 */
	private static function loadLocales(bool $enabled_only): array
	{
		try {
			$pdo = Db::instance();

			if (!DbSchemaHelper::tableExists('locales', $pdo)) {
				return I18nRuntime::getAvailableLocaleCodes();
			}

			$sql = "SELECT `locale` FROM `locales`";

			if ($enabled_only) {
				$sql .= " WHERE `is_enabled` = 1";
			}

			$sql .= " ORDER BY `sort_order`, `locale`";
			$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
			$locales = [];
			$seen = [];

			foreach ($rows as $row) {
				$locale = self::tryCanonicalize((string) ($row['locale'] ?? ''));

				if ($locale === null || isset($seen[$locale])) {
					continue;
				}

				$seen[$locale] = true;
				$locales[] = $locale;
			}

			return $locales;
		} catch (Throwable) {
			return [self::getDefaultLocale()];
		}
	}

	/**
	 * @param list<string> $locales
	 * @return array<string, true>
	 */
	public static function normalizeLocaleList(array $locales): array
	{
		$normalized = [];

		foreach ($locales as $locale) {
			$canonical = self::tryCanonicalize((string) $locale);

			if ($canonical !== null) {
				$normalized[$canonical] = true;
			}
		}

		uksort($normalized, static fn (string $a, string $b): int => strcmp($a, $b));

		return $normalized;
	}

	/**
	 * @return list<string>
	 */
	private static function parseAcceptLanguage(string $header): array
	{
		$candidates = [];

		foreach (explode(',', $header) as $index => $part) {
			$part = trim($part);

			if ($part === '') {
				continue;
			}

			[$rawLocale, $rawParams] = array_pad(explode(';', $part, 2), 2, '');
			$locale = self::tryCanonicalize($rawLocale);

			if ($locale === null) {
				continue;
			}

			$q = 1.0;

			foreach (explode(';', $rawParams) as $rawParam) {
				$param = trim($rawParam);

				if ($param === '' || !str_starts_with(strtolower($param), 'q=')) {
					continue;
				}

				if (!preg_match('/^q=(?:0(?:\.[0-9]{1,3})?|1(?:\.0{1,3})?)$/i', $param)) {
					$q = -1.0;

					break;
				}

				$q = (float) substr($param, 2);
			}

			if ($q <= 0.0) {
				continue;
			}

			$candidates[] = [
				'locale' => $locale,
				'q' => $q,
				'index' => $index,
			];
		}

		usort($candidates, static function (array $a, array $b): int {
			$quality = $b['q'] <=> $a['q'];

			return $quality !== 0 ? $quality : ($a['index'] <=> $b['index']);
		});

		return array_values(array_unique(array_map(
			static fn (array $candidate): string => (string) $candidate['locale'],
			$candidates
		)));
	}

	/**
	 * @param list<string> $enabled
	 */
	private static function matchByScriptOrLanguage(string $requested, array $enabled): ?string
	{
		$requestParts = self::splitLocale($requested);
		$sameLanguage = array_values(array_filter(
			$enabled,
			static fn (string $candidate): bool => self::splitLocale($candidate)['language'] === $requestParts['language']
		));

		if ($sameLanguage === []) {
			return null;
		}

		if ($requestParts['script'] !== null) {
			$sameScript = array_values(array_filter(
				$sameLanguage,
				static fn (string $candidate): bool => self::splitLocale($candidate)['script'] === $requestParts['script']
			));

			if (count($sameScript) === 1) {
				return $sameScript[0];
			}
		}

		if (count($sameLanguage) === 1 && !self::hasScriptConflict($requestParts, self::splitLocale($sameLanguage[0]))) {
			return $sameLanguage[0];
		}

		return null;
	}

	/**
	 * @return array{language: string, script: string|null, region: string|null}
	 */
	private static function splitLocale(string $locale): array
	{
		$parts = explode('-', self::canonicalize($locale));
		$script = null;
		$region = null;

		foreach (array_slice($parts, 1) as $part) {
			if (preg_match('/^[A-Z][a-z]{3}$/', $part)) {
				$script = $part;
			} elseif (preg_match('/^(?:[A-Z]{2}|[0-9]{3})$/', $part)) {
				$region = $part;
			}
		}

		return [
			'language' => $parts[0],
			'script' => $script,
			'region' => $region,
		];
	}

	/**
	 * @param array{script: string|null} $requested
	 * @param array{script: string|null} $candidate
	 */
	private static function hasScriptConflict(array $requested, array $candidate): bool
	{
		return $requested['script'] !== null
			&& $candidate['script'] !== null
			&& $requested['script'] !== $candidate['script'];
	}
}
