<?php

declare(strict_types=1);

class I18nSeedLintService
{
	private const array EXPECTED_HEADER = [
		'domain',
		'key',
		'context',
		'locale',
		'source_text',
		'expected_text',
		'human_reviewed',
		'text',
	];

	private const array SAME_AS_SOURCE_ALLOWLIST = [
		'API',
		'CLI',
		'CSS',
		'CSV',
		'DB',
		'HTML',
		'HTTP',
		'HTTPS',
		'ID',
		'IP',
		'JSON',
		'MCP',
		'PHP',
		'SEO',
		'SQL',
		'TM',
		'UID',
		'URL',
		'XML',
	];

	private const array SAME_AS_SOURCE_PREFIX_ALLOWLIST = [
		'Claude Desktop',
	];

	/**
	 * @param list<array<string, mixed>>|null $targets
	 * @return array{
	 *     status: string,
	 *     targets_checked: int,
	 *     files_checked: int,
	 *     rows_checked: int,
	 *     errors: int,
	 *     warnings: int,
	 *     issues: list<array<string, mixed>>,
	 *     targets: list<array<string, mixed>>
	 * }
	 */
	public static function lint(?array $targets = null, array $options = []): array
	{
		$targets ??= I18nSeedTargetDiscovery::discoverTargets();
		$check_global_duplicates = (bool) ($options['check_global_duplicates'] ?? true);
		$issues = [];
		$target_results = [];
		$files_checked = 0;
		$rows_checked = 0;
		$global_keys = [];
		$expected_locales = self::resolveExpectedLocales($targets, $options['expected_locales'] ?? null);

		foreach ($targets as $target) {
			$result = self::lintTarget($target, $global_keys, $check_global_duplicates, $expected_locales);
			$target_results[] = $result;
			$issues = [...$issues, ...$result['issues']];
			$files_checked += (int) $result['files_checked'];
			$rows_checked += (int) $result['rows_checked'];
		}

		$errors = count(array_filter($issues, static fn (array $issue): bool => $issue['severity'] === 'error'));
		$warnings = count(array_filter($issues, static fn (array $issue): bool => $issue['severity'] === 'warning'));

		return [
			'status' => $errors > 0 ? 'error' : ($warnings > 0 ? 'warning' : 'ok'),
			'targets_checked' => count($targets),
			'files_checked' => $files_checked,
			'rows_checked' => $rows_checked,
			'errors' => $errors,
			'warnings' => $warnings,
			'issues' => $issues,
			'targets' => $target_results,
		];
	}

	/**
	 * @param array<string, mixed> $target
	 * @param array<string, string> $global_keys
	 * @param list<string> $expected_locales
	 * @return array<string, mixed>
	 */
	private static function lintTarget(array $target, array &$global_keys, bool $check_global_duplicates, array $expected_locales): array
	{
		$input_dir = (string) $target['input_dir'];
		$issues = [];
		$files_checked = 0;
		$rows_checked = 0;
		$allowed_domains = self::normalizeAllowedDomains($target['domains'] ?? []);

		if (!is_dir($input_dir)) {
			$issues[] = self::issue('warning', 'target_missing', $input_dir, 0, "Seed target directory is missing: {$input_dir}");

			return [
				...$target,
				'status' => 'missing',
				'files_checked' => 0,
				'rows_checked' => 0,
				'issues' => $issues,
			];
		}

		$files = glob(rtrim($input_dir, '/') . '/*.csv') ?: [];
		sort($files);

		if ($files === []) {
			$issues[] = self::issue('warning', 'target_empty', $input_dir, 0, "Seed target contains no locale CSV files: {$input_dir}");
		}

		$locale_files = [];

		foreach ($files as $file) {
			$locale = LocaleService::tryCanonicalize(basename($file, '.csv')) ?? basename($file, '.csv');
			$locale_files[$locale] = true;
			$file_result = self::lintFile($file, $locale, $global_keys, $allowed_domains, $check_global_duplicates);
			$issues = [...$issues, ...$file_result['issues']];
			$files_checked++;
			$rows_checked += $file_result['rows_checked'];
		}

		if ($files !== [] && !isset($locale_files['en-US'])) {
			$issues[] = self::issue('error', 'missing_en_us_seed', $input_dir, 0, 'Seed target must contain en-US.csv as its source-locale baseline.');
		}

		if ($files !== []) {
			foreach ($expected_locales as $expected_locale) {
				if ($expected_locale === 'en-US' || isset($locale_files[$expected_locale])) {
					continue;
				}

				$issues[] = self::issue('warning', 'missing_locale_seed', $input_dir, 0, "Seed target is missing {$expected_locale}.csv.");
			}
		}

		$error_count = count(array_filter($issues, static fn (array $issue): bool => $issue['severity'] === 'error'));
		$warning_count = count(array_filter($issues, static fn (array $issue): bool => $issue['severity'] === 'warning'));

		return [
			...$target,
			'status' => $error_count > 0 ? 'error' : ($warning_count > 0 ? 'warning' : 'ok'),
			'files_checked' => $files_checked,
			'rows_checked' => $rows_checked,
			'issues' => $issues,
		];
	}

