<?php

declare(strict_types=1);

class I18nShippedDatabaseAuditService
{
	/**
	 * @param array{
	 *     all_packages?: bool,
	 *     locales?: list<string>
	 * } $options
	 * @return array{
	 *     status: string,
	 *     scope: string,
	 *     locales: list<string>,
	 *     groups_processed: int,
	 *     files_processed: int,
	 *     missing_rows: int,
	 *     changed_rows: int,
	 *     customized_rows: int,
	 *     conflicts: int,
	 *     has_errors: bool,
	 *     sync_locales: list<string>,
	 *     suggested_command: string,
	 *     issues: list<array<string, mixed>>
	 * }
	 */
	public static function audit(array $options = []): array
	{
		$all_packages = (bool) ($options['all_packages'] ?? false);
		$locales = self::normalizeLocales($options['locales'] ?? I18nRuntime::getAvailableLocaleCodes());

		if ($locales === []) {
			$locales = [LocaleService::getDefaultLocale()];
		}

		$scope = $all_packages ? 'all_packages' : 'active';

		try {
			return self::auditTargets(
				I18nSeedTargetDiscovery::discoverTargets([
					'all_packages' => $all_packages,
				]),
				$locales,
				$scope
			);
		} catch (Throwable $exception) {
			return [
				'status' => 'error',
				'scope' => $scope,
				'locales' => $locales,
				'groups_processed' => 0,
				'files_processed' => 0,
				'missing_rows' => 0,
				'changed_rows' => 0,
				'customized_rows' => 0,
				'conflicts' => 0,
				'has_errors' => true,
				'sync_locales' => $locales,
				'suggested_command' => self::buildSuggestedCommand($locales),
				'issues' => [
					[
						'code' => 'shipped_i18n_database_audit_failed',
						'message' => $exception->getMessage(),
					],
				],
			];
		}
	}

	/**
	 * Read-only shipped translation database audit. This intentionally does not
	 * call the CSV importer dry-run because that path performs per-row entity
	 * lookups and is too expensive for a diagnostic command.
	 *
	 * @param list<array<string, mixed>> $targets
	 * @param list<string> $locales
	 * @return array{
	 *     status: string,
	 *     scope: string,
	 *     locales: list<string>,
	 *     groups_processed: int,
	 *     files_processed: int,
	 *     missing_rows: int,
	 *     changed_rows: int,
	 *     customized_rows: int,
	 *     conflicts: int,
	 *     has_errors: bool,
	 *     sync_locales: list<string>,
	 *     suggested_command: string,
	 *     issues: list<array<string, mixed>>
	 * }
	 */
	public static function auditTargets(array $targets, array $locales, string $scope = 'active'): array
	{
		return self::summarizeSyncResult(
			self::buildAuditResult(self::uniqueTargets($targets), $locales),
			$locales,
			$scope
		);
	}

	/**
	 * @param array<string, mixed> $sync_result
	 * @param list<string> $locales
	 * @return array{
	 *     status: string,
	 *     scope: string,
	 *     locales: list<string>,
	 *     groups_processed: int,
	 *     files_processed: int,
	 *     missing_rows: int,
	 *     changed_rows: int,
	 *     customized_rows: int,
	 *     conflicts: int,
	 *     has_errors: bool,
	 *     sync_locales: list<string>,
	 *     suggested_command: string,
	 *     issues: list<array<string, mixed>>
	 * }
	 */
	public static function summarizeSyncResult(array $sync_result, array $locales, string $scope = 'active'): array
	{
		$issues = self::extractIssues($sync_result);
		$sync_locales = self::extractSyncLocales($issues);
		$missing_rows = (int) ($sync_result['inserted'] ?? 0);
		$changed_rows = (int) ($sync_result['updated'] ?? 0);
		$customized_rows = (int) ($sync_result['conflicts'] ?? 0);
		$has_errors = (bool) ($sync_result['has_errors'] ?? false);
		$needs_sync = $missing_rows > 0 || $changed_rows > 0;
		$status = match (true) {
			$has_errors => 'error',
			$needs_sync => 'needs_sync',
			$customized_rows > 0 => 'customized',
			default => 'ok',
		};

		return [
			'status' => $status,
			'scope' => $scope,
			'locales' => $locales,
			'groups_processed' => (int) ($sync_result['groups_processed'] ?? 0),
			'files_processed' => (int) ($sync_result['files_processed'] ?? 0),
			'missing_rows' => $missing_rows,
			'changed_rows' => $changed_rows,
			'customized_rows' => $customized_rows,
			// Backward-compatible alias: conflicts are human-reviewed DB rows
			// whose current text no longer matches the shipped expected_text.
			'conflicts' => $customized_rows,
			'has_errors' => $has_errors,
			'sync_locales' => $sync_locales,
			'suggested_command' => self::buildSuggestedCommand($sync_locales),
			'issues' => $issues,
		];
	}

