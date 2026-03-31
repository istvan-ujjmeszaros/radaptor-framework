<?php

/**
 * Export DB-backed translations into versionable seed CSV files.
 *
 * Usage:
 *   radaptor i18n:seed-export <output-dir> [--locale en_US,hu_HU] [--domain cms,common] [--key-prefix widget.,form.] [--clean] [--dry-run] [--json]
 */
class CLICommandI18nSeedExport extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Export i18n seed files';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Export DB-backed translations into versionable seed CSV files.

			Usage: radaptor i18n:seed-export <output-dir> [--locale en_US,hu_HU] [--domain cms,common] [--key-prefix widget.,form.] [--clean] [--dry-run] [--json]

			Examples:
			  radaptor i18n:seed-export /app/tmp/seeds
			  radaptor i18n:seed-export /app/tmp/seeds --locale hu_HU --clean
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getRiskLevel(): string
	{
		return 'build';
	}

	public function run(): void
	{
		$output_dir = Request::getMainArg();

		if ($output_dir === null || trim($output_dir) === '') {
			Kernel::abort('Usage: radaptor i18n:seed-export <output-dir> [--locale en_US,hu_HU] [--domain cms,common] [--key-prefix widget.,form.] [--clean] [--dry-run] [--json]');
		}

		$dry_run = Request::hasArg('dry-run');
		$clean = Request::hasArg('clean');
		$json = Request::hasArg('json');

		try {
			$result = I18nSeedExportService::exportDirectory($output_dir, [
				'locales' => $this->getCsvListOption('locale'),
				'domains' => $this->getCsvListOption('domain'),
				'key_prefixes' => $this->getCsvListOption('key-prefix'),
				'dry_run' => $dry_run,
				'clean' => $clean,
			]);
		} catch (Throwable $e) {
			if ($json) {
				echo json_encode([
					'status' => 'error',
					'message' => $e->getMessage(),
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

				return;
			}

			echo "Seed export failed: {$e->getMessage()}\n";

			return;
		}

		if ($json) {
			echo json_encode([
				'status' => 'success',
				...$result,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		$prefix = $dry_run ? '[dry-run] ' : '';
		echo "{$prefix}Output dir: {$result['output_dir']}\n";
		echo "{$prefix}Rows exported: {$result['rows_exported']}\n";
		echo "{$prefix}Files written: {$result['files_written']}\n";

		if (!empty($result['deleted_files'])) {
			echo "{$prefix}Deleted stale files: " . count($result['deleted_files']) . "\n";
		}

		foreach ($result['files'] as $file) {
			echo "{$prefix}- {$file['locale']}: {$file['status']} ({$file['rows']} rows) → {$file['path']}\n";
		}
	}

	/**
	 * @return list<string>
	 */
	private function getCsvListOption(string $name): array
	{
		global $argv;

		$values = [];

		foreach ($argv as $idx => $arg) {
			if ($arg === "--{$name}") {
				$value = $argv[$idx + 1] ?? null;

				if (is_string($value) && !str_starts_with($value, '--')) {
					$values = [...$values, ...explode(',', $value)];
				}
			}
		}

		$key_value = Request::getArg($name);

		if (is_string($key_value) && trim($key_value) !== '') {
			$values = [...$values, ...explode(',', $key_value)];
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
}
