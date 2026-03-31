<?php

/**
 * Sync all shipped translation seed directories for the current installation.
 *
 * Usage:
 *   radaptor i18n:sync-shipped [--locale en_US,hu_HU] [--mode upsert|insert_new|sync] [--dry-run] [--no-build] [--json]
 */
class CLICommandI18nSyncShipped extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Sync shipped translations';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Sync all shipped translation seed directories for the current installation.

			Usage: radaptor i18n:sync-shipped [--locale en_US,hu_HU] [--mode upsert|insert_new|sync] [--dry-run] [--no-build] [--json]

			Examples:
			  radaptor i18n:sync-shipped
			  radaptor i18n:sync-shipped --locale hu_HU
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

	public function getWebParams(): array
	{
		return [
			['name' => 'locale', 'label' => 'Locale', 'type' => 'option'],
		];
	}

	public function run(): void
	{
		$dry_run = Request::hasArg('dry-run');
		$json = Request::hasArg('json');

		try {
			$result = I18nShippedSyncService::sync([
				'locales' => $this->getCsvListOption('locale'),
				'mode' => $this->getSingleOption('mode', CsvImportMode::Upsert->value),
				'dry_run' => $dry_run,
				'build' => !Request::hasArg('no-build'),
			]);
		} catch (Throwable $e) {
			if ($json) {
				echo json_encode([
					'status' => 'error',
					'message' => $e->getMessage(),
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

				return;
			}

			echo "Shipped i18n sync failed: {$e->getMessage()}\n";

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
		echo "{$prefix}Groups processed: {$result['groups_processed']}\n";
		echo "{$prefix}Files processed: {$result['files_processed']}\n";
		echo "{$prefix}Conflicts: {$result['conflicts']}\n";
		echo "{$prefix}Inserted: {$result['inserted']}\n";
		echo "{$prefix}Updated: {$result['updated']}\n";
		echo "{$prefix}Imported: {$result['imported']}\n";
		echo "{$prefix}Skipped: {$result['skipped']}\n";
		echo "{$prefix}Deleted: {$result['deleted']}\n";
		echo "{$prefix}Catalog build: " . ($result['build_ran'] ? 'ran' : ($result['build_requested'] ? 'skipped' : 'disabled')) . "\n";

		foreach ($result['groups'] as $group) {
			echo "{$prefix}{$group['group_type']}:{$group['group_id']} → {$group['status']} | files {$group['files_processed']}, conflicts {$group['conflicts']}, imported {$group['imported']}\n";
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
