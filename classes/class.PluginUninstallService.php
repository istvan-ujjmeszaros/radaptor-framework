<?php

class PluginUninstallService
{
	/**
	 * @return array{
	 *     dry_run: bool,
	 *     plugin_id: string,
	 *     manifest_updated: bool,
	 *     lockfile_updated: bool,
	 *     backup_created: bool,
	 *     backup_dir: string|null,
	 *     filesystem_removed: bool,
	 *     filesystem_path: string|null,
	 *     source_type: string|null,
	 *     composer_sync_ran: bool,
	 *     composer_sync: array<string, mixed>|null,
	 *     templates_rebuilt: bool,
	 *     widgets_rebuilt: bool,
	 *     autoloader_rebuilt: bool,
	 *     runtime_registry_rebuilt: bool,
	 *     lifecycle_hooks_ran: bool
	 * }
	 */
	public static function uninstall(string $plugin_id, bool $dry_run = false, bool $no_backup = false): array
	{
		return self::uninstallPaths(
			PluginManifest::getPath(),
			PluginLockfile::getPath(),
			$plugin_id,
			$dry_run,
			$no_backup,
			ComposerJsonHelper::getPath(),
			PluginComposerLockfile::getPath()
		);
	}

	/**
	 * @return array{
	 *     dry_run: bool,
	 *     plugin_id: string,
	 *     manifest_updated: bool,
	 *     lockfile_updated: bool,
	 *     backup_created: bool,
	 *     backup_dir: string|null,
	 *     filesystem_removed: bool,
	 *     filesystem_path: string|null,
	 *     source_type: string|null,
	 *     composer_sync_ran: bool,
	 *     composer_sync: array<string, mixed>|null,
	 *     templates_rebuilt: bool,
	 *     widgets_rebuilt: bool,
	 *     autoloader_rebuilt: bool,
	 *     runtime_registry_rebuilt: bool,
	 *     lifecycle_hooks_ran: bool
	 * }
	 */
	public static function uninstallPaths(
		string $manifest_path,
		string $lock_path,
		string $plugin_id,
		bool $dry_run = false,
		bool $no_backup = false,
		?string $composer_json_path = null,
		?string $plugin_composer_lock_path = null
	): array {
		$manifest = PluginManifest::loadFromPath($manifest_path);
		$lock = PluginLockfile::loadFromPath($lock_path);
		$manifest_plugin = $manifest['plugins'][$plugin_id] ?? null;
		$lock_plugin = $lock['plugins'][$plugin_id] ?? null;

		if (!is_array($manifest_plugin) && !is_array($lock_plugin)) {
			throw new RuntimeException("Plugin '{$plugin_id}' is not declared in the manifest or lockfile.");
		}

		self::assertNoInstalledDependants($plugin_id, $lock['plugins']);

		$source = is_array($manifest_plugin['source'] ?? null)
			? $manifest_plugin['source']
			: (is_array($lock_plugin['source'] ?? null) ? $lock_plugin['source'] : []);
		$source_type = is_string($source['type'] ?? null) ? $source['type'] : null;
		$backup_created = false;
		$backup_dir = null;
		$filesystem_removed = false;
		$filesystem_path = PluginBackupService::getFilesystemPath($manifest_plugin, $lock_plugin);
		$composer_sync_ran = false;
		$composer_sync = null;
		$staged_removed_filesystem_path = null;
		$config_example_removed = false;

		if (!$dry_run && !$no_backup) {
			$backup = PluginBackupService::backupPlugin($plugin_id, $manifest_plugin, $lock_plugin);
			$backup_created = true;
			$backup_dir = $backup['backup_dir'];
			$filesystem_path = $backup['filesystem_path'];
		}

		$next_manifest = $manifest;
		unset($next_manifest['plugins'][$plugin_id]);
		$next_lock = $lock;
		unset($next_lock['plugins'][$plugin_id]);
		$manifest_updated = array_key_exists($plugin_id, $manifest['plugins']);
		$lockfile_updated = array_key_exists($plugin_id, $lock['plugins']);
		$templates_rebuilt = false;
		$widgets_rebuilt = false;
		$autoloader_rebuilt = false;
		$runtime_registry_rebuilt = false;
		$lifecycle_hooks_ran = false;

		if (!$dry_run) {
			try {
				PluginLifecycleManager::runBeforeUninstall($plugin_id, $lock['plugins']);
				$lifecycle_hooks_ran = true;

				if ($manifest_updated) {
					PluginManifest::write($next_manifest, $manifest_path);
				}

				if ($lockfile_updated) {
					PluginLockfile::write($next_lock, $lock_path);
				}

				if ($source_type === 'registry' && $filesystem_path !== null && is_dir($filesystem_path)) {
					$staged_removed_filesystem_path = dirname($filesystem_path)
						. '/.'
						. basename($filesystem_path)
						. '.uninstall-'
						. bin2hex(random_bytes(4));

					if (!rename($filesystem_path, $staged_removed_filesystem_path)) {
						throw new RuntimeException("Unable to stage plugin directory for uninstall: {$filesystem_path}");
					}

					$filesystem_removed = true;
					AutoloaderFailsafe::reset();
					PackagePathHelper::reset();
				}

				PluginConfigExampleManager::removeExample($plugin_id);
				$config_example_removed = true;

				self::rebuildTemplates();
				self::rebuildWidgets();
				self::rebuildAutoloader();
				self::rebuildRuntimeRegistry();
				PackageConfig::reset();
				PackagePathHelper::reset();
				PluginRegistry::reset();
				$templates_rebuilt = true;
				$widgets_rebuilt = true;
				$autoloader_rebuilt = true;
				$runtime_registry_rebuilt = true;

				if ($composer_json_path !== null && $plugin_composer_lock_path !== null) {
					$composer_sync = self::syncComposerState(
						$next_lock,
						$composer_json_path,
						$plugin_composer_lock_path,
						false
					);
					$composer_sync_ran = true;
				}
			} catch (Throwable $e) {
				self::restoreFailedUninstallState(
					$manifest_updated ? $manifest : null,
					$manifest_path,
					$lockfile_updated ? $lock : null,
					$lock_path,
					$filesystem_path,
					$staged_removed_filesystem_path,
					$backup_dir,
					$config_example_removed ? $lock['plugins'] : null,
					$composer_json_path,
					$plugin_composer_lock_path
				);

				throw $e;
			}

			if ($staged_removed_filesystem_path !== null && is_dir($staged_removed_filesystem_path)) {
				self::removeDirectory($staged_removed_filesystem_path);
			}
		} elseif ($composer_json_path !== null && $plugin_composer_lock_path !== null) {
			$composer_sync = self::syncComposerState(
				$next_lock,
				$composer_json_path,
				$plugin_composer_lock_path,
				true
			);
			$composer_sync_ran = true;
		}

		return [
			'dry_run' => $dry_run,
			'plugin_id' => $plugin_id,
			'manifest_updated' => $manifest_updated,
			'lockfile_updated' => $lockfile_updated,
			'backup_created' => $backup_created,
			'backup_dir' => $backup_dir,
			'filesystem_removed' => $filesystem_removed,
			'filesystem_path' => $filesystem_path,
			'source_type' => $source_type,
			'composer_sync_ran' => $composer_sync_ran,
			'composer_sync' => $composer_sync,
			'templates_rebuilt' => $templates_rebuilt,
			'widgets_rebuilt' => $widgets_rebuilt,
			'autoloader_rebuilt' => $autoloader_rebuilt,
			'runtime_registry_rebuilt' => $runtime_registry_rebuilt,
			'lifecycle_hooks_ran' => $lifecycle_hooks_ran,
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
	 * @param array<string, array<string, mixed>> $installed_plugins
	 */
	private static function assertNoInstalledDependants(string $plugin_id, array $installed_plugins): void
	{
		$target_plugin = $installed_plugins[$plugin_id] ?? null;

		if (!is_array($target_plugin)) {
			return;
		}

		$target_package = trim((string) ($target_plugin['package'] ?? ''));

		if ($target_package === '') {
			return;
		}

		$dependants = [];

		foreach ($installed_plugins as $installed_plugin_id => $installed_plugin) {
			if ($installed_plugin_id === $plugin_id) {
				continue;
			}

			$dependencies = PluginDependencyHelper::normalizeDependencies(
				$installed_plugin['dependencies'] ?? [],
				"Plugin '{$installed_plugin_id}'"
			);

			if (isset($dependencies[$target_package])) {
				$dependants[] = "{$installed_plugin_id} ({$dependencies[$target_package]})";
			}
		}

		if ($dependants !== []) {
			sort($dependants);

			throw new RuntimeException(
				"Plugin '{$plugin_id}' cannot be uninstalled because it is required by: " . implode(', ', $dependants)
			);
		}
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
	 * @param array{
	 *     lockfile_version?: int,
	 *     plugins: array<string, array<string, mixed>>
	 * }|null $manifest
	 * @param array{
	 *     lockfile_version?: int,
	 *     plugins: array<string, array<string, mixed>>
	 * }|null $lock
	 * @param array<string, array<string, mixed>>|null $plugins_for_examples
	 */
	private static function restoreFailedUninstallState(
		?array $manifest,
		string $manifest_path,
		?array $lock,
		string $lock_path,
		?string $filesystem_path,
		?string $staged_removed_filesystem_path,
		?string $backup_dir,
		?array $plugins_for_examples,
		?string $composer_json_path,
		?string $plugin_composer_lock_path
	): void {
		if ($manifest !== null) {
			PluginManifest::write($manifest, $manifest_path);
		}

		if ($lock !== null) {
			PluginLockfile::write($lock, $lock_path);
		}

		if ($filesystem_path !== null) {
			if ($staged_removed_filesystem_path !== null && is_dir($staged_removed_filesystem_path)) {
				if (is_dir($filesystem_path)) {
					self::removeDirectory($filesystem_path);
				}

				rename($staged_removed_filesystem_path, $filesystem_path);
			} elseif (
				$backup_dir !== null
				&& is_dir($backup_dir . '/filesystem')
				&& !is_dir($filesystem_path)
			) {
				self::copyDirectory($backup_dir . '/filesystem', $filesystem_path);
			}
		}

		if ($plugins_for_examples !== null) {
			PluginConfigExampleManager::syncInstalledExamples($plugins_for_examples);
		}

		if ($lock !== null && $composer_json_path !== null && $plugin_composer_lock_path !== null) {
			try {
				self::syncComposerState($lock, $composer_json_path, $plugin_composer_lock_path, false);
			} catch (Throwable) {
			}
		}

		try {
			self::rebuildTemplates();
			self::rebuildWidgets();
			self::rebuildAutoloader();
			self::rebuildRuntimeRegistry();
			PackageConfig::reset();
			PackagePathHelper::reset();
			PluginRegistry::reset();
		} catch (Throwable) {
		}
	}

	private static function copyDirectory(string $source, string $destination): void
	{
		if (!is_dir($destination) && !mkdir($destination, 0o755, true) && !is_dir($destination)) {
			throw new RuntimeException("Unable to create directory: {$destination}");
		}

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

			if (is_dir($source_path) && !is_link($source_path)) {
				self::copyDirectory($source_path, $destination_path);

				continue;
			}

			if (!copy($source_path, $destination_path)) {
				throw new RuntimeException("Unable to restore plugin file: {$destination_path}");
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
			throw new RuntimeException("Unable to read directory: {$directory}");
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$path = $directory . '/' . $item;

			if (is_dir($path) && !is_link($path)) {
				self::removeDirectory($path);

				continue;
			}

			if (!unlink($path)) {
				throw new RuntimeException("Unable to remove path: {$path}");
			}
		}

		if (!rmdir($directory)) {
			throw new RuntimeException("Unable to remove directory: {$directory}");
		}
	}
}
