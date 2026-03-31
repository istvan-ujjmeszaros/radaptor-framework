<?php

/**
 * Sync plugin lockfile and generated runtime registry from the manifest.
 *
 * Usage: radaptor plugin:sync [--dry-run] [--json]
 */
class CLICommandPluginSync extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Sync plugins from manifest';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Sync plugin lockfile and generated runtime registry from the manifest.
			Runs migrations, i18n seed imports, and composer sync as needed.

			Usage: radaptor plugin:sync [--dry-run] [--json]

			Examples:
			  radaptor plugin:sync
			  radaptor plugin:sync --dry-run
			  radaptor plugin:sync --json
			DOC;
	}

	public function run(): void
	{
		$dryRun = Request::hasArg('dry-run');
		$json = Request::hasArg('json');

		try {
			$result = PluginSyncService::sync($dryRun);
		} catch (Throwable $e) {
			if ($json) {
				echo json_encode([
					'status' => 'error',
					'message' => $e->getMessage(),
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

				return;
			}

			echo "Plugin sync failed: {$e->getMessage()}\n";

			return;
		}

		$status = 'success';

		if ($this->hasFailedMigration($result['plugin_migrations'] ?? null)) {
			$status = 'error';
		}

		if (($result['i18n_seed_sync']['has_errors'] ?? false) === true) {
			$status = 'error';
		}

		if ($json) {
			echo json_encode([
				'status' => $status,
				...$result,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		$prefix = $dryRun ? '[dry-run] ' : '';

		echo "{$prefix}Processed plugins: {$result['plugins_processed']}\n";
		echo "{$prefix}Removed plugins: {$result['plugins_removed']}\n";
		echo "{$prefix}Lockfile changed: " . ($result['lockfile_changed'] ? 'yes' : 'no') . "\n";
		echo "{$prefix}Lockfile written: " . ($result['lockfile_written'] ? 'yes' : 'no') . "\n";
		echo "{$prefix}Autoloader rebuilt: " . ($result['autoloader_rebuilt'] ? 'yes' : 'no') . "\n";
		echo "{$prefix}Runtime registry rebuilt: " . ($result['runtime_registry_rebuilt'] ? 'yes' : 'no') . "\n";

		if ($result['plugin_migrations_ran']) {
			echo "{$prefix}Plugin migrations: " . count($result['plugin_migrations'] ?? []) . "\n";
		}

		if ($result['i18n_seed_sync_ran']) {
			echo "{$prefix}Plugin i18n seed sync: files {$result['i18n_seed_sync']['files_processed']}, conflicts {$result['i18n_seed_sync']['conflicts']}, imported {$result['i18n_seed_sync']['imported']}\n";
		}

		if ($result['composer_sync_ran']) {
			echo "{$prefix}Plugin composer sync: " . (($result['composer_sync']['changed'] ?? false) ? 'changed' : 'no changes') . "\n";
		}

		foreach ($result['plugins'] as $plugin) {
			echo "{$prefix}{$plugin['plugin_id']}: {$plugin['action']} ({$plugin['source_type']})\n";
		}

		if (!empty($result['removed_plugin_ids'])) {
			echo "{$prefix}Removed from lockfile: " . implode(', ', $result['removed_plugin_ids']) . "\n";
		}

		if ($dryRun) {
			echo "(dry-run — no changes written)\n";
		}
	}

	/**
	 * @param array<int, array<string, mixed>>|null $results
	 */
	private function hasFailedMigration(?array $results): bool
	{
		if (!is_array($results)) {
			return false;
		}

		foreach ($results as $result) {
			if (($result['success'] ?? true) !== true) {
				return true;
			}
		}

		return false;
	}
}
