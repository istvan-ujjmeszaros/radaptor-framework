<?php

declare(strict_types=1);

final class I18nCsvSchema
{
	public const string SOURCE_LOCALE = 'en-US';

	public const array NORMALIZED_HEADER = [
		'domain',
		'key',
		'context',
		'locale',
		'source_text',
		'expected_text',
		'human_reviewed',
		'allow_source_match',
		'text',
	];

	public static function isSourceLocale(string $locale): bool
	{
		$locale = LocaleService::tryCanonicalize($locale) ?? trim($locale);

		return $locale === self::SOURCE_LOCALE;
	}

	public static function isEligibleSourceMatch(string $locale, string $sourceText, string $text): bool
	{
		if (self::isSourceLocale($locale)) {
			return false;
		}

		return trim($sourceText) !== '' && trim($text) !== '' && $sourceText === $text;
	}

	public static function normalizeBoolean(mixed $value): bool
	{
		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value)) {
			return $value === 1;
		}

		$value = mb_strtolower(trim((string) $value));

		return in_array($value, ['1', 'true', 'yes', 'on'], true);
	}

	/**
	 * CSV imports accept common truthy forms; seed lint remains stricter and only accepts 0/1.
	 */
	public static function normalizeImportedBoolean(mixed $value): ?bool
	{
		$value = trim((string) $value);

		if ($value === '') {
			return null;
		}

		return self::normalizeBoolean($value);
	}
}
