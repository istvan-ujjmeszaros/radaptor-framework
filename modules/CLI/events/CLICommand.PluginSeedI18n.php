<?php

/**
 * Import i18n seed CSV files shipped by installed plugins.
 *
 * Usage:
 *   radaptor plugin:seed-i18n <plugin-id> [--dry-run] [--json]
 *   radaptor plugin:seed-i18n --all [--dry-run] [--json]
 */
class CLICommandPluginSeedI18n extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Import plugin i18n seeds';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Import i18n seed CSV files shipped by installed plugins.

			Usage:
			  radaptor plugin:seed-i18n <plugin-id> [--dry-run] [--json]
			  radaptor plugin:seed-i18n --all [--dry-run] [--json]

			Examples:
			  radaptor plugin:seed-i18n hello-world
			  radaptor plugin:seed-i18n --all --dry-run
			  radaptor plugin:seed-i18n --all --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'plugin_id', 'label' => 'Plugin ID', 'type' => 'main_arg'],
			['name' => 'all', 'label' => 'All plugins', 'type' => 'flag', 'default' => '1'],
			['name' => 'dry-run', 'label' => 'Dry run', 'type' => 'flag'],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$plugin_id = Request::getMainArg();

		if ($plugin_id !== null && $this->looksLikeCliOption($plugin_id)) {
			$plugin_id = null;
		}

		$all = Request::hasArg('all');
		$dry_run = Request::hasArg('dry-run');
		$json = Request::hasArg('json');

		try {
			$result = PluginI18nSeedService::seed($plugin_id, $all, $dry_run);
		} catch (Throwable $e) {
			if ($json) {
				echo json_encode([
					'status' => 'error',
					'message' => $e->getMessage(),
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

				return;
			}

			echo "Plugin seed import failed: {$e->getMessage()}\n";

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
		echo "{$prefix}Plugins processed: {$result['plugins_processed']}\n";
		echo "{$prefix}Seed files processed: {$result['files_processed']}\n";
		echo "{$prefix}Inserted: {$result['inserted']}\n";
		echo "{$prefix}Updated: {$result['updated']}\n";
		echo "{$prefix}Imported: {$result['imported']}\n";
		echo "{$prefix}Skipped: {$result['skipped']}\n";
		echo "{$prefix}Deleted: {$result['deleted']}\n";

		foreach ($result['plugins'] as $plugin) {
			echo "\n{$prefix}Plugin: {$plugin['plugin_id']} ({$plugin['status']})\n";

			if (!empty($plugin['seed_dir'])) {
				echo "{$prefix}Seed dir: {$plugin['seed_dir']}\n";
			}

			if (!empty($plugin['errors'])) {
				echo "{$prefix}Errors: " . implode(', ', $plugin['errors']) . "\n";
			}

			foreach ($plugin['files'] as $file) {
				echo "{$prefix}- {$file['locale']}: inserted {$file['inserted']}, imported {$file['imported']}, skipped {$file['skipped']}";

				if (!empty($file['errors'])) {
					echo " | errors: " . implode(', ', $file['errors']);
				}

				echo "\n";
			}
		}

		if ($dry_run) {
			echo "\n(dry-run — no changes written)\n";
		}
	}

	private function looksLikeCliOption(string $arg): bool
	{
		return str_starts_with($arg, '--') || str_contains($arg, '=');
	}
}
