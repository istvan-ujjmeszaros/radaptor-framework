<?php

/**
 * Import translations from CSV.
 *
 * Usage: radaptor i18n:import <file> [--format auto|normalized|wide] [--mode upsert|insert_new|sync] [--expect-locale hu_HU] [--dry-run]
 *
 * Modes:
 *   insert_new   Add keys that do not yet exist; skip existing translations.
 *                Safe for partial top-ups. Cannot overwrite existing rows.
 *
 *   upsert       (default) Insert new and update existing translations.
 *                Never deletes. Suitable for importing a translator's file.
 *
 *   sync         Upsert + delete translations whose domain/key/context/locale
 *                is absent from the CSV. Use this to clean up obsolete keys.
 *                NOTE: Nested-set tables (resource_tree, menus) require sync
 *                mode exclusively — partial imports corrupt lft/rgt values.
 *
 * --dry-run      Validate the file and report what would be imported/deleted
 *                without writing anything to the database.
 *
 * --expect-locale  Fail if the CSV locale column contains anything else.
 *
 * Mixed-locale files are allowed for insert_new and upsert.
 * Sync requires a single-locale file so deletion scope remains explicit.
 *
 * After a successful import, run `radaptor i18n:build` to rebuild catalogs.
 *
 * Examples:
 *   radaptor i18n:import hu_HU.csv
 *   radaptor i18n:import hu_HU.csv --mode insert_new
 *   radaptor i18n:import hu_HU.csv --mode sync
 *   radaptor i18n:import translations.csv --format wide --mode upsert
 *   radaptor i18n:import hu_HU.csv --format normalized --mode sync --expect-locale hu_HU
 *   radaptor i18n:import hu_HU.csv --dry-run
 */
class CLICommandI18nImport extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Import translations';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Import translations from CSV.

			Usage: radaptor i18n:import <file> [--mode upsert|insert_new|sync] [--expect-locale hu_HU] [--dry-run] [--json]

			Examples:
			  radaptor i18n:import hu_HU.csv
			  radaptor i18n:import hu_HU.csv --mode insert_new
			  radaptor i18n:import hu_HU.csv --mode sync --expect-locale hu_HU
			  radaptor i18n:import hu_HU.csv --dry-run
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function getWebTimeout(): int
	{
		return 60;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'file', 'label' => 'File path', 'type' => 'main_arg', 'required' => true],
			['name' => 'mode', 'label' => 'Import mode', 'type' => 'option'],
			['name' => 'expect-locale', 'label' => 'Expected locale', 'type' => 'option'],
			['name' => 'dry-run', 'label' => 'Dry run', 'type' => 'flag'],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$file   = Request::getMainArg();
		$dryRun = Request::hasArg('dry-run');

		if ($file === null || trim($file) === '') {
			Kernel::abort("Usage: radaptor i18n:import <file> [--format auto|normalized|wide] [--mode upsert|insert_new|sync] [--expect-locale hu_HU] [--dry-run]");
		}

		if (!file_exists($file)) {
			Kernel::abort("File not found: {$file}");
		}

		$modeValue = $this->_getCliOption('mode', '');
		$mode      = null;
		$format    = $this->_getCliOption('format', 'auto');

		if ($modeValue !== '') {
			$mode = CsvImportMode::tryFrom($modeValue);

			if ($mode === null) {
				$valid = implode(', ', array_map(fn ($m) => $m->value, CsvImportMode::cases()));
				Kernel::abort("Unknown mode '{$modeValue}'. Valid modes: {$valid}");
			}
		}

		$csv = file_get_contents($file);

		if ($csv === false) {
			Kernel::abort("Unable to read file: {$file}");
		}

		$expectedLocale = $this->_getCliOption('expect-locale', '');
		$dataset = new ImportExportDatasetI18nTranslations();

		try {
			$result = $dataset->import($csv, [
				'format' => $format,
				'mode' => $modeValue,
				'expect_locale' => $expectedLocale,
				'dry_run' => $dryRun ? '1' : '0',
			]);
		} catch (InvalidArgumentException $e) {
			foreach (explode("\n", $e->getMessage()) as $err) {
				if ($err !== '') {
					echo "ERROR: {$err}\n";
				}
			}

			exit(1);
		}

		if (!empty($result['errors'])) {
			foreach ($result['errors'] as $err) {
				echo "ERROR: {$err}\n";
			}

			if ($result['imported'] === 0) {
				exit(1);
			}
		}

		$prefix = $dryRun ? '[dry-run] ' : '';
		echo "{$prefix}Format: {$format}\n";
		echo "{$prefix}Mode: {$result['mode']}\n";
		$detectedLocales = $result['detected_locales'] ?? [];

		if (!empty($detectedLocales)) {
			if (count($detectedLocales) === 1) {
				echo "{$prefix}Locale: {$detectedLocales[0]}\n";
			} else {
				echo "{$prefix}Locales: " . implode(', ', $detectedLocales) . "\n";
			}
		}
		echo "{$prefix}Processed: {$result['processed']}\n";
		echo "{$prefix}Inserted: {$result['inserted']}\n";
		echo "{$prefix}Updated: {$result['updated']}\n";
		echo "{$prefix}Imported: {$result['imported']}\n";
		echo "{$prefix}Skipped: {$result['skipped']}\n";
		echo "{$prefix}Deleted: {$result['deleted']}\n";

		if ($dryRun) {
			echo "(dry-run — no changes written)\n";
		} elseif (empty($result['errors'])) {
			echo "Run `radaptor i18n:build` to rebuild the catalogs.\n";
		}
	}

	private function _getCliOption(string $name, string $default = ''): string
	{
		global $argv;

		foreach ($argv as $idx => $arg) {
			if ($arg === "--{$name}") {
				$value = $argv[$idx + 1] ?? null;

				return is_string($value) && !str_starts_with($value, '--') ? trim($value) : $default;
			}
		}

		$keyValue = Request::getArg($name);

		if (!is_null($keyValue) && trim($keyValue) !== '') {
			return trim($keyValue);
		}

		return $default;
	}
}
