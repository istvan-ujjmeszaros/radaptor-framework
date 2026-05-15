<?php

/**
 * Uninstall a plugin from the current application state.
 *
 * Usage: radaptor plugin:uninstall <plugin-id> [--dry-run] [--json] [--no-backup]
 */
class CLICommandPluginUninstall extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Uninstall a plugin';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Uninstall a plugin from the current application state.
			Removes from manifest, lockfile, filesystem, and rebuilds registries.

			Usage: radaptor plugin:uninstall <plugin-id> [--dry-run] [--json] [--no-backup]

			Examples:
			  radaptor plugin:uninstall hello-world
			  radaptor plugin:uninstall hello-world --dry-run
			  radaptor plugin:uninstall hello-world --no-backup --json
			DOC;
	}

	public function run(): void
	{
		global $argv;

		$plugin_id = $argv[2] ?? null;

		if (!is_string($plugin_id) || $plugin_id === '') {
			Kernel::abort("Usage: radaptor plugin:uninstall <plugin-id> [--dry-run] [--json] [--no-backup]");
		}

		$dry_run = Request::hasArg('dry-run');
		$json = Request::hasArg('json');
		$no_backup = Request::hasArg('no-backup');

		try {
			$result = PluginUninstallService::uninstall($plugin_id, $dry_run, $no_backup);
		} catch (Throwable $e) {
			if ($json) {
				echo json_encode([
					'status' => 'error',
					'message' => $e->getMessage(),
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

				return;
			}

			echo "Plugin uninstall failed: {$e->getMessage()}\n";

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
		echo "{$prefix}Plugin: {$result['plugin_id']}\n";
		echo "{$prefix}Source type: " . ($result['source_type'] ?? '-') . "\n";
		echo "{$prefix}Manifest updated: " . ($result['manifest_updated'] ? 'yes' : 'no') . "\n";
		echo "{$prefix}Lockfile updated: " . ($result['lockfile_updated'] ? 'yes' : 'no') . "\n";
		echo "{$prefix}Backup created: " . ($result['backup_created'] ? 'yes' : 'no') . "\n";

		if ($result['backup_dir'] !== null) {
			echo "{$prefix}Backup dir: {$result['backup_dir']}\n";
		}

		echo "{$prefix}Filesystem removed: " . ($result['filesystem_removed'] ? 'yes' : 'no') . "\n";
		echo "{$prefix}Autoloader rebuilt: " . ($result['autoloader_rebuilt'] ? 'yes' : 'no') . "\n";
		echo "{$prefix}Runtime registry rebuilt: " . ($result['runtime_registry_rebuilt'] ? 'yes' : 'no') . "\n";
		echo "{$prefix}Plugin composer sync: " . (($result['composer_sync']['changed'] ?? false) ? 'changed' : 'no changes') . "\n";

		if ($dry_run) {
			echo "(dry-run — no changes written)\n";
		}
	}
}
