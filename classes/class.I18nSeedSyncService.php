<?php

class I18nSeedSyncService
{
	/**
	 * @param array{
	 *     locales?: list<string>,
	 *     mode?: string,
	 *     dry_run?: bool
	 * } $options
	 * @return array{
	 *     input_dir: string,
	 *     dry_run: bool,
	 *     mode: string,
	 *     locales: list<string>,
	 *     files_processed: int,
	 *     conflicts: int,
	 *     inserted: int,
	 *     updated: int,
	 *     imported: int,
	 *     skipped: int,
	 *     deleted: int,
	 *     has_errors: bool,
	 *     files: list<array<string, mixed>>
	 * }
	 */
	public static function syncDirectory(string $input_dir, array $options = []): array
	{
		$input_dir = rtrim($input_dir, '/');
		$dry_run = (bool) ($options['dry_run'] ?? false);
		$mode = self::normalizeMode($options['mode'] ?? CsvImportMode::Upsert->value);
		$requested_locales = self::normalizeLocales($options['locales'] ?? []);
		$locales = $requested_locales !== []
			? $requested_locales
			: self::discoverLocales($input_dir);
		$dataset = new ImportExportDatasetI18nTranslations();
		$files = [];
		$conflicts = 0;
		$inserted = 0;
		$updated = 0;
		$imported = 0;
		$skipped = 0;
		$deleted = 0;
		$has_errors = false;

		if (!is_dir($input_dir)) {
			throw new RuntimeException("Input directory not found: {$input_dir}");
		}

		foreach ($locales as $locale) {
			$file_path = $input_dir . '/' . $locale . '.csv';

			if (!file_exists($file_path)) {
				$files[] = [
					'locale' => $locale,
					'file' => $file_path,
					'processed' => 0,
					'conflicts' => 0,
					'inserted' => 0,
					'updated' => 0,
					'imported' => 0,
					'skipped' => 0,
					'deleted' => 0,
					'errors' => ["Missing seed file: {$file_path}"],
				];
				$has_errors = true;

				continue;
			}

			$csv = file_get_contents($file_path);

			if ($csv === false) {
				$files[] = [
					'locale' => $locale,
					'file' => $file_path,
					'processed' => 0,
					'conflicts' => 0,
					'inserted' => 0,
					'updated' => 0,
					'imported' => 0,
					'skipped' => 0,
					'deleted' => 0,
					'errors' => ["Unable to read seed file: {$file_path}"],
				];
				$has_errors = true;

				continue;
			}

			try {
				$result = $dataset->import($csv, [
					'format' => 'auto',
					'mode' => $mode->value,
					'expect_locale' => $locale,
					'dry_run' => $dry_run ? '1' : '0',
				]);
			} catch (InvalidArgumentException $e) {
				$files[] = [
					'locale' => $locale,
					'file' => $file_path,
					'processed' => 0,
					'conflicts' => 0,
					'inserted' => 0,
					'updated' => 0,
					'imported' => 0,
					'skipped' => 0,
					'deleted' => 0,
					'errors' => array_values(array_filter(explode("\n", $e->getMessage()))),
				];
				$has_errors = true;

				continue;
			}

			$file_conflicts = count(array_filter(
				$result['row_results'],
				static fn (array $row): bool => ($row['reason'] ?? '') === 'expected_mismatch'
			));

			$files[] = [
				'locale' => $locale,
				'file' => $file_path,
				'processed' => (int) $result['processed'],
				'conflicts' => $file_conflicts,
				'inserted' => (int) $result['inserted'],
				'updated' => (int) $result['updated'],
				'imported' => (int) $result['imported'],
				'skipped' => (int) $result['skipped'],
				'deleted' => (int) $result['deleted'],
				'errors' => $result['errors'],
			];
			$conflicts += $file_conflicts;
			$inserted += (int) $result['inserted'];
			$updated += (int) $result['updated'];
			$imported += (int) $result['imported'];
			$skipped += (int) $result['skipped'];
			$deleted += (int) $result['deleted'];
			$has_errors = $has_errors || !empty($result['errors']);
		}

		return [
			'input_dir' => $input_dir,
			'dry_run' => $dry_run,
			'mode' => $mode->value,
			'locales' => $locales,
			'files_processed' => count($files),
			'conflicts' => $conflicts,
			'inserted' => $inserted,
			'updated' => $updated,
			'imported' => $imported,
			'skipped' => $skipped,
			'deleted' => $deleted,
			'has_errors' => $has_errors,
			'files' => $files,
		];
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

		return array_keys($normalized);
	}

	/**
	 * @return list<string>
	 */
	private static function discoverLocales(string $input_dir): array
	{
		$files = glob($input_dir . '/*.csv') ?: [];
		sort($files);
		$locales = [];

		foreach ($files as $file) {
			$locale = basename($file, '.csv');

			if (!LocaleRegistry::isKnownLocale($locale)) {
				throw new RuntimeException("Seed filename does not map to a supported locale: {$file}");
			}

			$locales[] = $locale;
		}

		return $locales;
	}

	private static function normalizeMode(mixed $mode): CsvImportMode
	{
		$mode_value = trim((string) $mode);

		if ($mode_value === '') {
			$mode_value = CsvImportMode::Upsert->value;
		}

		$mode = CsvImportMode::tryFrom($mode_value);

		if ($mode === null) {
			$valid = implode(', ', array_map(static fn (CsvImportMode $candidate): string => $candidate->value, CsvImportMode::cases()));

			throw new RuntimeException("Unknown mode '{$mode_value}'. Valid modes: {$valid}");
		}

		return $mode;
	}
}