	/**
	 * @param array<string, string> $global_keys
	 * @param array<string, true> $allowed_domains
	 * @return array{rows_checked: int, issues: list<array<string, mixed>>}
	 */
	private static function lintFile(string $file, string $expected_locale, array &$global_keys, array $allowed_domains, bool $check_global_duplicates): array
	{
		$issues = [];
		$expected_locale = LocaleService::tryCanonicalize($expected_locale) ?? $expected_locale;

		if (!LocaleRegistry::isKnownLocale($expected_locale)) {
			$issues[] = self::issue('error', 'unknown_filename_locale', $file, 0, "Seed filename locale is not supported: {$expected_locale}");
		}

		$handle = fopen($file, 'r');

		if ($handle === false) {
			return [
				'rows_checked' => 0,
				'issues' => [
					self::issue('error', 'unreadable_file', $file, 0, "Unable to read seed file: {$file}"),
				],
			];
		}

		$header = fgetcsv($handle, 0, ',', '"', '');
		$line = 1;

		if ($header === false) {
			fclose($handle);

			return [
				'rows_checked' => 0,
				'issues' => [
					self::issue('error', 'empty_file', $file, 1, 'CSV file is empty.'),
				],
			];
		}

		$header = self::normalizeHeader($header);

		if ($header !== self::EXPECTED_HEADER) {
			$issues[] = self::issue(
				'error',
				'invalid_header',
				$file,
				1,
				'CSV header must be: ' . implode(',', self::EXPECTED_HEADER)
			);
		}

		$indexes = array_flip($header);
		$file_keys = [];
		$rows_checked = 0;

		while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
			$line++;

			if (self::isIgnorableRow($row)) {
				continue;
			}

			$rows_checked++;

			if (count($row) !== count($header)) {
				$issues[] = self::issue('error', 'invalid_column_count', $file, $line, 'CSV row column count does not match the header.');

				continue;
			}

			$data = [];

			foreach ($indexes as $name => $index) {
				$data[$name] = trim((string) ($row[$index] ?? ''));
			}

			if (($data['locale'] ?? '') !== '') {
				$data['locale'] = LocaleService::tryCanonicalize((string) $data['locale']) ?? (string) $data['locale'];
			}

			foreach (['domain', 'key', 'locale', 'source_text', 'text'] as $required) {
				if (($data[$required] ?? '') === '') {
					$issues[] = self::issue('error', 'required_value_missing', $file, $line, "Required column '{$required}' is empty.");
				}
			}

			if (($data['locale'] ?? '') !== $expected_locale) {
				$issues[] = self::issue('error', 'locale_mismatch', $file, $line, "Row locale must match filename locale {$expected_locale}.");
			}

			$domain = (string) ($data['domain'] ?? '');

			if ($allowed_domains !== [] && $domain !== '' && !isset($allowed_domains[$domain])) {
				$issues[] = self::issue('error', 'domain_out_of_scope', $file, $line, "Domain '{$domain}' is not registered for this seed target.");
			}

			if (
				($data['locale'] ?? '') !== 'en-US'
				&& self::looksLikeUntranslatedSource((string) ($data['source_text'] ?? ''), (string) ($data['text'] ?? ''))
			) {
				$issues[] = self::issue('warning', 'text_matches_source', $file, $line, 'Non-source locale text matches source_text and may be an English placeholder.');
			}

			$natural_key = implode("\0", [
				$data['domain'] ?? '',
				$data['key'] ?? '',
				$data['context'] ?? '',
				$data['locale'] ?? '',
			]);

			if (isset($file_keys[$natural_key])) {
				$issues[] = self::issue('error', 'duplicate_file_key', $file, $line, 'Duplicate domain/key/context/locale row in the same seed file.');
			}

			$file_keys[$natural_key] = true;

			if ($check_global_duplicates && isset($global_keys[$natural_key]) && $global_keys[$natural_key] !== $file) {
				$issues[] = self::issue('warning', 'duplicate_seed_key', $file, $line, 'Same domain/key/context/locale also appears in ' . $global_keys[$natural_key]);
			}

			if ($check_global_duplicates) {
				$global_keys[$natural_key] = $file;
			}
		}

