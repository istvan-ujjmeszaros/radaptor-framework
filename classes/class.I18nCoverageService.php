<?php

declare(strict_types=1);

class I18nCoverageService
{
	/**
	 * @param array{locales?: list<string>, domain?: string} $options
	 * @return array{
	 *     status: string,
	 *     total_messages: int,
	 *     locales: list<array<string, mixed>>,
	 *     domains: list<array<string, mixed>>
	 * }
	 */
	public static function summarize(array $options = []): array
	{
		$locales = self::normalizeLocales($options['locales'] ?? I18nRuntime::getAvailableLocaleCodes());
		$domain = trim((string) ($options['domain'] ?? ''));
		$total_messages = self::countMessages($domain);
		$locale_summaries = [];

		foreach ($locales as $locale) {
			$locale_summaries[] = self::summarizeLocale($locale, $domain, $total_messages);
		}

		return [
			'status' => self::statusForLocaleSummaries($locale_summaries),
			'total_messages' => $total_messages,
			'locales' => $locale_summaries,
			'domains' => self::summarizeDomains($locales),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function summarizeLocale(string $locale, string $domain = '', ?int $total_messages = null): array
	{
		if (!LocaleRegistry::isKnownLocale($locale)) {
			throw new RuntimeException("Unknown locale: {$locale}");
		}

		$domain = trim($domain);
		$total_messages ??= self::countMessages($domain);
		$where = $domain !== '' ? 'WHERE m.domain = :domain' : '';
		$params = [':locale' => $locale];

		if ($domain !== '') {
			$params[':domain'] = $domain;
		}

		$stmt = Db::instance()->prepare(
			"SELECT
				SUM(CASE WHEN t.`key` IS NOT NULL AND TRIM(COALESCE(t.text, '')) <> '' THEN 1 ELSE 0 END) AS translated,
				SUM(CASE WHEN t.`key` IS NULL OR TRIM(COALESCE(t.text, '')) = '' THEN 1 ELSE 0 END) AS missing,
				SUM(CASE WHEN t.human_reviewed = 1 AND TRIM(COALESCE(t.text, '')) <> '' THEN 1 ELSE 0 END) AS reviewed,
				SUM(CASE WHEN t.`key` IS NOT NULL AND t.source_hash_snapshot <> m.source_hash THEN 1 ELSE 0 END) AS stale
			FROM i18n_messages m
			LEFT JOIN i18n_translations t
				ON t.domain = m.domain
				AND t.`key` = m.`key`
				AND t.context = m.context
				AND t.locale = :locale
			{$where}"
		);
		$stmt->execute($params);
		$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
		$translated = (int) ($row['translated'] ?? 0);
		$missing = (int) ($row['missing'] ?? 0);
		$reviewed = (int) ($row['reviewed'] ?? 0);
		$stale = (int) ($row['stale'] ?? 0);

		return [
			'locale' => $locale,
			'label' => LocaleRegistry::getDisplayLabel($locale),
			'total' => $total_messages,
			'translated' => $translated,
			'missing' => $missing,
			'reviewed' => $reviewed,
			'unreviewed' => max(0, $translated - $reviewed),
			'stale' => $stale,
			'translated_percent' => self::percent($translated, $total_messages),
			'reviewed_percent' => self::percent($reviewed, $total_messages),
			'status' => $total_messages <= 0 ? 'empty' : ($missing > 0 || $stale > 0 ? 'needs_work' : 'ok'),
		];
	}

	/**
	 * @param list<string> $locales
	 * @return list<array<string, mixed>>
	 */
	private static function summarizeDomains(array $locales): array
	{
		if ($locales === []) {
			return [];
		}

		$locale_selects = [];
		$params = [];

		foreach ($locales as $index => $locale) {
			$locale_selects[] = $index === 0 ? 'SELECT ? AS locale' : 'UNION ALL SELECT ?';
			$params[] = $locale;
		}

		$locale_sql = implode(' ', $locale_selects);
		$rows = Db::instance()->prepare(
			"SELECT
				m.domain,
				COUNT(DISTINCT CONCAT(m.domain, CHAR(0), m.`key`, CHAR(0), m.context)) AS total,
				SUM(CASE WHEN t.`key` IS NULL OR TRIM(COALESCE(t.text, '')) = '' THEN 1 ELSE 0 END) AS missing_total
			FROM i18n_messages m
			CROSS JOIN ({$locale_sql}) locales
			LEFT JOIN i18n_translations t
				ON t.domain = m.domain
				AND t.`key` = m.`key`
				AND t.context = m.context
				AND t.locale = locales.locale
			GROUP BY m.domain
			ORDER BY m.domain"
		);
		$rows->execute($params);
		$rows = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
		$domains = [];

		foreach ($rows as $row) {
			$domains[] = [
				'domain' => (string) $row['domain'],
				'total' => (int) $row['total'],
				'missing_total' => (int) $row['missing_total'],
			];
		}

		return $domains;
	}

	private static function countMessages(string $domain = ''): int
	{
		if ($domain === '') {
			return (int) Db::instance()->query('SELECT COUNT(*) FROM i18n_messages')->fetchColumn();
		}

		$stmt = Db::instance()->prepare('SELECT COUNT(*) FROM i18n_messages WHERE domain = :domain');
		$stmt->execute([':domain' => $domain]);

		return (int) $stmt->fetchColumn();
	}

	/**
	 * @param mixed $locales
	 * @return list<string>
	 */
	private static function normalizeLocales(mixed $locales): array
	{
		if (!is_array($locales)) {
			return [];
		}

		$normalized = [];

		foreach ($locales as $locale) {
			$locale = trim((string) $locale);

			if ($locale === '') {
				continue;
			}

			if (!LocaleRegistry::isKnownLocale($locale)) {
				throw new RuntimeException("Unknown locale: {$locale}");
			}

			$normalized[$locale] = true;
		}

		$locales = array_keys($normalized);
		sort($locales);

		return $locales;
	}

	private static function percent(int $value, int $total): float
	{
		if ($total <= 0) {
			return 0.0;
		}

		return round(($value / $total) * 100, 1);
	}

	/**
	 * @param list<array<string, mixed>> $summaries
	 */
	private static function statusForLocaleSummaries(array $summaries): string
	{
		foreach ($summaries as $summary) {
			if (!in_array(($summary['status'] ?? ''), ['ok', 'empty'], true)) {
				return 'needs_work';
			}
		}

		return 'ok';
	}
}
