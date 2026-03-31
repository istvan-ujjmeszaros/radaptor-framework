<?php

class PluginSyncService
{
	/**
	 * @return array{
	 *     dry_run: bool,
	 *     lockfile_changed: bool,
	 *     lockfile_written: bool,
	 *     templates_rebuilt: bool,
	 *     widgets_rebuilt: bool,
	 *     autoloader_rebuilt: bool,
	 *     runtime_registry_rebuilt: bool,
	 *     plugin_migrations_ran: bool,
	 *     plugin_migrations: array<int, array<string, mixed>>|null,
	 *     i18n_seed_sync_ran: bool,
	 *     i18n_seed_sync: array<string, mixed>|null,
	 *     composer_sync_ran: bool,
	 *     composer_sync: array<string, mixed>|null,
	 *     lifecycle_hooks_ran: bool,
	 *     plugins_processed: int,
	 *     plugins_removed: int,
	 *     removed_plugin_ids: string[],
	 *     plugins: array<int, array<string, mixed>>,
	 *     status_summary: array<string, mixed>
	 * }
	 */
	public static function sync(bool $dry_run = false, bool $run_plugin_migrations = true): array
	{
		return self::syncPaths(
			PluginManifest::getPath(),
			PluginLockfile::getPath(),
			$dry_run,
			ComposerJsonHelper::getPath(),
			PluginComposerLockfile::getPath(),
			$run_plugin_migrations
		);
	}

	/**
	 * @return array{
	 *     dry_run: bool,
	 *     lockfile_changed: bool,
	 *     lockfile_written: bool,
	 *     templates_rebuilt: bool,
	 *     widgets_rebuilt: bool,
	 *     autoloader_rebuilt: bool,
	 *     runtime_registry_rebuilt: bool,
	 *     plugin_migrations_ran: bool,
	 *     plugin_migrations: array<int, array<string, mixed>>|null,
	 *     i18n_seed_sync_ran: bool,
	 *     i18n_seed_sync: array<string, mixed>|null,
	 *     composer_sync_ran: bool,
	 *     composer_sync: array<string, mixed>|null,
	 *     lifecycle_hooks_ran: bool,
	 *     plugins_processed: int,
	 *     plugins_removed: int,
	 *     removed_plugin_ids: string[],
	 *     plugins: array<int, array<string, mixed>>,
	 *     status_summary: array<string, mixed>
	 * }
	 */
	public static function syncPaths(
		string $manifest_path,
		string $lock_path,
		bool $dry_run = false,
		?string $composer_json_path = null,
		?string $plugin_composer_lock_path = null,
		bool $run_plugin_migrations = true
	): array {
		$manifest = PluginManifest::loadFromPath($manifest_path);
		$current_lock = file_exists($lock_path)
			? PluginLockfile::loadFromPath($lock_path)
			: [
				'lockfile_version' => 1,
				'plugins' => [],
				'path' => $lock_path,
				'base_dir' => dirname($lock_path),
			];
		$next = self::buildNextLockState($manifest, $current_lock, dirname($lock_path), $dry_run);
		$next_lock = [
			'lockfile_version' => max(1, (int) $current_lock['lockfile_version']),
			'plugins' => $next['plugins'],
		];
		$current_export = PluginLockfile::exportDocument($current_lock);
		$next_export = PluginLockfile::exportDocument($next_lock);
		$lockfile_changed = $current_export !== $next_export;
		$lockfile_written = false;
		$stale_registry_paths = self::collectStaleRegistryPaths(
			$current_lock['plugins'],
			$next_lock['plugins'],
			dirname($lock_path)
		);
		$templates_rebuilt = false;
		$widgets_rebuilt = false;
		$autoloader_rebuilt = false;
		$runtime_registry_rebuilt = false;
		$plugin_migrations_ran = false;
		$plugin_migrations = null;
		$migrations_failed = false;
		$i18n_seed_sync_ran = false;
		$i18n_seed_sync = null;
		$composer_sync_ran = false;
		$composer_sync = null;
		$lifecycle_hooks_ran = false;

		if (!$dry_run) {
			if ($run_plugin_migrations) {
				$plugin_migrations = MigrationRunner::runPendingForModules($next['ordered_plugin_ids']);
				$plugin_migrations_ran = true;
				$migrations_failed = self::hasFailedMigration($plugin_migrations);
			}

			if (!$migrations_failed) {
				self::removeStaleRegistryDirectories($stale_registry_paths);

				foreach ($next['removed_plugin_ids'] as $removed_plugin_id) {
					PluginConfigExampleManager::removeExample($removed_plugin_id);
				}

				if ($lockfile_changed) {
					PluginLockfile::write($next_lock, $lock_path);
					$lockfile_written = true;
				}

				self::rebuildTemplates();
				self::rebuildWidgets();
				self::rebuildAutoloader();
				self::rebuildRuntimeRegistry();
				PluginConfigExampleManager::syncInstalledExamples($next_lock['plugins']);
				PackageConfig::reset();
				PackagePathHelper::reset();
				PluginRegistry::reset();
				$templates_rebuilt = true;
				$widgets_rebuilt = true;
				$autoloader_rebuilt = true;
				$runtime_registry_rebuilt = true;
				$i18n_seed_sync = PluginI18nSyncService::syncPaths(
					$manifest_path,
					$lock_path,
					null,
					true,
					false,
					CsvImportMode::Upsert->value
				);
				$i18n_seed_sync_ran = true;
				PluginLifecycleManager::runAfterSync($next_lock['plugins']);
				$lifecycle_hooks_ran = true;

				if ($composer_json_path !== null && $plugin_composer_lock_path !== null) {
					$composer_sync = self::syncComposerState(
						$next_lock,
						$composer_json_path,
						$plugin_composer_lock_path,
						$dry_run
					);
					$composer_sync_ran = true;
				}
			}
		}

		if ($dry_run && $composer_json_path !== null && $plugin_composer_lock_path !== null) {
			$composer_sync = self::syncComposerState(
				$next_lock,
				$composer_json_path,
				$plugin_composer_lock_path,
				true
			);
			$composer_sync_ran = true;
		}

		$status = PluginStateInspector::getStatus();

		return [
			'dry_run' => $dry_run,
			'lockfile_changed' => $lockfile_changed,
			'lockfile_written' => $lockfile_written,
			'templates_rebuilt' => $templates_rebuilt,
			'widgets_rebuilt' => $widgets_rebuilt,
			'autoloader_rebuilt' => $autoloader_rebuilt,
			'runtime_registry_rebuilt' => $runtime_registry_rebuilt,
			'plugin_migrations_ran' => $plugin_migrations_ran,
			'plugin_migrations' => $plugin_migrations,
			'i18n_seed_sync_ran' => $i18n_seed_sync_ran,
			'i18n_seed_sync' => $i18n_seed_sync,
			'composer_sync_ran' => $composer_sync_ran,
			'composer_sync' => $composer_sync,
			'lifecycle_hooks_ran' => $lifecycle_hooks_ran,
			'plugins_processed' => count($next['plugin_summaries']),
			'plugins_removed' => count($next['removed_plugin_ids']),
			'removed_plugin_ids' => $next['removed_plugin_ids'],
			'plugins' => array_values($next['plugin_summaries']),
			'status_summary' => $status['summary'],
		];
	}

