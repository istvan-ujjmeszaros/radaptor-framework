<?php

/**
 * Install or reconcile packages from radaptor.json without upgrading locked registry versions.
 *
 * Usage: radaptor install [--include-demo-seeds] [--rerun-demo-seeds] [--skip-seeds] [--dry-run] [--json] [--ignore-local-overrides] [--apply-layout-renames | --abort-on-layout-renames]
 */
class CLICommandInstall extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Install packages';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Install or reconcile packages from radaptor.json without upgrading locked registry versions.

			Usage: radaptor install [--include-demo-seeds] [--rerun-demo-seeds] [--skip-seeds] [--dry-run] [--json] [--ignore-local-overrides] [--apply-layout-renames | --abort-on-layout-renames]

			Layout renames:
			  Incoming packages may declare deprecated_layouts in .registry-package.json
			  (e.g. admin_nomenu -> admin_login). After refreshing the registry packages on
			  disk and BEFORE lockfile-write, asset-build, plugin-bridge, migrations, and
			  seeds, install/update detects affected webpages and _theme_settings mappings
			  and either prompts (TTY) or requires one of:
			    --apply-layout-renames    apply pending renames non-interactively
			    --abort-on-layout-renames refuse renames non-interactively (exits with error)
			  In CI / non-TTY mode with neither flag set, the command aborts with exit 1.
			  Aborting leaves the registry refreshed but performs no CMS-content mutation;
			  the next run re-prompts.

			Examples:
			  radaptor install
			  radaptor install --include-demo-seeds
			  radaptor install --include-demo-seeds --rerun-demo-seeds
			  radaptor install --skip-seeds
			  radaptor install --dry-run
			  radaptor install --json
			  radaptor install --ignore-local-overrides
			  radaptor install --apply-layout-renames
			  radaptor install --abort-on-layout-renames
			DOC;
	}

	public function run(): void
	{
		$this->runMode(false);
	}

	protected function runMode(bool $update): void
	{
		$dry_run = Request::hasArg('dry-run');
		$json = Request::hasArg('json');
		$include_demo_seeds = Request::hasArg('include-demo-seeds');
		$rerun_demo_seeds = Request::hasArg('rerun-demo-seeds');
		$skip_seeds = Request::hasArg('skip-seeds');
		$ignore_local_overrides = Request::hasArg('ignore-local-overrides');
		$apply_layout_renames = Request::hasArg('apply-layout-renames');
		$abort_layout_renames = Request::hasArg('abort-on-layout-renames');
		$prompt = (!$json && SeedCliPromptHelper::isInteractive())
			? static fn (array $demo_seeds): bool => SeedCliPromptHelper::confirmDemoSeedRerun($demo_seeds)
			: null;

		if ($apply_layout_renames && $abort_layout_renames) {
			$message = '--apply-layout-renames and --abort-on-layout-renames are mutually exclusive.';

			if ($json) {
				echo json_encode(['status' => 'error', 'message' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

				exit(1);
			}

			echo "{$message}\n";

			exit(1);
		}

		$layout_decision = static function (array $pending, array $rename_metadata) use ($apply_layout_renames, $abort_layout_renames, $json): ?bool {
			if ($abort_layout_renames) {
				return false;
			}

			if ($apply_layout_renames) {
				return true;
			}

			if ($json) {
				// JSON callers must drive the gate explicitly via flags; we keep stdout machine-parseable
				// and let the resulting RuntimeException surface through the JSON error response.
				return null;
			}

			if (SeedCliPromptHelper::isInteractive()) {
				return SeedCliPromptHelper::confirmLayoutRenames($pending, $rename_metadata);
			}

			return null;
		};

		try {
			$result = $update
				? PackageInstallService::update($dry_run, $include_demo_seeds, $rerun_demo_seeds, $skip_seeds, $prompt, $ignore_local_overrides, $layout_decision)
				: PackageInstallService::install($dry_run, $include_demo_seeds, $rerun_demo_seeds, $skip_seeds, $prompt, $ignore_local_overrides, $layout_decision);
		} catch (Throwable $e) {
			if ($json) {
				echo json_encode([
					'status' => 'error',
					'message' => $e->getMessage(),
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

				exit(1);
			}

			echo ucfirst($update ? 'update' : 'install') . " failed: {$e->getMessage()}\n";

			exit(1);
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

		echo "{$prefix}Mode: {$result['mode']}\n";
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

		if (is_array($result['shipped_i18n_audit'] ?? null)) {
			$audit = $result['shipped_i18n_audit'];
			echo "{$prefix}Shipped i18n DB: {$audit['status']}, missing {$audit['missing_rows']}, changed {$audit['changed_rows']}, customized {$audit['customized_rows']}\n";
		}

		if (($result['shipped_i18n_sync_ran'] ?? false) === true && is_array($result['shipped_i18n_sync'] ?? null)) {
			$sync = $result['shipped_i18n_sync'];
			echo "{$prefix}Shipped i18n sync: files {$sync['files_processed']}, conflicts {$sync['conflicts']}, imported {$sync['imported']}\n";
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
			echo ucfirst($update ? 'update' : 'install') . " finished with errors.\n";
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

		if ($this->hasFailedShippedI18n($result['shipped_i18n_audit'] ?? null, $result['shipped_i18n_sync'] ?? null)) {
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
	 * @param array<string, mixed>|null $audit
	 * @param array<string, mixed>|null $sync
	 */
	private function hasFailedShippedI18n(?array $audit, ?array $sync): bool
	{
		if (is_array($audit) && ($audit['status'] ?? 'ok') === 'error') {
			return true;
		}

		return is_array($sync) && ($sync['has_errors'] ?? false) === true;
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
