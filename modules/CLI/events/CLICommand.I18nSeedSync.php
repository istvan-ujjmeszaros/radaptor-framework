<?php

/**
 * Sync per-locale seed CSV files from a directory back into the DB.
 *
 * Usage:
 *   radaptor i18n:seed-sync <input-dir> [--locale en_US,hu_HU] [--mode upsert|insert_new|sync] [--dry-run] [--json]
 */
class CLICommandI18nSeedSync extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Sync i18n seed files';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Sync per-locale seed CSV files from a directory back into the DB.

			Usage: radaptor i18n:seed-sync <input-dir> [--locale en_US,hu_HU] [--mode upsert|insert_new|sync] [--dry-run] [--json]

			Examples:
			  radaptor i18n:seed-sync /app/tmp/seeds
			  radaptor i18n:seed-sync /app/tmp/seeds --locale hu_HU --mode sync
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

	public function run(): void
	{
		$input_dir = Request::getMainArg();

		if ($input_dir === null || trim($input_dir) === '') {
			Kernel::abort('Usage: radaptor i18n:seed-sync <input-dir> [--locale en_US,hu_HU] [--mode upsert|insert_new|sync] [--dry-run] [--json]');
		}

		$dry_run = Request::hasArg('dry-run');
		$json = Request::hasArg('json');

		try {
			$result = I18nSeedSyncService::syncDirectory($input_dir, [
				'locales' => $this->getCsvListOption('locale'),
				'mode' => $this->getSingleOption('mode', CsvImportMode::Upsert->value),
				'dry_run' => $dry_run,
			]);
		} catch (Throwable $e) {
			if ($json) {
				echo json_encode([
					'status' => 'error',
					'message' => $e->getMessage(),
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

				return;
			}

			echo "Seed sync failed: {$e->getMessage()}\n";

			return;
		}

		if ($json) {
			echo json_encode([
				'status' => $result['has_errors'] ? 'error' : 'success',
				...$result,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		$prefix = $dry_run ? '[dry-run] ' : '';
		echo "{$prefix}Input dir: {$result['input_dir']}\n";
		echo "{$prefix}Mode: {$result['mode']}\n";
		echo "{$prefix}Files processed: {$result['files_processed']}\n";
		echo "{$prefix}Conflicts: {$result['conflicts']}\n";
		echo "{$prefix}Inserted: {$result['inserted']}\n";
		echo "{$prefix}Updated: {$result['updated']}\n";
		echo "{$prefix}Imported: {$result['imported']}\n";
		echo "{$prefix}Skipped: {$result['skipped']}\n";
		echo "{$prefix}Deleted: {$result['deleted']}\n";

		foreach ($result['files'] as $file) {
			echo "{$prefix}- {$file['locale']}: conflicts {$file['conflicts']}, inserted {$file['inserted']}, updated {$file['updated']}, imported {$file['imported']}, skipped {$file['skipped']}, deleted {$file['deleted']}";

			if (!empty($file['errors'])) {
				echo " | errors: " . implode(', ', $file['errors']);
			}

			echo "\n";
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

	private function getSingleOption(string $name, string $default = ''): string
	{
		global $argv;

		foreach ($argv as $idx => $arg) {
			if ($arg === "--{$name}") {
				$value = $argv[$idx + 1] ?? null;

				return is_string($value) && !str_starts_with($value, '--') ? trim($value) : $default;
			}
		}

		$key_value = Request::getArg($name);

		if (is_string($key_value) && trim($key_value) !== '') {
			return trim($key_value);
		}

		return $default;
	}
}