		fclose($handle);

		return [
			'rows_checked' => $rows_checked,
			'issues' => $issues,
		];
	}

	/**
	 * @param mixed $domains
	 * @return array<string, true>
	 */
	private static function normalizeAllowedDomains(mixed $domains): array
	{
		if (!is_array($domains)) {
			return [];
		}

		$normalized = [];

		foreach ($domains as $domain) {
			$domain = trim((string) $domain);

			if ($domain !== '') {
				$normalized[$domain] = true;
			}
		}

		return $normalized;
	}

	private static function looksLikeUntranslatedSource(string $source_text, string $text): bool
	{
		$source_text = trim($source_text);
		$text = trim($text);

		if ($source_text === '' || $text === '' || $source_text !== $text) {
			return false;
		}

		if (in_array($text, self::SAME_AS_SOURCE_ALLOWLIST, true)) {
			return false;
		}

		foreach (self::SAME_AS_SOURCE_PREFIX_ALLOWLIST as $prefix) {
			if (str_starts_with($text, $prefix)) {
				return false;
			}
		}

		if (preg_match('/^\d+(?:\s+(?:minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years))?$/i', $text) === 1) {
			return false;
		}

		preg_match_all('/[A-Za-z]{3,}/', $text, $words);

		return count($words[0] ?? []) >= 3;
	}

	/**
	 * @param list<array<string, mixed>> $targets
	 * @return list<string>
	 */
	private static function resolveExpectedLocales(array $targets, mixed $configured): array
	{
		if (is_array($configured)) {
			$locales = [];

			foreach ($configured as $locale) {
				$locale = LocaleService::tryCanonicalize((string) $locale) ?? '';

				if ($locale !== '' && LocaleRegistry::isKnownLocale($locale)) {
					$locales[$locale] = true;
				}
			}

			ksort($locales);

			return array_keys($locales);
		}

		$locales = [];

		foreach ($targets as $target) {
			$input_dir = (string) ($target['input_dir'] ?? '');

			if (!is_dir($input_dir)) {
				continue;
			}

			foreach (glob(rtrim($input_dir, '/') . '/*.csv') ?: [] as $file) {
				$locale = LocaleService::tryCanonicalize(basename($file, '.csv')) ?? '';

				if (LocaleRegistry::isKnownLocale($locale)) {
					$locales[$locale] = true;
				}
			}
		}

		ksort($locales);

		return array_keys($locales);
	}

	/**
	 * @param list<string|null> $header
	 * @return list<string>
	 */
	private static function normalizeHeader(array $header): array
	{
		return array_map(
			static function (mixed $column): string {
				return trim((string) $column, "\xEF\xBB\xBF \t\n\r\0\x0B");
			},
			$header
		);
	}

	/**
	 * @param list<string|null> $row
	 */
	private static function isIgnorableRow(array $row): bool
	{
		foreach ($row as $value) {
			if (trim((string) $value) !== '') {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array{severity: string, code: string, file: string, line: int, message: string}
	 */
	private static function issue(string $severity, string $code, string $file, int $line, string $message): array
	{
		return [
			'severity' => $severity,
			'code' => $code,
			'file' => str_replace('\\', '/', $file),
			'line' => $line,
			'message' => $message,
		];
	}
}
