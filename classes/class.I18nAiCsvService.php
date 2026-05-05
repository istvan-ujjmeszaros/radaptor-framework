<?php

declare(strict_types=1);

class I18nAiCsvService
{
	private const array NORMALIZED_HEADER = [
		'domain',
		'key',
		'context',
		'locale',
		'source_text',
		'expected_text',
		'human_reviewed',
		'text',
	];

	/**
	 * @param array{
	 *     domain?: string,
	 *     key_prefix?: string,
	 *     missing_only?: bool,
	 *     unreviewed_only?: bool
	 * } $options
	 */
	public static function exportForLocale(string $locale, array $options = []): string
	{
		if (!LocaleRegistry::isKnownLocale($locale)) {
			throw new RuntimeException("Unknown locale: {$locale}");
		}

		$domain = trim((string) ($options['domain'] ?? ''));
		$key_prefix = trim((string) ($options['key_prefix'] ?? ''));
		$missing_only = (bool) ($options['missing_only'] ?? false);
		$unreviewed_only = (bool) ($options['unreviewed_only'] ?? false);
		$where = [];
		$params = [
			':export_locale' => $locale,
			':join_locale' => $locale,
		];

		if ($domain !== '') {
			$where[] = 'm.domain = :domain';
			$params[':domain'] = $domain;
		}

		if ($key_prefix !== '') {
			$where[] = 'm.`key` LIKE :key_prefix';
			$params[':key_prefix'] = $key_prefix . '%';
		}

		if ($missing_only) {
			$where[] = "(t.`key` IS NULL OR TRIM(COALESCE(t.text, '')) = '')";
		}

		if ($unreviewed_only) {
			$where[] = "(t.`key` IS NULL OR t.human_reviewed <> 1)";
		}

		$where_sql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
		$stmt = Db::instance()->prepare(
			"SELECT
				m.domain,
				m.`key`,
				m.context,
				:export_locale AS locale,
				m.source_text,
				COALESCE(t.text, '') AS expected_text,
				'0' AS human_reviewed,
				COALESCE(t.text, '') AS text
			FROM i18n_messages m
			LEFT JOIN i18n_translations t
				ON t.domain = m.domain
				AND t.`key` = m.`key`
				AND t.context = m.context
				AND t.locale = :join_locale
			{$where_sql}
			ORDER BY m.domain, m.`key`, m.context"
		);
		$stmt->execute($params);
		$handle = fopen('php://temp', 'r+');

		if ($handle === false) {
			throw new RuntimeException('Unable to open temporary CSV stream.');
		}

		fwrite($handle, "\xEF\xBB\xBF");
		fputcsv($handle, self::NORMALIZED_HEADER, ',', '"', '');

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			fputcsv($handle, array_map(
				static fn (string $column): string => (string) ($row[$column] ?? ''),
				self::NORMALIZED_HEADER
			), ',', '"', '');
		}

		rewind($handle);
		$csv = stream_get_contents($handle);
		fclose($handle);

		if ($csv === false) {
			throw new RuntimeException('Unable to read generated CSV stream.');
		}