	/**
	 * @param list<array<string, mixed>> $targets
	 * @param list<string> $locales
	 * @return array<string, mixed>
	 */
	private static function buildAuditResult(array $targets, array $locales): array
	{
		usort($targets, static function (array $left, array $right): int {
			return [
				(string) ($left['group_type'] ?? ''),
				(string) ($left['group_id'] ?? ''),
				(string) ($left['input_dir'] ?? ''),
			] <=> [
				(string) ($right['group_type'] ?? ''),
				(string) ($right['group_id'] ?? ''),
				(string) ($right['input_dir'] ?? ''),
			];
		});

		$groups = [];
		$files_processed = 0;
		$conflicts = 0;
		$inserted = 0;
		$updated = 0;
		$imported = 0;
		$skipped = 0;
		$deleted = 0;
		$has_errors = false;

		foreach ($targets as $target) {
			$group = self::auditTarget($target, $locales);
			$groups[] = $group;
			$files_processed += (int) ($group['files_processed'] ?? 0);
			$conflicts += (int) ($group['conflicts'] ?? 0);
			$inserted += (int) ($group['inserted'] ?? 0);
			$updated += (int) ($group['updated'] ?? 0);
			$imported += (int) ($group['imported'] ?? 0);
			$skipped += (int) ($group['skipped'] ?? 0);
			$deleted += (int) ($group['deleted'] ?? 0);
			$has_errors = $has_errors || (bool) ($group['has_errors'] ?? false);
		}

		return [
			'dry_run' => true,
			'mode' => CsvImportMode::Upsert->value,
			'build_requested' => false,
			'build_ran' => false,
			'built_locales' => [],
			'locales_filter' => $locales,
			'groups_processed' => count($groups),
			'files_processed' => $files_processed,
			'conflicts' => $conflicts,
			'inserted' => $inserted,
			'updated' => $updated,
			'imported' => $imported,
			'skipped' => $skipped,
			'deleted' => $deleted,
			'has_errors' => $has_errors,
			'groups' => $groups,
		];
	}