	/**
	 * @param array{
	 *     lockfile_version?: int,
	 *     plugins: array<string, array<string, mixed>>
	 * } $next_lock
	 * @return array<string, mixed>
	 */
	private static function syncComposerState(
		array $next_lock,
		string $composer_json_path,
		string $plugin_composer_lock_path,
		bool $dry_run
	): array {
		$temp_plugin_lock_path = tempnam(sys_get_temp_dir(), 'plugin-lock-');

		if ($temp_plugin_lock_path === false) {
			throw new RuntimeException('Unable to create temporary plugin lockfile for composer sync.');
		}

		try {
			PluginLockfile::write($next_lock, $temp_plugin_lock_path);

			return PluginComposerSyncService::syncPaths(
				$composer_json_path,
				$plugin_composer_lock_path,
				$temp_plugin_lock_path,
				$dry_run
			);
		} finally {
			if (is_file($temp_plugin_lock_path)) {
				unlink($temp_plugin_lock_path);
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>>|null $results
	 */
	private static function hasFailedMigration(?array $results): bool
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
	 * @param array{
	 *     plugins: array<string, array<string, mixed>>
	 * } $manifest
	 * @param array{
	 *     lockfile_version?: int,
	 *     plugins: array<string, array<string, mixed>>
	 * } $current_lock
	 * @return array{
	 *     plugins: array<string, array<string, mixed>>,
	 *     plugin_summaries: array<string, array<string, mixed>>,
	 *     removed_plugin_ids: string[],
	 *     ordered_plugin_ids: list<string>
	 * }
	 */
	private static function buildNextLockState(array $manifest, array $current_lock, string $lock_base_dir, bool $dry_run): array
	{
		$next_plugins = [];
		$plugin_summaries = [];
		$discovery_state = self::createDiscoveryState();

		foreach ($manifest['plugins'] as $plugin_id => $manifest_plugin) {
			$current_locked_plugin = $current_lock['plugins'][$plugin_id] ?? null;
			$locked_plugin = self::buildLockedPlugin(
				$plugin_id,
				$manifest_plugin,
				$current_locked_plugin,
				$lock_base_dir,
				$dry_run,
				$discovery_state
			);
			$action = !isset($current_lock['plugins'][$plugin_id])
				? 'added'
				: (
					PluginLockfile::exportPlugin($current_lock['plugins'][$plugin_id], $plugin_id)
					=== PluginLockfile::exportPlugin($locked_plugin, $plugin_id)
						? 'unchanged'
						: 'updated'
				);

			$next_plugins[$plugin_id] = $locked_plugin;
			$plugin_summaries[$plugin_id] = self::buildPluginSummary(
				$plugin_id,
				$manifest_plugin,
				$locked_plugin,
				$current_lock['plugins'][$plugin_id] ?? null,
				$action
			);
		}

		self::resolveAutoInstalledRegistryDependencies(
			$manifest['plugins'],
			$current_lock['plugins'],
			$next_plugins,
			$plugin_summaries,
			$lock_base_dir,
			$dry_run,
			$discovery_state
		);

		$removed_plugin_ids = array_values(array_diff(
			array_keys($current_lock['plugins']),
			array_keys($next_plugins)
		));
		sort($removed_plugin_ids);
		$ordered_plugin_ids = self::validateAndOrderPlugins($next_plugins);
		ksort($next_plugins);
		ksort($plugin_summaries);

		return [
			'plugins' => $next_plugins,
			'plugin_summaries' => $plugin_summaries,
			'removed_plugin_ids' => $removed_plugin_ids,
			'ordered_plugin_ids' => $ordered_plugin_ids,
		];
	}

	/**
	 * @param array<string, mixed>|null $manifest_plugin
	 * @param array<string, mixed> $locked_plugin
	 * @param array<string, mixed>|null $current_locked_plugin
	 * @return array<string, mixed>
	 */
	private static function buildPluginSummary(
		string $plugin_id,
		?array $manifest_plugin,
		array $locked_plugin,
		?array $current_locked_plugin,
		string $action
	): array {
		return [
			'plugin_id' => $plugin_id,
			'package' => $locked_plugin['package'] ?? $manifest_plugin['package'] ?? null,
			'source_type' => $locked_plugin['resolved']['type'] ?? $locked_plugin['source']['type'] ?? null,
			'action' => $action,
			'auto_installed' => (bool) ($locked_plugin['auto_installed'] ?? false),
			'required_by' => $locked_plugin['required_by'] ?? [],
			'resolved_version' => $locked_plugin['resolved']['version'] ?? null,
			'descriptor_file' => $locked_plugin['descriptor_file'] ?? null,
			'changed' => $current_locked_plugin === null
				? true
				: PluginLockfile::exportPlugin($current_locked_plugin, $plugin_id)
					!== PluginLockfile::exportPlugin($locked_plugin, $plugin_id),
		];
	}

	/**
	 * @param array<string, mixed> $manifest_plugin
	 * @param array<string, mixed>|null $current_locked_plugin
	 * @param array{
	 *     discovered: array<string, array<string, mixed>>|null,
	 *     by_base_path: array<string, array<string, mixed>>|null
	 * } $discovery_state
	 * @return array<string, mixed>
	 */
	private static function buildLockedPlugin(
		string $plugin_id,
		array $manifest_plugin,
		?array $current_locked_plugin,
		string $lock_base_dir,
		bool $dry_run,
		array &$discovery_state
	): array {
		$source = $manifest_plugin['source'] ?? null;

		if (!is_array($source)) {
			throw new RuntimeException("Plugin '{$plugin_id}' is missing a source definition.");
		}

		$source_type = $source['type'] ?? null;

		if ($source_type === 'dev') {
			$locked_plugin = self::buildDevLockedPlugin($plugin_id, $manifest_plugin, $lock_base_dir, $discovery_state);

			return self::applyManifestLockHints($locked_plugin, $manifest_plugin, $plugin_id);
		}

		if ($source_type === 'registry') {
			$locked_plugin = self::buildRegistryLockedPlugin(
				$plugin_id,
				$manifest_plugin,
				$current_locked_plugin,
				$lock_base_dir,
				$dry_run,
				$discovery_state
			);

			return self::applyManifestLockHints($locked_plugin, $manifest_plugin, $plugin_id);
		}

		throw new RuntimeException("Plugin sync does not support source type '{$source_type}' for '{$plugin_id}'.");
	}

	/**
	 * @param array<string, mixed> $locked_plugin
	 * @param array<string, mixed> $manifest_plugin
	 * @return array<string, mixed>
	 */
	private static function applyManifestLockHints(array $locked_plugin, array $manifest_plugin, string $plugin_id): array
	{
		if (($manifest_plugin['auto_installed'] ?? false) === true) {
			$locked_plugin['auto_installed'] = true;
		}

		if (isset($manifest_plugin['required_by']) && is_array($manifest_plugin['required_by'])) {
			$required_by = [];

			foreach ($manifest_plugin['required_by'] as $value) {
				$value = trim((string) $value);

				if ($value === '') {
					continue;
				}

				$required_by[] = PluginIdHelper::normalize($value, "Plugin '{$plugin_id}' required_by");
			}

			$required_by = array_values(array_unique($required_by));
			sort($required_by);

			if ($required_by !== []) {
				$locked_plugin['required_by'] = $required_by;
			}
		}

		return $locked_plugin;
	}

	private static function toRelativePath(string $path): string
	{
		$normalized = str_replace('\\', '/', $path);
		$root = str_replace('\\', '/', DEPLOY_ROOT);

		if (str_starts_with($normalized, $root)) {
			$normalized = substr($normalized, strlen($root));
		}

		return ltrim($normalized, '/');
	}

	private static function toPathForStorage(string $absolute_path, string $base_dir): string
	{
		$absolute = self::normalizePath($absolute_path);
		$base = rtrim(self::normalizePath($base_dir), '/');

		if ($absolute === $base) {
			return '.';
		}

		if (str_starts_with($absolute . '/', $base . '/')) {
			return ltrim(substr($absolute, strlen($base)), '/');
		}

		return $absolute;
	}

	private static function normalizePath(string $path): string
	{
		$path = str_replace('\\', '/', $path);
		$real = realpath($path);

		if ($real !== false) {
			return rtrim(str_replace('\\', '/', $real), '/');
		}

		return rtrim($path, '/');
	}

	/**
	 * @param array<string, array<string, mixed>> $current_lock_plugins
	 * @param array<string, array<string, mixed>> $next_lock_plugins
	 * @return array<string, string>
	 */
	private static function collectStaleRegistryPaths(
		array $current_lock_plugins,
		array $next_lock_plugins,
		string $lock_base_dir
	): array {
		$paths = [];

		foreach ($current_lock_plugins as $plugin_id => $current_locked_plugin) {
			$current_source = is_array($current_locked_plugin['source'] ?? null) ? $current_locked_plugin['source'] : [];
			$current_resolved = is_array($current_locked_plugin['resolved'] ?? null) ? $current_locked_plugin['resolved'] : [];
			$current_type = $current_resolved['type'] ?? $current_source['type'] ?? null;

			if ($current_type !== 'registry') {
				continue;
			}

			$next_locked_plugin = $next_lock_plugins[$plugin_id] ?? null;
			$next_source = is_array($next_locked_plugin['source'] ?? null) ? $next_locked_plugin['source'] : [];
			$next_resolved = is_array($next_locked_plugin['resolved'] ?? null) ? $next_locked_plugin['resolved'] : [];
			$next_type = $next_resolved['type'] ?? $next_source['type'] ?? null;

			if ($next_type === 'registry') {
				continue;
			}

			$path = self::resolveLockStoredPath(
				is_string($current_resolved['path'] ?? null)
					? $current_resolved['path']
					: 'plugins/registry/' . PluginIdHelper::normalize($plugin_id, 'Registry plugin'),
				$lock_base_dir
			);

			if (is_dir($path)) {
				$paths[$plugin_id] = $path;
			}
		}

		ksort($paths);

		return $paths;
	}

	private static function resolveLockStoredPath(string $path, string $base_dir): string
	{
		$normalized = str_replace('\\', '/', $path);

		if (str_starts_with($normalized, '/')) {
			return self::normalizePath($normalized);
		}

		return self::normalizePath(rtrim($base_dir, '/') . '/' . ltrim($normalized, '/'));
	}

	/**
	 * @param array<string, string> $paths
	 */
	private static function removeStaleRegistryDirectories(array $paths): void
	{
		foreach ($paths as $path) {
			self::removeDirectory($path);
		}
	}

	private static function rebuildAutoloader(): void
	{
		$initial_output_buffers = ob_get_level();
		ob_start();
		CLICommandBuildAutoloader::create();

		while (ob_get_level() > $initial_output_buffers) {
			ob_end_clean();
		}

		AutoloaderFromGeneratedMap::reset();
		AutoloaderFailsafe::reset();
		PackagePathHelper::reset();
	}

	private static function rebuildTemplates(): void
	{
		$initial_output_buffers = ob_get_level();
		ob_start();
		CLICommandBuildTemplates::create();

		while (ob_get_level() > $initial_output_buffers) {
			ob_end_clean();
		}
	}

	private static function rebuildWidgets(): void
	{
		$initial_output_buffers = ob_get_level();
		ob_start();
		CLICommandBuildWidgets::create();

		while (ob_get_level() > $initial_output_buffers) {
			ob_end_clean();
		}
	}

	private static function rebuildRuntimeRegistry(): void
	{
		$initial_output_buffers = ob_get_level();
		ob_start();
		CLICommandBuildPlugins::create();

		while (ob_get_level() > $initial_output_buffers) {
			ob_end_clean();
		}
	}

	/**
	 * @return array{
	 *     discovered: array<string, array<string, mixed>>|null,
	 *     by_base_path: array<string, array<string, mixed>>|null
	 * }
	 */
	private static function createDiscoveryState(): array
	{
		return [
			'discovered' => null,
			'by_base_path' => null,
		];
	}

	/**
	 * @param array{
	 *     discovered: array<string, array<string, mixed>>|null,
	 *     by_base_path: array<string, array<string, mixed>>|null
	 * } $discovery_state
	 */
	private static function ensureDiscoveryState(array &$discovery_state): void
	{
		if ($discovery_state['discovered'] !== null && $discovery_state['by_base_path'] !== null) {
			return;
		}

		$discovered = PluginDescriptorDiscovery::discover();
		$by_base_path = [];

		foreach ($discovered as $plugin) {
			$by_base_path[$plugin['base_path']] = $plugin;
		}

		$discovery_state['discovered'] = $discovered;
		$discovery_state['by_base_path'] = $by_base_path;
	}

	/**
	 * @param array<string, mixed> $manifest_plugin
	 * @param array{
	 *     discovered: array<string, array<string, mixed>>|null,
	 *     by_base_path: array<string, array<string, mixed>>|null
	 * } $discovery_state
	 * @return array<string, mixed>
	 */
	private static function buildDevLockedPlugin(
		string $plugin_id,
		array $manifest_plugin,
		string $lock_base_dir,
		array &$discovery_state
	): array {
		$plugin_id = PluginIdHelper::normalize($plugin_id, 'Dev plugin');
		self::ensureDiscoveryState($discovery_state);

		$source = $manifest_plugin['source'];
		$source_path = $source['path'] ?? null;
		$resolved_path = $source['resolved_path'] ?? null;

		if (!is_string($source_path) || $source_path === '') {
			$source_path = 'plugins/dev/' . $plugin_id;
		}

		if (!is_string($resolved_path) || !is_dir($resolved_path)) {
			$resolved_path = str_starts_with($source_path, '/')
				? rtrim($source_path, '/')
				: rtrim(DEPLOY_ROOT . ltrim($source_path, '/'), '/');
		}

		if (!is_dir($resolved_path)) {
			throw new RuntimeException("Dev plugin '{$plugin_id}' path does not exist: {$source_path}");
		}

		$base_path = self::toRelativePath($resolved_path);
		$descriptor = $discovery_state['by_base_path'][$base_path] ?? ($discovery_state['discovered'][$plugin_id] ?? null);

		if ($descriptor === null) {
			throw new RuntimeException("Plugin descriptor not found for '{$plugin_id}' under {$base_path}");
		}

		if ($descriptor['id'] !== $plugin_id) {
			throw new RuntimeException("Dev plugin '{$plugin_id}' resolved to descriptor id '{$descriptor['id']}'.");
		}

		$metadata = PluginPackageMetadataHelper::loadFromSourcePath($resolved_path);

		if ($metadata['plugin_id'] !== $plugin_id) {
			throw new RuntimeException("Dev plugin '{$plugin_id}' metadata plugin_id '{$metadata['plugin_id']}' does not match the descriptor.");
		}

		$manifest_package = $manifest_plugin['package'] ?? null;

		if (is_string($manifest_package) && $manifest_package !== '' && $metadata['package'] !== $manifest_package) {
			throw new RuntimeException("Dev plugin '{$plugin_id}' metadata package '{$metadata['package']}' does not match manifest package '{$manifest_package}'.");
		}

		return [
			'package' => $manifest_package ?? $metadata['package'],
			'plugin_id' => $plugin_id,
			'source' => [
				'type' => 'dev',
				'path' => $source_path,
			],
			'resolved' => [
				'type' => 'dev',
				'path' => self::toPathForStorage($resolved_path, $lock_base_dir),
				'version' => $metadata['version'],
			],
			'descriptor_class' => $descriptor['descriptor_class'],
			'descriptor_file' => $descriptor['descriptor_file'],
			'dependencies' => $metadata['dependencies'],
			'composer' => [
				'require' => $metadata['composer']['require'],
			],
		];
	}

	/**
	 * @param array<string, mixed> $manifest_plugin
	 * @param array<string, mixed>|null $current_locked_plugin
	 * @param array{
	 *     discovered: array<string, array<string, mixed>>|null,
	 *     by_base_path: array<string, array<string, mixed>>|null
	 * } $discovery_state
	 * @return array<string, mixed>
	 */
	private static function buildRegistryLockedPlugin(
		string $plugin_id,
		array $manifest_plugin,
		?array $current_locked_plugin,
		string $lock_base_dir,
		bool $dry_run,
		array &$discovery_state
	): array {
		$source = $manifest_plugin['source'];
		$registry_reference = $source['registry'] ?? null;
		$registry_url = $source['resolved_registry_url'] ?? $registry_reference ?? null;
		$requested_version = $source['version'] ?? null;
		$package = $manifest_plugin['package'] ?? null;

		if (!is_string($registry_reference) || $registry_reference === '') {
			throw new RuntimeException("Registry plugin '{$plugin_id}' is missing source.registry.");
		}

		if (!is_string($registry_url) || $registry_url === '') {
			throw new RuntimeException("Registry plugin '{$plugin_id}' has an unresolved registry URL.");
		}

		if (!is_string($package) || $package === '') {
			throw new RuntimeException("Registry plugin '{$plugin_id}' is missing package.");
		}

		$resolved_package = PluginRegistryClient::resolvePackage([
			'name' => $registry_reference,
			'resolved_url' => $registry_url,
		], $package, is_string($requested_version) ? $requested_version : null);

		if ($resolved_package['plugin_id'] !== $plugin_id) {
			throw new RuntimeException("Registry package '{$package}' resolved to plugin id '{$resolved_package['plugin_id']}', expected '{$plugin_id}'.");
		}

		$target_relative_path = 'plugins/registry/' . $plugin_id;
		$target_absolute_path = DEPLOY_ROOT . $target_relative_path;
		$next_locked_plugin = [
			'package' => $package,
			'plugin_id' => $plugin_id,
			'source' => array_filter([
				'type' => 'registry',
				'registry' => $registry_url,
				'version' => is_string($requested_version) && $requested_version !== '' ? $requested_version : null,
			], static fn (mixed $value): bool => $value !== null),
			'resolved' => [
				'type' => 'registry',
				'registry' => $registry_url,
				'version' => $resolved_package['version'],
				'path' => self::toPathForStorage($target_absolute_path, $lock_base_dir),
				'dist_url' => $resolved_package['dist']['url'],
			],
			'dependencies' => PluginDependencyHelper::normalizeDependencies(
				$resolved_package['dependencies'],
				"Plugin '{$plugin_id}'"
			),
			'composer' => [
				'require' => PluginDependencyHelper::normalizeDependencies(
					$resolved_package['composer_require'],
					"Plugin '{$plugin_id}' composer.require"
				),
			],
		];

		$next_locked_plugin['resolved']['dist_sha256'] = $resolved_package['dist']['sha256'];

		$install_required = !is_dir($target_absolute_path);

		if ($current_locked_plugin !== null) {
			$install_required = $install_required
				|| PluginLockfile::exportPlugin($current_locked_plugin, $plugin_id) !== PluginLockfile::exportPlugin($next_locked_plugin, $plugin_id);
		}

		if (!$dry_run && $install_required) {
			self::installRegistryPlugin($plugin_id, $resolved_package, $target_absolute_path);
			AutoloaderFailsafe::reset();
			PackagePathHelper::reset();
			PluginRegistry::clearGeneratedPluginsCache();
			$discovery_state = self::createDiscoveryState();
		}

		if (!$dry_run && !is_dir($target_absolute_path)) {
			throw new RuntimeException("Registry plugin '{$plugin_id}' is not installed at {$target_relative_path}.");
		}

		if ($dry_run && !is_dir($target_absolute_path) && $current_locked_plugin === null) {
			return $next_locked_plugin;
		}

		self::ensureDiscoveryState($discovery_state);

		$descriptor = $discovery_state['by_base_path'][$target_relative_path] ?? ($discovery_state['discovered'][$plugin_id] ?? null);

		if ($descriptor === null) {
			if ($dry_run) {
				return $next_locked_plugin;
			}

			throw new RuntimeException("Installed registry plugin descriptor not found for '{$plugin_id}'.");
		}

		if ($descriptor['id'] !== $plugin_id) {
			throw new RuntimeException("Installed registry plugin '{$plugin_id}' resolved to descriptor id '{$descriptor['id']}'.");
		}

		$next_locked_plugin['descriptor_class'] = $descriptor['descriptor_class'];
		$next_locked_plugin['descriptor_file'] = $descriptor['descriptor_file'];

		return $next_locked_plugin;
	}

	/**
	 * @param array<string, array<string, mixed>> $manifest_plugins
	 * @param array<string, array<string, mixed>> $current_lock_plugins
	 * @param array<string, array<string, mixed>> $next_plugins
	 * @param array<string, array<string, mixed>> $plugin_summaries
	 * @param array{
	 *     discovered: array<string, array<string, mixed>>|null,
	 *     by_base_path: array<string, array<string, mixed>>|null
	 * } $discovery_state
	 */
	private static function resolveAutoInstalledRegistryDependencies(
		array $manifest_plugins,
		array $current_lock_plugins,
		array &$next_plugins,
		array &$plugin_summaries,
		string $lock_base_dir,
		bool $dry_run,
		array &$discovery_state
	): void {
		$manifest_package_map = PluginDependencyHelper::buildPackageToPluginMap($manifest_plugins);
		$processed_requests = [];

		while (true) {
			$requests = self::collectAutoInstallDependencyRequests($next_plugins, $manifest_package_map);
			$changed = false;

			foreach ($requests as $package => $request) {
				$signature = json_encode($request, JSON_THROW_ON_ERROR);

				if (($processed_requests[$package] ?? null) === $signature) {
					continue;
				}

				$processed_requests[$package] = $signature;
				$registry_url = $request['registry_url'];
				$constraint = PluginVersionHelper::combineConstraints($request['constraints']);

				try {
					$resolved_package = PluginRegistryClient::resolvePackage([
						'name' => $registry_url,
						'resolved_url' => $registry_url,
					], $package, $constraint === '' ? null : $constraint);
				} catch (RuntimeException $e) {
					throw new RuntimeException(
						"Plugin dependency resolution failed. Unable to resolve dependency '{$package}' for "
						. implode(', ', $request['required_by']) . ': ' . $e->getMessage(),
						0,
						$e
					);
				}
				$dependency_plugin_id = $resolved_package['plugin_id'];
				$current_locked_plugin = $next_plugins[$dependency_plugin_id]
					?? ($current_lock_plugins[$dependency_plugin_id] ?? null);
				$locked_plugin = self::buildRegistryLockedPlugin(
					$dependency_plugin_id,
					[
						'package' => $package,
						'source' => [
							'type' => 'registry',
							'registry' => $registry_url,
							'resolved_registry_url' => $registry_url,
							'version' => $constraint === '' ? null : $constraint,
						],
					],
					$current_locked_plugin,
					$lock_base_dir,
					$dry_run,
					$discovery_state
				);
				$locked_plugin['auto_installed'] = true;
				$locked_plugin['required_by'] = $request['required_by'];
				$action = !isset($current_lock_plugins[$dependency_plugin_id])
					? 'auto_added'
					: (
						PluginLockfile::exportPlugin($current_lock_plugins[$dependency_plugin_id], $dependency_plugin_id)
						=== PluginLockfile::exportPlugin($locked_plugin, $dependency_plugin_id)
							? 'unchanged'
							: 'auto_updated'
					);

				$previous_export = isset($next_plugins[$dependency_plugin_id])
					? PluginLockfile::exportPlugin($next_plugins[$dependency_plugin_id], $dependency_plugin_id)
					: null;
				$next_plugins[$dependency_plugin_id] = $locked_plugin;
				$plugin_summaries[$dependency_plugin_id] = self::buildPluginSummary(
					$dependency_plugin_id,
					null,
					$locked_plugin,
					$current_lock_plugins[$dependency_plugin_id] ?? null,
					$action
				);

				if ($previous_export !== PluginLockfile::exportPlugin($locked_plugin, $dependency_plugin_id)) {
					$changed = true;
				}
			}

			if (!$changed) {
				return;
			}
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $plugins
	 * @return list<string>
	 */
	private static function validateAndOrderPlugins(array $plugins): array
	{
		$missing = PluginDependencyHelper::findMissingDependencies($plugins);

		if ($missing !== []) {
			$messages = [];

			foreach ($missing as $plugin_id => $dependencies) {
				$messages[] = "{$plugin_id} -> " . implode(', ', array_keys($dependencies));
			}

			throw new RuntimeException('Plugin dependency resolution failed. Missing dependencies: ' . implode('; ', $messages));
		}

		$mismatches = PluginDependencyHelper::findDependencyVersionMismatches($plugins);

		if ($mismatches !== []) {
			$messages = [];

			foreach ($mismatches as $plugin_id => $dependencies) {
				$parts = [];

				foreach ($dependencies as $package => $mismatch) {
					$resolved_version = $mismatch['resolved_version'] ?? 'unknown';
					$parts[] = "{$package} ({$resolved_version} does not satisfy {$mismatch['constraint']})";
				}

				$messages[] = "{$plugin_id} -> " . implode(', ', $parts);
			}

			throw new RuntimeException('Plugin dependency resolution failed. Version conflicts: ' . implode('; ', $messages));
		}

		return PluginDependencyHelper::sortPluginIdsByDependencies($plugins);
	}

	/**
	 * @param array<string, array<string, mixed>> $plugins
	 * @param array<string, string> $manifest_package_map
	 * @return array<string, array{
	 *     registry_url: string,
	 *     constraints: list<string>,
	 *     required_by: list<string>
	 * }>
	 */
	private static function collectAutoInstallDependencyRequests(array $plugins, array $manifest_package_map): array
	{
		$requests = [];

		foreach ($plugins as $plugin_id => $plugin) {
			$dependencies = PluginDependencyHelper::normalizeDependencies(
				$plugin['dependencies'] ?? [],
				"Plugin '{$plugin_id}'"
			);
			$resolved = is_array($plugin['resolved'] ?? null) ? $plugin['resolved'] : [];
			$source = is_array($plugin['source'] ?? null) ? $plugin['source'] : [];
			$source_type = $resolved['type'] ?? $source['type'] ?? null;

			foreach ($dependencies as $package => $constraint) {
				if (isset($manifest_package_map[$package])) {
					continue;
				}

				if ($source_type !== 'registry') {
					throw new RuntimeException(
						"Plugin dependency resolution failed. '{$plugin_id}' depends on '{$package}', but only registry plugins can auto-install missing dependencies."
					);
				}

				$registry_url = $resolved['registry'] ?? $source['registry'] ?? null;

				if (!is_string($registry_url) || $registry_url === '') {
					throw new RuntimeException(
						"Plugin dependency resolution failed. '{$plugin_id}' depends on '{$package}', but no registry URL is available for auto-install."
					);
				}

				if (!isset($requests[$package])) {
					$requests[$package] = [
						'registry_url' => $registry_url,
						'constraints' => [],
						'required_by' => [],
					];
				}

				if ($requests[$package]['registry_url'] !== $registry_url) {
					throw new RuntimeException(
						"Plugin dependency resolution failed. '{$package}' is required from multiple registries, which is not supported."
					);
				}

				$requests[$package]['constraints'][] = $constraint;
				$requests[$package]['required_by'][$plugin_id] = $plugin_id;
			}
		}

		$normalized_requests = [];

		foreach ($requests as $package => $request) {
			$constraints = array_values(array_unique($request['constraints']));
			sort($constraints);
			$required_by = array_values($request['required_by']);
			sort($required_by);
			$normalized_requests[$package] = [
				'registry_url' => $request['registry_url'],
				'constraints' => $constraints,
				'required_by' => $required_by,
			];
		}

		ksort($normalized_requests);

		return $normalized_requests;
	}

	/**
	 * @param array{
	 *     package: string,
	 *     version: string,
	 *     plugin_id: string,
	 *     dist: array{
	 *         type: string,
	 *         url: string,
	 *         sha256: string
	 *     }
	 * } $resolved_package
	 */
	private static function installRegistryPlugin(string $plugin_id, array $resolved_package, string $target_absolute_path): void
	{
		$plugin_id = PluginIdHelper::normalize($plugin_id, 'Registry plugin');

		if ($resolved_package['dist']['type'] !== 'zip') {
			throw new RuntimeException("Registry package '{$resolved_package['package']}' uses unsupported dist type '{$resolved_package['dist']['type']}'.");
		}

		if (!class_exists(ZipArchive::class)) {
			throw new RuntimeException('ZipArchive extension is required for registry plugin installs.');
		}

		$staging_root = rtrim(DEPLOY_ROOT, '/') . '/tmp/plugin-sync/' . $plugin_id . '-' . bin2hex(random_bytes(8));
		$archive_path = $staging_root . '/package.zip';
		$extract_path = $staging_root . '/extract';
		$backup_path = null;

		self::ensureDirectory(dirname($archive_path));
		self::ensureDirectory($extract_path);

		try {
			$archive_contents = file_get_contents($resolved_package['dist']['url']);

			if ($archive_contents === false) {
				throw new RuntimeException("Unable to download registry package '{$resolved_package['package']}' from {$resolved_package['dist']['url']}");
			}

			$actual_hash = hash('sha256', $archive_contents);

			if (!hash_equals($resolved_package['dist']['sha256'], $actual_hash)) {
				throw new RuntimeException("Registry package '{$resolved_package['package']}' failed SHA-256 verification.");
			}

			if (file_put_contents($archive_path, $archive_contents) === false) {
				throw new RuntimeException("Unable to store registry package archive for '{$resolved_package['package']}'.");
			}

			$zip = new ZipArchive();
			$zip_result = $zip->open($archive_path);

			if ($zip_result !== true) {
				throw new RuntimeException("Unable to open registry package archive for '{$resolved_package['package']}'.");
			}

			if (!$zip->extractTo($extract_path)) {
				$zip->close();

				throw new RuntimeException("Unable to extract registry package '{$resolved_package['package']}'.");
			}

			$zip->close();

			$plugin_root = self::resolveExtractedPluginRoot($extract_path);

			if (file_exists($target_absolute_path)) {
				$backup_path = dirname($target_absolute_path) . '/.' . basename($target_absolute_path) . '.bak-' . bin2hex(random_bytes(4));
				rename($target_absolute_path, $backup_path);
			}

			self::copyDirectory($plugin_root, $target_absolute_path);
		} catch (Throwable $e) {
			if (is_dir($target_absolute_path)) {
				self::removeDirectory($target_absolute_path);
			}

			if ($backup_path !== null && is_dir($backup_path)) {
				rename($backup_path, $target_absolute_path);
			}

			self::removeDirectory($staging_root);

			throw $e;
		}

		if ($backup_path !== null && is_dir($backup_path)) {
			self::removeDirectory($backup_path);
		}

		self::removeDirectory($staging_root);
	}

	private static function resolveExtractedPluginRoot(string $extract_path): string
	{
		$descriptor_matches = glob(rtrim($extract_path, '/') . '/Plugin.*.php') ?: [];

		if (count($descriptor_matches) === 1) {
			return rtrim($extract_path, '/');
		}

		$children = array_values(array_filter(
			scandir($extract_path) ?: [],
			static fn (string $entry): bool => $entry !== '.' && $entry !== '..'
		));

		if (count($children) === 1) {
			$candidate = rtrim($extract_path, '/') . '/' . $children[0];

			if (is_dir($candidate)) {
				$nested_matches = glob($candidate . '/Plugin.*.php') ?: [];

				if (count($nested_matches) === 1) {
					return $candidate;
				}
			}
		}

		throw new RuntimeException("Registry plugin archive does not expose exactly one descriptor file at its root: {$extract_path}");
	}

	private static function ensureDirectory(string $directory): void
	{
		if (is_dir($directory)) {
			return;
		}

		if (!mkdir($directory, 0o755, true) && !is_dir($directory)) {
			throw new RuntimeException("Unable to create directory: {$directory}");
		}
	}

	private static function copyDirectory(string $source, string $destination): void
	{
		self::ensureDirectory($destination);
		$items = scandir($source);

		if ($items === false) {
			throw new RuntimeException("Unable to read directory: {$source}");
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$source_path = $source . '/' . $item;
			$destination_path = $destination . '/' . $item;

			if (is_dir($source_path)) {
				self::copyDirectory($source_path, $destination_path);

				continue;
			}

			if (!copy($source_path, $destination_path)) {
				throw new RuntimeException("Unable to copy plugin file to {$destination_path}");
			}
		}
	}

	private static function removeDirectory(string $directory): void
	{
		if (!is_dir($directory)) {
			return;
		}

		$items = scandir($directory);

		if ($items === false) {
			return;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$path = $directory . '/' . $item;

			if (is_dir($path) && !is_link($path)) {
				self::removeDirectory($path);
			} elseif (file_exists($path) && !unlink($path)) {
				throw new RuntimeException("Unable to remove path: {$path}");
			}
		}

		if (!rmdir($directory)) {
			throw new RuntimeException("Unable to remove directory: {$directory}");
		}
	}
}