		return $csv;
	}

	/**
	 * @param array{expect_locale?: string, dry_run?: bool} $options
	 * @return array<string, mixed>
	 */
	public static function importCsv(string $csv_content, array $options = []): array
	{
		$expected_locale = trim((string) ($options['expect_locale'] ?? ''));
		$dry_run = (bool) ($options['dry_run'] ?? false);
		$validation = self::validateCsv($csv_content, $expected_locale);

		if ($validation['errors'] !== []) {
			return [
				'status' => 'error',
				'dry_run' => $dry_run,
				'imported' => 0,
				'updated' => 0,
				'inserted' => 0,
				'skipped' => 0,
				'errors' => $validation['errors'],
				'warnings' => $validation['warnings'],
			];
		}

		$dataset = new ImportExportDatasetI18nTranslations();
		$result = $dataset->import(self::normalizeAiImportCsv($csv_content), [
			'format' => 'normalized',
			'mode' => CsvImportMode::Upsert->value,
			'expect_locale' => $expected_locale,
			'dry_run' => $dry_run ? '1' : '0',
		]);
		$conflicts = array_values(array_filter(
			$result['row_results'] ?? [],
			static fn (array $row): bool => ($row['reason'] ?? '') === 'expected_mismatch'
		));

		if ($conflicts !== []) {
			$result['errors'] = [
				...($result['errors'] ?? []),
				'Import skipped ' . count($conflicts) . ' row(s) because expected_text no longer matched the database.',
			];
		}

		$review_reset = 0;

		if (!$dry_run && empty($result['errors']) && $validation['review_reset_rows'] !== []) {
			$review_reset = self::resetChangedRowsForReview($validation['review_reset_rows']);
		}

		return [
			'status' => empty($result['errors']) ? 'success' : 'error',
			...$result,
			'dry_run' => $dry_run,
			'warnings' => $validation['warnings'],
			'ai_review_reset' => $dry_run ? count($validation['review_reset_rows']) : $review_reset,
		];
	}

	/**
	 * @return array{
	 *     errors: list<string>,
	 *     warnings: list<string>,
	 *     rows: int,
	 *     locales: list<string>,
	 *     review_reset_rows: list<array{domain: string, key: string, context: string, locale: string, text: string}>
	 * }
	 */
	private static function validateCsv(string $csv_content, string $expected_locale): array
	{
		$csv_content = ltrim($csv_content, "\xEF\xBB\xBF");
		$handle = fopen('php://temp', 'r+');

		if ($handle === false) {
			return [
				'errors' => ['Unable to open temporary CSV stream.'],
				'warnings' => [],
				'rows' => 0,
				'locales' => [],
				'review_reset_rows' => [],
			];
		}

		fwrite($handle, $csv_content);
		rewind($handle);
		$header = fgetcsv($handle, 0, ',', '"', '');

		if ($header === false) {
			fclose($handle);

			return [
				'errors' => ['CSV is empty.'],
				'warnings' => [],
				'rows' => 0,
				'locales' => [],
				'review_reset_rows' => [],
			];
		}

		$header = array_map(
			static fn (mixed $column): string => trim((string) $column, "\xEF\xBB\xBF \t\n\r\0\x0B"),
			$header
		);
		$errors = [];
		$warnings = [];

		if ($header !== self::NORMALIZED_HEADER) {
			$errors[] = 'CSV header must be: ' . implode(',', self::NORMALIZED_HEADER);
			fclose($handle);

			return [
				'errors' => $errors,
				'warnings' => $warnings,
				'rows' => 0,
				'locales' => [],
				'review_reset_rows' => [],
			];
		}

		$indexes = array_flip($header);
		$line = 1;
		$row_count = 0;
		$locales = [];
		$review_reset_rows = [];

		while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
			$line++;

			if (self::isIgnorableRow($row)) {
				continue;
			}

			$row_count++;

			if (count($row) !== count($header)) {
				$errors[] = "Line {$line}: CSV row column count does not match the header.";

				continue;
			}

			$data = [];

			foreach ($indexes as $column => $index) {
				$data[$column] = (string) ($row[$index] ?? '');
			}

			$locale = trim($data['locale'] ?? '');
			$locales[$locale] = true;

			if ($expected_locale !== '' && $locale !== $expected_locale) {
				$errors[] = "Line {$line}: expected locale {$expected_locale}, found {$locale}.";
			}

			if ($locale !== '' && !LocaleRegistry::isKnownLocale($locale)) {
				$errors[] = "Line {$line}: unknown locale {$locale}.";
			}

			foreach (['domain', 'key', 'locale', 'source_text'] as $required) {
				if (trim($data[$required] ?? '') === '') {
					$errors[] = "Line {$line}: {$required} is required.";
				}
			}

			$current = self::loadCurrentRow(
				trim($data['domain'] ?? ''),
				trim($data['key'] ?? ''),
				trim($data['context'] ?? ''),
				$locale
			);

			if ($current === null) {
				$errors[] = "Line {$line}: source message does not exist for {$data['domain']}.{$data['key']}.";

				continue;
			}

			if ((string) ($current['source_text'] ?? '') !== (string) ($data['source_text'] ?? '')) {
				$errors[] = "Line {$line}: source_text no longer matches the database source text.";
			}

			if (($current['locale'] ?? null) !== null) {
				$current_text = (string) ($current['translation_text'] ?? '');
				$expected_text = (string) ($data['expected_text'] ?? '');
				$incoming_text = (string) ($data['text'] ?? '');
				$is_human_reviewed = ((int) ($current['human_reviewed'] ?? 0)) === 1;

				if ($expected_text !== '' && $current_text !== $expected_text) {
					$errors[] = "Line {$line}: expected_text no longer matches the current database translation.";
				}

				if ($is_human_reviewed && $expected_text === '' && $incoming_text !== $current_text) {
					$errors[] = "Line {$line}: human-reviewed translation updates require expected_text.";
				}

				if ($incoming_text !== '' && $incoming_text !== $current_text) {
					$review_reset_rows[] = [
						'domain' => trim($data['domain'] ?? ''),
						'key' => trim($data['key'] ?? ''),
						'context' => trim($data['context'] ?? ''),
						'locale' => $locale,
						'text' => $incoming_text,
					];
				}
			}

			if (trim((string) ($data['text'] ?? '')) === '') {
				$warnings[] = "Line {$line}: text is empty and will be skipped by upsert.";
			}
		}

		fclose($handle);
		$locales = array_values(array_filter(array_keys($locales), static fn (string $locale): bool => $locale !== ''));
		sort($locales);

		if ($row_count === 0) {
			$errors[] = 'CSV contains no data rows.';
		}

		return [
			'errors' => array_values(array_unique($errors)),
			'warnings' => array_values(array_unique($warnings)),
			'rows' => $row_count,
			'locales' => $locales,
			'review_reset_rows' => $review_reset_rows,
		];
	}

	private static function normalizeAiImportCsv(string $csv_content): string
	{
		$csv_content = ltrim($csv_content, "\xEF\xBB\xBF");
		$input = fopen('php://temp', 'r+');
		$output = fopen('php://temp', 'r+');

		if ($input === false || $output === false) {
			throw new RuntimeException('Unable to open temporary CSV stream.');
		}

		fwrite($input, $csv_content);
		rewind($input);
		fwrite($output, "\xEF\xBB\xBF");

		$header = fgetcsv($input, 0, ',', '"', '');

		if ($header === false) {
			fclose($input);
			fclose($output);

			throw new RuntimeException('CSV is empty.');
		}

		$review_index = array_search('human_reviewed', $header, true);

		fputcsv($output, $header, ',', '"', '');

		while (($row = fgetcsv($input, 0, ',', '"', '')) !== false) {
			if ($review_index !== false && !self::isIgnorableRow($row)) {
				$row[$review_index] = '0';
			}

			fputcsv($output, $row, ',', '"', '');
		}

		fclose($input);
		rewind($output);
		$csv = stream_get_contents($output);
		fclose($output);

		if ($csv === false) {
			throw new RuntimeException('Unable to read generated CSV stream.');
		}

		return $csv;
	}

	/**
	 * @param list<array{domain: string, key: string, context: string, locale: string, text: string}> $rows
	 */
	private static function resetChangedRowsForReview(array $rows): int
	{
		$pdo = Db::instance();
		$stmt = $pdo->prepare(
			'UPDATE i18n_translations
			SET human_reviewed = 0
			WHERE domain = :domain
				AND `key` = :key
				AND context = :context
				AND locale = :locale
				AND text = :text
				AND human_reviewed <> 0'
		);
		$count = 0;

		foreach ($rows as $row) {
			$stmt->execute([
				':domain' => $row['domain'],
				':key' => $row['key'],
				':context' => $row['context'],
				':locale' => $row['locale'],
				':text' => $row['text'],
			]);
			$count += $stmt->rowCount();
		}

		return $count;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function loadCurrentRow(string $domain, string $key, string $context, string $locale): ?array
	{
		if ($domain === '' || $key === '') {
			return null;
		}

		$stmt = Db::instance()->prepare(
			'SELECT
				m.domain,
				m.`key`,
				m.context,
				m.source_text,
				m.source_hash,
				t.locale,
				t.text AS translation_text,
				t.human_reviewed
			FROM i18n_messages m
			LEFT JOIN i18n_translations t
				ON t.domain = m.domain
				AND t.`key` = m.`key`
				AND t.context = m.context
				AND t.locale = :locale
			WHERE m.domain = :domain AND m.`key` = :key AND m.context = :context'
		);
		$stmt->execute([
			':domain' => $domain,
			':key' => $key,
			':context' => $context,
			':locale' => $locale,
		]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
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
}
