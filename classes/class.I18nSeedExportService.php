<?php

class I18nSeedExportService
{
	/**
	 * @param array{
	 *     locales?: list<string>,
	 *     domains?: list<string>,
	 *     key_prefixes?: list<string>,
	 *     dry_run?: bool,
	 *     clean?: bool
	 * } $options
	 * @return array{
	 *     output_dir: string,
	 *     dry_run: bool,
	 *     clean: bool,
	 *     locales: list<string>,
	 *     domains: list<string>,
	 *     key_prefixes: list<string>,
	 *     files_written: int,
	 *     rows_exported: int,
	 *     deleted_files: list<string>,
	 *     files: list<array<string, mixed>>
	 * }
	 */
	public static function exportDirectory(string $output_dir, array $options = []): array
	{
		$dataset = new ImportExportDatasetI18nTranslations();
		$dry_run = (bool) ($options['dry_run'] ?? false);
		$clean = (bool) ($options['clean'] ?? false);
		$requested_locales = self::normalizeLocales($options['locales'] ?? I18nRuntime::getAvailableLocaleCodes());
		$locales = $requested_locales !== []
			? $requested_locales
			: self::normalizeLocales(I18nRuntime::getAvailableLocaleCodes());
		$domains = self::normalizeStringList($options['domains'] ?? []);
		$key_prefixes = self::normalizeStringList($options['key_prefixes'] ?? []);
		$output_dir = rtrim($output_dir, '/');
		$files = [];
		$expected_paths = [];
		$rows_exported = 0;
		$files_written = 0;
		$deleted_files = [];

		if ($output_dir === '') {
			throw new RuntimeException('Output directory is required.');
		}

		if (!$dry_run && !is_dir($output_dir) && !mkdir($output_dir, 0o755, true) && !is_dir($output_dir)) {
			throw new RuntimeException("Unable to create output directory: {$output_dir}");
		}

		foreach ($locales as $locale) {
			$path = $output_dir . '/' . $locale . '.csv';

			/** @var array<string, mixed> $dataset_options */
			$dataset_options = [
				'format' => 'normalized',
				'locale' => $locale,
				'domains' => $domains,
				'key_prefixes' => $key_prefixes,
			];
			$csv = $dataset->export($dataset_options);
			$row_count = self::countCsvRows($csv);

			if ($row_count === 0) {
				$files[] = [
					'locale' => $locale,
					'path' => $path,
					'status' => 'empty',
					'rows' => 0,
					'written' => false,
				];

				continue;
			}

			$expected_paths[] = $path;
			$rows_exported += $row_count;

			if (!$dry_run && file_put_contents($path, $csv) === false) {
				throw new RuntimeException("Unable to write seed file: {$path}");
			}

			if (!$dry_run) {
				$files_written++;
			}

			$files[] = [
				'locale' => $locale,
				'path' => $path,
				'status' => $dry_run ? 'dry_run' : 'written',
				'rows' => $row_count,
				'written' => !$dry_run,
			];
		}

		if ($clean && is_dir($output_dir)) {
			$expected_lookup = array_fill_keys($expected_paths, true);
			$existing = glob($output_dir . '/*.csv') ?: [];
			sort($existing);

			foreach ($existing as $existing_file) {
				if (isset($expected_lookup[$existing_file])) {
					continue;
				}

				if (!$dry_run && !unlink($existing_file)) {
					throw new RuntimeException("Unable to delete stale seed file: {$existing_file}");
				}

				$deleted_files[] = $existing_file;
			}
		}

		return [
			'output_dir' => $output_dir,
			'dry_run' => $dry_run,
			'clean' => $clean,
			'locales' => $locales,
			'domains' => $domains,
			'key_prefixes' => $key_prefixes,
			'files_written' => $files_written,
			'rows_exported' => $rows_exported,
			'deleted_files' => $deleted_files,
			'files' => $files,
		];
	}

	/**
	 * @param mixed $locales
	 * @return list<string>
	 */
	private static function normalizeLocales(mixed $locales): array
	{
		$normalized = self::normalizeStringList($locales);

		foreach ($normalized as $locale) {
			if (!LocaleRegistry::isKnownLocale($locale)) {
				throw new RuntimeException("Unknown locale: {$locale}");
			}
		}

		return $normalized;
	}

	/**
	 * @param mixed $values
	 * @return list<string>
	 */
	private static function normalizeStringList(mixed $values): array
	{
		if (!is_array($values)) {
			return [];
		}

		$normalized = [];

		foreach ($values as $value) {
			$value = trim((string) $value);

			if ($value === '') {
				continue;
			}

			$normalized[$value] = true;
		}

		return array_keys($normalized);
	}

	private static function countCsvRows(string $csv): int
	{
		$csv = ltrim($csv, "\xEF\xBB\xBF");
		$handle = fopen('php://temp', 'r+');

		if ($handle === false) {
			return 0;
		}

		fwrite($handle, $csv);
		rewind($handle);
		fgetcsv($handle, 0, ',', '"', '\\');
		$count = 0;

		while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
			if (CsvHelper::isIgnorableRawRow($row)) {
				continue;
			}

			$count++;
		}

		fclose($handle);

		return $count;
	}
}
