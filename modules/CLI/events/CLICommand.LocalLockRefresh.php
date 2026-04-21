<?php

/**
 * Refresh radaptor.local.lock.json from committed lock state and active local overrides.
 *
 * Usage: radaptor local-lock:refresh [--include-demo-seeds] [--rerun-demo-seeds] [--skip-seeds] [--dry-run] [--json] [--ignore-local-overrides]
 */
class CLICommandLocalLockRefresh extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Refresh local package lock';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Refresh radaptor.local.lock.json from committed lock state and active local overrides.

			Usage: radaptor local-lock:refresh [--include-demo-seeds] [--rerun-demo-seeds] [--skip-seeds] [--dry-run] [--json] [--ignore-local-overrides]

			Examples:
			  radaptor local-lock:refresh
			  radaptor local-lock:refresh --dry-run
			  radaptor local-lock:refresh --json
			DOC;
	}

	public function run(): void
	{
		$dry_run = Request::hasArg('dry-run');
		$json = Request::hasArg('json');
		$include_demo_seeds = Request::hasArg('include-demo-seeds');
		$rerun_demo_seeds = Request::hasArg('rerun-demo-seeds');
		$skip_seeds = Request::hasArg('skip-seeds');
		$ignore_local_overrides = Request::hasArg('ignore-local-overrides');
		$prompt = (!$json && SeedCliPromptHelper::isInteractive())
			? static fn (array $demo_seeds): bool => SeedCliPromptHelper::confirmDemoSeedRerun($demo_seeds)
			: null;

		try {
			$result = PackageInstallService::refreshLocalLock(
				$dry_run,
				$include_demo_seeds,
				$rerun_demo_seeds,
				$skip_seeds,
				$prompt,
				$ignore_local_overrides
			);
		} catch (Throwable $e) {
			if ($json) {
				echo json_encode([
					'status' => 'error',
					'message' => $e->getMessage(),
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

				return;
			}

			echo "Local lock refresh failed: {$e->getMessage()}\n";

			return;
		}

		$status = $this->determineStatus($result);

		if ($json) {
			echo json_encode([
				'status' => $status,
				...$result,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		$prefix = $dry_run ? '[dry-run] ' : '';

		echo "{$prefix}Mode: local-lock:refresh\n";
		echo "{$prefix}Processed packages: {$result['packages_processed']}\n";
		echo "{$prefix}Removed packages: {$result['packages_removed']}\n";
		echo "{$prefix}Lockfile changed: " . ($result['lockfile_changed'] ? 'yes' : 'no') . "\n";
		echo "{$prefix}Lockfile written: " . ($result['lockfile_written'] ? 'yes' : 'no') . "\n";
		echo "{$prefix}Plugin bridge written: " . ($result['plugin_bridge_written'] ? 'yes' : 'no') . "\n";

		if (is_array($result['plugin_sync'] ?? null)) {
			echo "{$prefix}Plugin runtime sync: yes\n";
		}

		if (($result['package_migrations_ran'] ?? false) === true) {
			$package_migrations = is_array($result['package_migrations'] ?? null) ? $result['package_migrations'] : [];
			echo "{$prefix}Package migrations: " . count($package_migrations) . "\n";
		}

		if (($result['seeds_ran'] ?? false) === true && is_array($result['seeds'] ?? null)) {
			echo "{$prefix}Seeds: {$result['seeds']['status']}, executed {$result['seeds']['seeds_executed']}, skipped {$result['seeds']['seeds_skipped']}\n";

			if (!empty($result['seeds']['message'])) {
				echo "{$prefix}{$result['seeds']['message']}\n";
			}
		}

		if (($result['assets_built'] ?? false) === true) {
			$assets = is_array($result['assets'] ?? null) ? $result['assets'] : [];
			echo "{$prefix}Assets: +{$assets['links_created']} / -{$assets['links_removed']} / ={$assets['links_unchanged']}\n";
		}

		foreach ($result['packages'] as $package) {
			echo "{$prefix}{$package['package_key']}: {$package['action']} ({$package['source_type']})\n";
		}

		if (!empty($result['removed_package_keys'])) {
			echo "{$prefix}Removed from lockfile: " . implode(', ', $result['removed_package_keys']) . "\n";
		}

		if ($dry_run) {
			echo "(dry-run — no changes written)\n";
		}

		if ($status === 'error') {
			echo "Local lock refresh finished with errors.\n";
		}
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function determineStatus(array $result): string
	{
		if ($this->hasFailedMigration($result['package_migrations'] ?? null)) {
			return 'error';
		}

		if ($this->hasFailedPluginSync($result['plugin_sync'] ?? null)) {
			return 'error';
		}

		if ($this->hasFailedSeeds($result['seeds'] ?? null)) {
			return 'error';
		}

		return 'success';
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

	/**
	 * @param array<string, mixed>|null $result
	 */
	private function hasFailedPluginSync(?array $result): bool
	{
		if (!is_array($result)) {
			return false;
		}

		if ($this->hasFailedMigration($result['plugin_migrations'] ?? null)) {
			return true;
		}

		return ($result['i18n_seed_sync']['has_errors'] ?? false) === true;
	}

	/**
	 * @param array<string, mixed>|null $result
	 */
	private function hasFailedSeeds(?array $result): bool
	{
		if (!is_array($result)) {
			return false;
		}

		return in_array((string) ($result['status'] ?? ''), ['aborted', 'error'], true);
	}
}
