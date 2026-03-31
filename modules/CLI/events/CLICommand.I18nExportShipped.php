<?php

/**
 * Export all shipped core/app translation seed directories into their versioned locations.
 *
 * Usage:
 *   radaptor i18n:export-shipped [--locale en_US,hu_HU] [--clean] [--dry-run] [--json]
 */
class CLICommandI18nExportShipped extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Export shipped translations';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Export all shipped core/app translation seed directories into their versioned locations.

			Usage: radaptor i18n:export-shipped [--locale en_US,hu_HU] [--clean] [--dry-run] [--json]

			Examples:
			  radaptor i18n:export-shipped
			  radaptor i18n:export-shipped --locale hu_HU
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

	public function getWebParams(): array
	{
		return [
			['name' => 'locale', 'label' => 'Locale', 'type' => 'option'],
		];
	}

	public function run(): void
	{
		$dry_run = Request::hasArg('dry-run');
		$clean = Request::hasArg('clean');
		$json = Request::hasArg('json');

		try {
			$result = I18nShippedExportService::export([
				'locales' => $this->getCsvListOption('locale'),
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

			echo "Shipped seed export failed: {$e->getMessage()}\n";

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
		echo "{$prefix}Groups processed: {$result['groups_processed']}\n";
		echo "{$prefix}Rows exported: {$result['rows_exported']}\n";
		echo "{$prefix}Files written: {$result['files_written']}\n";

		if (!empty($result['deleted_files'])) {
			echo "{$prefix}Deleted stale files: " . count($result['deleted_files']) . "\n";
		}

		foreach ($result['groups'] as $group) {
			echo "{$prefix}{$group['group_type']}:{$group['group_id']} → {$group['rows_exported']} rows, {$group['files_written']} files\n";
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