	/**
	 * @param array<string, mixed> $target
	 * @param list<string> $locales
	 * @return array<string, mixed>
	 */
	private static function auditTarget(array $target, array $locales): array
	{
		$input_dir = rtrim((string) ($target['input_dir'] ?? ''), '/');

		if ($input_dir === '' || !is_dir($input_dir)) {
			return [
				...$target,
				'status' => 'missing',
				'locales' => [],
				'files_processed' => 0,
				'conflicts' => 0,
				'inserted' => 0,
				'updated' => 0,
				'imported' => 0,
				'skipped' => 0,
				'deleted' => 0,
				'has_errors' => false,
				'files' => [],
				'errors' => [],
			];
		}

		$files = [];
		$conflicts = 0;
		$inserted = 0;
		$updated = 0;
		$imported = 0;
		$skipped = 0;
		$deleted = 0;
		$has_errors = false;
		$processed_locales = [];

		foreach ($locales as $locale) {
			$file = self::auditFile($input_dir . '/' . $locale . '.csv', $locale);
			$files[] = $file;
			$processed_locales[] = $locale;
			$conflicts += (int) ($file['conflicts'] ?? 0);
			$inserted += (int) ($file['inserted'] ?? 0);
			$updated += (int) ($file['updated'] ?? 0);
			$imported += (int) ($file['imported'] ?? 0);
			$skipped += (int) ($file['skipped'] ?? 0);
			$deleted += (int) ($file['deleted'] ?? 0);
			$has_errors = $has_errors || !empty($file['errors']);
		}

		return [
			...$target,
			'status' => $has_errors ? 'error' : ($files !== [] ? 'ok' : 'empty'),
			'locales' => $processed_locales,
			'files_processed' => count($files),
			'conflicts' => $conflicts,
			'inserted' => $inserted,
			'updated' => $updated,
			'imported' => $imported,
			'skipped' => $skipped,
			'deleted' => $deleted,
			'has_errors' => $has_errors,
			'files' => $files,
			'errors' => [],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function auditFile(string $file_path, string $expected_locale): array
	{
		if (!file_exists($file_path)) {
			return self::emptyFileResult($expected_locale, $file_path, ["Missing seed file: {$file_path}"]);
		}

		$csv = file_get_contents($file_path);

		if ($csv === false) {
			return self::emptyFileResult($expected_locale, $file_path, ["Unable to read seed file: {$file_path}"]);
		}

		try {
			$rows = self::parseSeedRows($csv, $expected_locale);
		} catch (InvalidArgumentException $exception) {
			return self::emptyFileResult(
				$expected_locale,
				$file_path,
				array_values(array_filter(explode("\n", $exception->getMessage())))
			);
		}

		return self::compareSeedRowsToDatabase($rows, $expected_locale, $file_path);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function emptyFileResult(string $locale, string $file_path, array $errors): array
	{
		return [
			'locale' => $locale,
			'file' => $file_path,
			'processed' => 0,
			'conflicts' => 0,
			'inserted' => 0,
			'updated' => 0,
			'imported' => 0,
			'skipped' => 0,
			'deleted' => 0,
			'errors' => $errors,
		];
	}

	/**
	 * @return list<array<string, string|int>>
	 */
	private static function parseSeedRows(string $csv, string $expected_locale): array
	{
		if (class_exists('I18nTranslationsWideCsv') && I18nTranslationsWideCsv::detectFormat($csv) === 'wide') {
			$csv = I18nTranslationsWideCsv::toNormalizedCsv($csv);
		}

		$csv = ltrim($csv, "\xEF\xBB\xBF");
		$handle = fopen('php://temp', 'r+');

		if ($handle === false) {
			throw new InvalidArgumentException('Unable to allocate CSV parser buffer');
		}

		fwrite($handle, $csv);
		rewind($handle);
		$headers = fgetcsv($handle, 0, ',', '"', '');

		if ($headers === false) {
			fclose($handle);

			throw new InvalidArgumentException('CSV is empty or unreadable');
		}

		$headers = CsvHelper::normalizeHeaderRow(array_map('strval', $headers));
		$map = new I18nTranslationsCsvMap();
		$errors = CsvHelper::validateHeaders($headers, $map);
		$definitions = $map->getColumnDefinitions();
		$rows = [];
		$line_number = 1;

		if ($errors !== []) {
			fclose($handle);

			throw new InvalidArgumentException(implode("\n", $errors));
		}

		while (($raw_row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
			$line_number++;

			if (CsvHelper::isIgnorableRawRow($raw_row)) {
				continue;
			}

			$row = [];

			foreach ($headers as $index => $column) {
				$row[$column] = (string) ($raw_row[$index] ?? '');
			}

			foreach ($definitions as $column => $definition) {
				if (!isset($row[$column]) && array_key_exists('default', $definition)) {
					$row[$column] = (string) $definition['default'];
				}
			}

			$raw_locale = trim((string) ($row['locale'] ?? ''));
			$locale = LocaleService::tryCanonicalize($raw_locale);

			if ($locale === null || !LocaleRegistry::isKnownLocale($locale)) {
				$errors[] = "Line {$line_number}: locale '{$raw_locale}' is not a supported standard locale";

				continue;
			}

			if ($locale !== $expected_locale) {
				$errors[] = "Line {$line_number}: expected locale '{$expected_locale}', found '{$locale}'";

				continue;
			}

			$row['locale'] = $locale;
			$row['line'] = $line_number;
			$rows[] = $row;
		}

		fclose($handle);

		if ($errors !== []) {
			throw new InvalidArgumentException(implode("\n", $errors));
		}

		return $rows;
	}

	/**
	 * @param list<array<string, string|int>> $rows
	 * @return array<string, mixed>
	 */
	private static function compareSeedRowsToDatabase(array $rows, string $locale, string $file_path): array
	{
		$existing_translations = self::loadExistingTranslations($rows);
		$existing_messages = self::loadExistingMessages($rows);
		$processed = 0;
		$conflicts = 0;
		$inserted = 0;
		$updated = 0;
		$imported = 0;
		$skipped = 0;
		$errors = [];

		foreach ($rows as $row) {
			$processed++;
			$line = (int) ($row['line'] ?? 0);
			$domain = trim((string) ($row['domain'] ?? ''));
			$key = trim((string) ($row['key'] ?? ''));
			$context = trim((string) ($row['context'] ?? ''));
			$text = (string) ($row['text'] ?? '');
			$expected_text = (string) ($row['expected_text'] ?? '');

			if ($domain === '' || $key === '' || $locale === '') {
				$errors[] = "Line {$line}: domain, key and locale are required";
				$skipped++;

				continue;
			}

			if (trim($text) === '') {
				$skipped++;

				continue;
			}

			$message_key = self::buildMessageKey($domain, $key, $context);
			$message = $existing_messages[$message_key] ?? null;

			if ($message === null) {
				$source_text = trim((string) ($row['source_text'] ?? ''));

				if ($source_text === '') {
					$errors[] = "Line {$line}: Source message not found for domain='{$domain}', key='{$key}', context='{$context}'";
					$skipped++;

					continue;
				}

				$source_hash = md5($source_text);
			} else {
				$source_hash = (string) ($message['source_hash'] ?? '');
			}

			$natural_key = self::buildTranslationKey($domain, $key, $context, $locale);
			$existing = $existing_translations[$natural_key] ?? null;
			$existing_human_reviewed = self::normalizeHumanReviewed($existing['human_reviewed'] ?? 0);

			if (
				$existing !== null
				&& $expected_text !== ''
				&& (string) ($existing['text'] ?? '') !== $expected_text
				&& $existing_human_reviewed
			) {
				$conflicts++;
				$skipped++;

				continue;
			}

			$target_human_reviewed = self::resolveImportedHumanReviewed($existing, $row['human_reviewed'] ?? '');

			if ($existing === null) {
				$inserted++;
				$imported++;

				continue;
			}

			$unchanged = (string) ($existing['text'] ?? '') === $text
				&& $existing_human_reviewed === $target_human_reviewed
				&& (string) ($existing['source_hash_snapshot'] ?? '') === $source_hash;

			if ($unchanged) {
				$skipped++;

				continue;
			}

			$updated++;
			$imported++;
		}

		return [
			'locale' => $locale,
			'file' => $file_path,
			'processed' => $processed,
			'conflicts' => $conflicts,
			'inserted' => $inserted,
			'updated' => $updated,
			'imported' => $imported,
			'skipped' => $skipped,
			'deleted' => 0,
			'errors' => $errors,
		];
	}

	/**
	 * @param list<array<string, string|int>> $rows
	 * @return array<string, array<string, mixed>>
	 */
	private static function loadExistingTranslations(array $rows): array
	{
		$domains = self::extractDomains($rows);
		$locales = self::extractLocales($rows);

		if ($domains === [] || $locales === []) {
			return [];
		}

		$domain_placeholders = implode(', ', array_fill(0, count($domains), '?'));
		$locale_placeholders = implode(', ', array_fill(0, count($locales), '?'));
		$stmt = Db::instance()->prepare(
			"SELECT domain, `key`, context, locale, `text`, human_reviewed, source_hash_snapshot
			FROM i18n_translations
			WHERE domain IN ({$domain_placeholders})
				AND locale IN ({$locale_placeholders})"
		);
		$stmt->execute([...$domains, ...$locales]);
		$existing = [];

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$existing[self::buildTranslationKey(
				(string) ($row['domain'] ?? ''),
				(string) ($row['key'] ?? ''),
				(string) ($row['context'] ?? ''),
				(string) ($row['locale'] ?? '')
			)] = $row;
		}

		return $existing;
	}

	/**
	 * @param list<array<string, string|int>> $rows
	 * @return array<string, array<string, mixed>>
	 */
	private static function loadExistingMessages(array $rows): array
	{
		$domains = self::extractDomains($rows);

		if ($domains === []) {
			return [];
		}

		$placeholders = implode(', ', array_fill(0, count($domains), '?'));
		$stmt = Db::instance()->prepare(
			"SELECT domain, `key`, context, source_hash
			FROM i18n_messages
			WHERE domain IN ({$placeholders})"
		);
		$stmt->execute($domains);
		$existing = [];

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$existing[self::buildMessageKey(
				(string) ($row['domain'] ?? ''),
				(string) ($row['key'] ?? ''),
				(string) ($row['context'] ?? '')
			)] = $row;
		}

		return $existing;
	}

	/**
	 * @param list<array<string, string|int>> $rows
	 * @return list<string>
	 */
	private static function extractDomains(array $rows): array
	{
		$domains = [];

		foreach ($rows as $row) {
			$domain = trim((string) ($row['domain'] ?? ''));

			if ($domain !== '') {
				$domains[$domain] = true;
			}
		}

		$domains = array_keys($domains);
		sort($domains);

		return $domains;
	}

	/**
	 * @param list<array<string, string|int>> $rows
	 * @return list<string>
	 */
	private static function extractLocales(array $rows): array
	{
		$locales = [];

		foreach ($rows as $row) {
			$locale = trim((string) ($row['locale'] ?? ''));

			if ($locale !== '') {
				$locales[$locale] = true;
			}
		}

		$locales = array_keys($locales);
		sort($locales);

		return $locales;
	}

	/**
	 * @param list<array<string, mixed>> $targets
	 * @return list<array<string, mixed>>
	 */
	private static function uniqueTargets(array $targets): array
	{
		$unique = [];
		$seen = [];

		foreach ($targets as $target) {
			$input_dir = rtrim(str_replace('\\', '/', (string) ($target['input_dir'] ?? '')), '/');
			$real = $input_dir !== '' ? realpath($input_dir) : false;
			$key = $real !== false ? rtrim(str_replace('\\', '/', $real), '/') : $input_dir;

			if ($key === '' || isset($seen[$key])) {
				continue;
			}

			$seen[$key] = true;
			$target['input_dir'] = $key;
			$unique[] = $target;
		}

		return $unique;
	}

	/**
	 * @param array<string, mixed>|null $existing
	 */
	private static function resolveImportedHumanReviewed(?array $existing, mixed $requested_human_reviewed): bool
	{
		$existing_human_reviewed = $existing !== null && self::normalizeHumanReviewed($existing['human_reviewed'] ?? 0);
		$requested = self::normalizeImportedHumanReviewed($requested_human_reviewed);

		if ($requested === null) {
			return $existing_human_reviewed;
		}

		if ($existing_human_reviewed && $requested === false) {
			return true;
		}

		return $requested;
	}

	private static function normalizeHumanReviewed(mixed $value): bool
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

	private static function normalizeImportedHumanReviewed(mixed $value): ?bool
	{
		$value = trim((string) $value);

		if ($value === '') {
			return null;
		}

		return self::normalizeHumanReviewed($value);
	}

	private static function buildMessageKey(string $domain, string $key, string $context): string
	{
		return $domain . "\0" . $key . "\0" . $context;
	}

	private static function buildTranslationKey(string $domain, string $key, string $context, string $locale): string
	{
		return self::buildMessageKey($domain, $key, $context) . "\0" . $locale;
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
			$locale = LocaleService::tryCanonicalize((string) $locale) ?? '';

			if ($locale === '') {
				continue;
			}

			if (!LocaleRegistry::isKnownLocale($locale)) {
				continue;
			}

			$normalized[$locale] = true;
		}

		$locales = array_keys($normalized);
		sort($locales);

		return $locales;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return list<array<string, mixed>>
	 */
	private static function extractIssues(array $result): array
	{
		$issues = [];

		foreach (($result['groups'] ?? []) as $group) {
			if (!is_array($group)) {
				continue;
			}

			foreach (($group['files'] ?? []) as $file) {
				if (!is_array($file)) {
					continue;
				}

				$inserted = (int) ($file['inserted'] ?? 0);
				$updated = (int) ($file['updated'] ?? 0);
				$conflicts = (int) ($file['conflicts'] ?? 0);
				$errors = array_values(array_filter(
					(array) ($file['errors'] ?? []),
					static fn (mixed $error): bool => trim((string) $error) !== ''
				));

				if ($inserted <= 0 && $updated <= 0 && $conflicts <= 0 && $errors === []) {
					continue;
				}

				$issues[] = [
					'code' => $errors === []
						? ($inserted > 0 || $updated > 0 ? 'shipped_i18n_database_out_of_sync' : 'shipped_i18n_database_customized')
						: 'shipped_i18n_seed_import_error',
					'group_type' => (string) ($group['group_type'] ?? ''),
					'group_id' => (string) ($group['group_id'] ?? ''),
					'locale' => (string) ($file['locale'] ?? ''),
					'file' => (string) ($file['file'] ?? ''),
					'processed' => (int) ($file['processed'] ?? 0),
					'missing_rows' => $inserted,
					'changed_rows' => $updated,
					'customized_rows' => $conflicts,
					'conflicts' => $conflicts,
					'errors' => $errors,
				];
			}
		}

		return $issues;
	}

	/**
	 * @param list<array<string, mixed>> $issues
	 * @return list<string>
	 */
	private static function extractSyncLocales(array $issues): array
	{
		$locales = [];

		foreach ($issues as $issue) {
			$code = (string) ($issue['code'] ?? '');

			if (!in_array($code, ['shipped_i18n_database_out_of_sync', 'shipped_i18n_seed_import_error'], true)) {
				continue;
			}

			$locale = LocaleService::tryCanonicalize((string) ($issue['locale'] ?? ''));

			if ($locale !== null) {
				$locales[$locale] = true;
			}
		}

		$locales = array_keys($locales);
		sort($locales);

		return $locales;
	}

	/**
	 * @param list<string> $locales
	 */
	private static function buildSuggestedCommand(array $locales): string
	{
		if ($locales === []) {
			return '';
		}

		$command = 'radaptor i18n:sync-shipped';
		$command .= ' --locale ' . implode(',', $locales);

		return $command;
	}
}
