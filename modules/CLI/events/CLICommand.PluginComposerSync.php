<?php

/**
 * Sync plugin-declared Composer requirements into the root composer.json.
 *
 * Usage: radaptor plugin:composer-sync [--dry-run] [--json]
 */
class CLICommandPluginComposerSync extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Sync plugin Composer requirements';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Sync plugin-declared Composer requirements into the root composer.json.

			Usage: radaptor plugin:composer-sync [--dry-run] [--json]

			Examples:
			  radaptor plugin:composer-sync
			  radaptor plugin:composer-sync --dry-run
			  radaptor plugin:composer-sync --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [];
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$dryRun = Request::hasArg('dry-run');
		$json = Request::hasArg('json');

		try {
			$result = PluginComposerSyncService::sync($dryRun);
		} catch (Throwable $e) {
			if ($json) {
				echo json_encode([
					'status' => 'error',
					'message' => $e->getMessage(),
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

				return;
			}

			echo "Plugin composer sync failed: {$e->getMessage()}\n";

			return;
		}

		if ($json) {
			echo json_encode([
				'status' => 'success',
				...$result,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		$prefix = $dryRun ? '[dry-run] ' : '';

		echo "{$prefix}Composer JSON changed: " . ($result['composer_json_changed'] ? 'yes' : 'no') . "\n";
		echo "{$prefix}Composer ownership lock changed: " . ($result['composer_lockfile_changed'] ? 'yes' : 'no') . "\n";

		if ($result['added_packages'] !== []) {
			echo "{$prefix}Added packages:\n";

			foreach ($result['added_packages'] as $package => $constraint) {
				echo "  + {$package} {$constraint}\n";
			}
		}

		if ($result['updated_packages'] !== []) {
			echo "{$prefix}Updated packages:\n";

			foreach ($result['updated_packages'] as $package => $change) {
				echo "  ~ {$package} {$change['from']} -> {$change['to']}\n";
			}
		}

		if ($result['removed_packages'] !== []) {
			echo "{$prefix}Removed packages:\n";

			foreach ($result['removed_packages'] as $package => $constraint) {
				echo "  - {$package} {$constraint}\n";
			}
		}

		if ($result['root_owned_warnings'] !== []) {
			echo "{$prefix}Root-owned package warnings:\n";

			foreach ($result['root_owned_warnings'] as $package => $warning) {
				echo "  ! {$package}: keeping root constraint {$warning['current_constraint']} (plugins requested {$warning['requested_constraint']})\n";
			}
		}

		if (!$result['changed']) {
			echo "{$prefix}No changes required.\n";
		}

		if ($dryRun) {
			echo "(dry-run — no changes written)\n";
		}
	}
}
