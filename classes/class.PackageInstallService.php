<?php

require_once __DIR__ . '/class.PackageLocalOverrideHelper.php';

class PackageInstallService
{
	private const int REGISTRY_DOWNLOAD_TIMEOUT_SECONDS = 30;

	/**
	 * @return array<string, mixed>
	 */
	public static function install(
		bool $dry_run = false,
		bool $include_demo_seeds = false,
		bool $rerun_demo_seeds = false,
		bool $skip_seeds = false,
		?callable $confirm_demo_rerun = null,
		bool $ignore_local_overrides = false
	): array {
		return self::syncUsingEffectiveDocuments(
			false,
			$dry_run,
			$include_demo_seeds,
			$rerun_demo_seeds,
			$skip_seeds,
			$confirm_demo_rerun,
			$ignore_local_overrides
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function update(
		bool $dry_run = false,
		bool $include_demo_seeds = false,
		bool $rerun_demo_seeds = false,
		bool $skip_seeds = false,
		?callable $confirm_demo_rerun = null,
		bool $ignore_local_overrides = false
	): array {
		return self::syncUsingEffectiveDocuments(
			true,
			$dry_run,
			$include_demo_seeds,
			$rerun_demo_seeds,
			$skip_seeds,
			$confirm_demo_rerun,
			$ignore_local_overrides
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function refreshLocalLock(
		bool $dry_run = false,
		bool $include_demo_seeds = false,
		bool $rerun_demo_seeds = false,
		bool $skip_seeds = false,
		?callable $confirm_demo_rerun = null,
		bool $ignore_local_overrides = false
	): array {
		return self::syncUsingEffectiveDocuments(
			false,
			$dry_run,
			$include_demo_seeds,
			$rerun_demo_seeds,
			$skip_seeds,
			$confirm_demo_rerun,
			$ignore_local_overrides,
			true
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function syncUsingEffectiveDocuments(
		bool $update,
		bool $dry_run,
		bool $include_demo_seeds,
		bool $rerun_demo_seeds,
		bool $skip_seeds,
		?callable $confirm_demo_rerun,
		bool $ignore_local_overrides = false,
		bool $refresh_local_lock = false
	): array {
		$manifest = PackageLocalOverrideHelper::loadEffectiveManifest($ignore_local_overrides);
		$local_override_active = PackageLocalOverrideHelper::isLocalOverrideActive($ignore_local_overrides);
		$write_lock_path = PackageLockfile::getPath($ignore_local_overrides);
		$read_lock_path = $write_lock_path;

		if ($local_override_active && ($refresh_local_lock || !is_file($write_lock_path))) {
			$read_lock_path = PackageLockfile::getCommittedPath();
		}

		if (
			$refresh_local_lock
			&& !$local_override_active
		) {
			throw new RuntimeException('Local lock refresh requires an active and valid radaptor.local.json override.');
		}

		return self::syncResolvedState(
			$manifest,
			$read_lock_path,
			$write_lock_path,
			$update,
			$dry_run,
			PluginManifest::getPath(),
			PluginLockfile::getPath(),
			ComposerJsonHelper::getPath(),
			PluginComposerLockfile::getPath(),
			PackageAssetsBuilder::getStatePath(),
			$include_demo_seeds,
			$rerun_demo_seeds,
			$skip_seeds,
			$confirm_demo_rerun
		);
	}

	/**
	 * @return array{
	 *     mode: string,
	 *     dry_run: bool,
	 *     lockfile_changed: bool,
	 *     lockfile_written: bool,
	 *     packages_processed: int,
	 *     packages_removed: int,
	 *     removed_package_keys: string[],
	 *     packages: array<int, array<string, mixed>>,
	 *     plugin_bridge_written: bool,
	 *     plugin_sync: array<string, mixed>|null,
	 *     package_migrations_ran: bool,
	 *     package_migrations: array<int, array<string, mixed>>|null,
	 *     seeds_ran: bool,
	 *     seeds: array<string, mixed>|null,
	 *     assets_built: bool,
	 *     assets: array<string, mixed>|null
	 * }
	 */
	public static function syncPaths(
		string $manifest_path,
		string $lock_path,
		bool $update = false,
		bool $dry_run = false,
		?string $plugin_manifest_path = null,
		?string $plugin_lock_path = null,
		?string $composer_json_path = null,
		?string $plugin_composer_lock_path = null,
		?string $assets_state_path = null,
		bool $include_demo_seeds = false,
		bool $rerun_demo_seeds = false,
		bool $skip_seeds = false,
		?callable $confirm_demo_rerun = null
	): array {
		return self::syncResolvedState(
			PackageManifest::loadFromPath($manifest_path),
			$lock_path,
			$lock_path,
			$update,
			$dry_run,
			$plugin_manifest_path,
			$plugin_lock_path,
			$composer_json_path,
			$plugin_composer_lock_path,
			$assets_state_path,
			$include_demo_seeds,
			$rerun_demo_seeds,
			$skip_seeds,
			$confirm_demo_rerun
		);
	}

	/**
	 * @param array{
	 *     manifest_version: int,
	 *     registries: array<string, array{name: string, url: string, resolved_url: string}>,
	 *     packages: array<string, array<string, mixed>>,
	 *     path: string,
	 *     base_dir: string
	 * } $manifest
	 * @return array{
	 *     mode: string,
	 *     dry_run: bool,
	 *     lockfile_changed: bool,
	 *     lockfile_written: bool,
	 *     packages_processed: int,
	 *     packages_removed: int,
	 *     removed_package_keys: string[],
	 *     packages: array<int, array<string, mixed>>,
	 *     plugin_bridge_written: bool,
	 *     plugin_sync: array<string, mixed>|null,
	 *     package_migrations_ran: bool,
	 *     package_migrations: array<int, array<string, mixed>>|null,
	 *     seeds_ran: bool,
	 *     seeds: array<string, mixed>|null,
	 *     assets_built: bool,
	 *     assets: array<string, mixed>|null
	 * }
	 */
	private static function syncResolvedState(
		array $manifest,
		string $read_lock_path,
		string $write_lock_path,
		bool $update = false,
		bool $dry_run = false,
		?string $plugin_manifest_path = null,
		?string $plugin_lock_path = null,
		?string $composer_json_path = null,
		?string $plugin_composer_lock_path = null,
		?string $assets_state_path = null,
		bool $include_demo_seeds = false,
		bool $rerun_demo_seeds = false,
		bool $skip_seeds = false,
		?callable $confirm_demo_rerun = null
	): array {
		$current_lock = file_exists($read_lock_path)
			? PackageLockfile::loadFromPath($read_lock_path)
			: [
				'lockfile_version' => 1,
				'packages' => [],
				'path' => $write_lock_path,
				'base_dir' => dirname($write_lock_path),
			];
		$next = self::buildNextLockState($manifest, $current_lock, dirname($write_lock_path), $update);
		$next_lock = [
			'lockfile_version' => max(1, (int) $current_lock['lockfile_version']),
			'packages' => $next['packages'],
		];
		$current_export = PackageLockfile::exportDocument($current_lock);
		$next_lock_for_storage = self::sanitizeLockfileForStorage($next_lock, $manifest['registries']);
		$next_export = PackageLockfile::exportDocument($next_lock_for_storage);
		$must_initialize_target_lock = $read_lock_path !== $write_lock_path && !file_exists($write_lock_path);
		$lockfile_changed = $current_export !== $next_export || $must_initialize_target_lock;
		$lockfile_written = false;
		$plugin_bridge_written = false;
		$plugin_sync = null;
		$package_migrations_ran = false;
		$package_migrations = null;
		$seeds_ran = false;
		$seeds = null;
		$assets_built = false;
		$assets = null;

		if (!$dry_run) {
			self::removeStaleRegistryDirectories($current_lock['packages'], $next_lock['packages']);
			self::installRegistryPackages($current_lock['packages'], $next_lock['packages']);
			self::refreshInstalledPackageDiscoveryState();

			if ($plugin_manifest_path !== null && $plugin_lock_path !== null) {
				PackageBridgeHelper::writePluginManifest($plugin_manifest_path, $next_lock['packages']);
				$plugin_bridge_written = true;
				$plugin_sync = PluginSyncService::syncPaths(
					$plugin_manifest_path,
					$plugin_lock_path,
					false,
					$composer_json_path,
					$plugin_composer_lock_path,
					run_plugin_migrations: false
				);
			}

			if ($assets_state_path !== null) {
				$temp_lock_path = self::writeTempLockfile($next_lock, dirname($write_lock_path));

				try {
					$assets = PackageAssetsBuilder::buildPaths($temp_lock_path, $assets_state_path, false, DEPLOY_ROOT);
					$assets_built = true;
				} finally {
					self::removeTempFile($temp_lock_path);
				}
			}

			if ($lockfile_changed) {
				PackageLockfile::write($next_lock_for_storage, $write_lock_path);
				$lockfile_written = true;
			}

			self::rebuildAutoloader();
			self::rebuildThemeData();

			$package_migrations = MigrationRunner::runPendingForModules(
				self::buildMigrationModules($next['ordered_package_keys'])
			);
			$package_migrations_ran = true;

			if (!self::hasFailedMigration($package_migrations)) {
				$seeds = SeedRunner::runPaths(
					$write_lock_path,
					DEPLOY_ROOT . 'app',
					$include_demo_seeds,
					$rerun_demo_seeds,
					$skip_seeds,
					false,
					$confirm_demo_rerun
				);
				$seeds_ran = true;

				ob_start();

				try {
					CLICommandBuildRoles::create();
				} finally {
					ob_end_clean();
				}

				self::rebuildEventDocs();
			}

			PackageConfig::reset();
			PackagePathHelper::reset();
			PackageThemeScanHelper::reset();
		} else {
			if ($plugin_manifest_path !== null && $plugin_lock_path !== null) {
				$temp_plugin_manifest_path = self::writeTempPluginManifest($next_lock['packages'], dirname($plugin_manifest_path));

				try {
					$plugin_sync = PluginSyncService::syncPaths(
						$temp_plugin_manifest_path,
						$plugin_lock_path,
						true,
						$composer_json_path,
						$plugin_composer_lock_path
					);
				} finally {
					self::removeTempFile($temp_plugin_manifest_path);
				}
			}

			if ($assets_state_path !== null) {
				$temp_lock_path = self::writeTempLockfile($next_lock, dirname($write_lock_path));

				try {
					$assets = PackageAssetsBuilder::buildPaths($temp_lock_path, $assets_state_path, true, DEPLOY_ROOT);
					$assets_built = true;
				} finally {
					self::removeTempFile($temp_lock_path);
				}
			}

			$temp_lock_path = self::writeTempLockfile($next_lock, dirname($write_lock_path));

			try {
				$seeds = SeedRunner::runPaths(
					$temp_lock_path,
					DEPLOY_ROOT . 'app',
					$include_demo_seeds,
					$rerun_demo_seeds,
					$skip_seeds,
					true,
					$confirm_demo_rerun
				);
				$seeds_ran = true;
			} finally {
				self::removeTempFile($temp_lock_path);
			}
		}

		return [
			'mode' => $update ? 'update' : 'install',
			'dry_run' => $dry_run,
			'lockfile_changed' => $lockfile_changed,
			'lockfile_written' => $lockfile_written,
			'packages_processed' => count($next['package_summaries']),
			'packages_removed' => count($next['removed_package_keys']),
			'removed_package_keys' => $next['removed_package_keys'],
			'packages' => array_values($next['package_summaries']),
			'plugin_bridge_written' => $plugin_bridge_written,
			'plugin_sync' => $plugin_sync,
			'package_migrations_ran' => $package_migrations_ran,
			'package_migrations' => $package_migrations,
			'seeds_ran' => $seeds_ran,
			'seeds' => $seeds,
			'assets_built' => $assets_built,
			'assets' => $assets,
		];
	}

	/**
	 * @param array{
	 *     lockfile_version: int,
	 *     packages: array<string, array<string, mixed>>
	 * } $lockfile
	 * @param array<string, array{name: string, url: string, resolved_url: string}> $registries
	 * @return array{
	 *     lockfile_version: int,
	 *     packages: array<string, array<string, mixed>>
	 * }
	 */
	private static function sanitizeLockfileForStorage(array $lockfile, array $registries): array
	{
		$sanitized = $lockfile;

		foreach ($sanitized['packages'] as &$package) {
			$source = is_array($package['source'] ?? null) ? $package['source'] : null;
			$resolved = is_array($package['resolved'] ?? null) ? $package['resolved'] : null;
			$registry_name = is_array($source) ? trim((string) ($source['registry'] ?? '')) : '';

			if ($registry_name === '' || !isset($registries[$registry_name])) {
				continue;
			}

			$declared_registry_url = trim((string) $registries[$registry_name]['url']);

			if ($declared_registry_url === '') {
				continue;
			}

			$current_registry_url = '';

			if ($resolved !== null) {
				$current_registry_url = trim((string) ($source['resolved_registry_url'] ?? $resolved['registry_url'] ?? ''));
			}

			if ($source !== null) {
				$package['source']['resolved_registry_url'] = $declared_registry_url;
			}

			if ($resolved !== null) {
				$package['resolved']['registry_url'] = $declared_registry_url;

				if (is_string($resolved['dist_url'] ?? null) && trim((string) $resolved['dist_url']) !== '') {
					$package['resolved']['dist_url'] = self::rebaseRegistryArtifactUrlForStorage(
						trim((string) $resolved['dist_url']),
						$current_registry_url,
						$declared_registry_url
					);
				}
			}
		}
		unset($package);

		return $sanitized;
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

	private static function rebuildEventDocs(): void
	{
		$initial_output_buffers = ob_get_level();
		ob_start();
		CLICommandBuildEventDocs::create();

		while (ob_get_level() > $initial_output_buffers) {
			ob_end_clean();
		}
	}

	private static function hasSameUrlAuthority(array $left_parts, array $right_parts): bool
	{
		return strtolower((string) ($left_parts['scheme'] ?? '')) === strtolower((string) ($right_parts['scheme'] ?? ''))
			&& ((string) ($left_parts['host'] ?? '')) === ((string) ($right_parts['host'] ?? ''))
			&& ((int) ($left_parts['port'] ?? 0)) === ((int) ($right_parts['port'] ?? 0));
	}

	private static function rebaseUrlAuthority(string $candidate_url, string $base_url): string
	{
		$candidate_parts = parse_url($candidate_url);
		$base_parts = parse_url($base_url);

		if (!is_array($candidate_parts) || !is_array($base_parts)) {
			return $candidate_url;
		}

		$authority = self::buildUrlAuthority($base_parts);

		if ($authority === null || !preg_match('#^https?://#', $authority)) {
			return $candidate_url;
		}

		return $authority
			. (string) ($candidate_parts['path'] ?? '/')
			. self::buildUrlQueryAndFragment($candidate_parts);
	}

	private static function rebaseRegistryArtifactUrlForStorage(string $candidate_url, string $current_registry_url, string $declared_registry_url): string
	{
		$relative_path = self::extractRegistryRelativePath($candidate_url, $current_registry_url);

		if ($relative_path !== null && $relative_path !== '') {
			return self::resolveUrlAgainstBase($declared_registry_url, $relative_path);
		}

		$candidate_parts = parse_url($candidate_url);
		$placeholder_parts = parse_url(PackageManifest::getPlaceholderRegistryUrl());
		$declared_parts = parse_url($declared_registry_url);

		if (
			is_array($candidate_parts)
			&& is_array($placeholder_parts)
			&& is_array($declared_parts)
			&& self::hasSameUrlAuthority($candidate_parts, $placeholder_parts)
			&& !self::hasSameUrlAuthority($declared_parts, $placeholder_parts)
		) {
			return self::rebaseUrlAuthority($candidate_url, $declared_registry_url);
		}

		return $candidate_url;
	}

	private static function resolveUrlAgainstBase(string $base_url, string $candidate): string
	{
		if (filter_var($candidate, FILTER_VALIDATE_URL) !== false) {
			return $candidate;
		}

		$base = parse_url($base_url);
		$candidate_parts = parse_url($candidate);

		if (!is_array($base) || !isset($base['scheme'])) {
			return $candidate;
		}

		$candidate_path = str_replace('\\', '/', (string) ($candidate_parts['path'] ?? $candidate));
		$query_and_fragment = is_array($candidate_parts)
			? self::buildUrlQueryAndFragment($candidate_parts)
			: '';

		if ($base['scheme'] === 'file') {
			$base_path = $base['path'] ?? '';
			$base_dir = rtrim(str_replace('\\', '/', dirname($base_path)), '/');
			$path = str_starts_with($candidate_path, '/')
				? $candidate_path
				: ($base_dir . '/' . ltrim($candidate_path, '/'));

			return 'file://' . self::normalizeRelativeUrlPath($path) . $query_and_fragment;
		}

		$authority = self::buildUrlAuthority($base);

		if ($authority === null) {
			return $candidate;
		}

		if (str_starts_with($candidate_path, '/')) {
			return $authority . self::normalizeRelativeUrlPath($candidate_path) . $query_and_fragment;
		}

		$base_path = $base['path'] ?? '/';
		$base_dir = rtrim(str_replace('\\', '/', dirname($base_path)), '/');
		$joined_path = self::normalizeRelativeUrlPath($base_dir . '/' . $candidate_path);

		return $authority . $joined_path . $query_and_fragment;
	}

	private static function normalizeRelativeUrlPath(string $path): string
	{
		$path = str_replace('\\', '/', $path);
		$prefix = str_starts_with($path, '/') ? '/' : '';
		$segments = [];

		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			if ($segment === '..') {
				array_pop($segments);

				continue;
			}

			$segments[] = $segment;
		}

		return $prefix . implode('/', $segments);
	}

	private static function buildUrlAuthority(array $parts): ?string
	{
		$scheme = strtolower((string) ($parts['scheme'] ?? ''));

		if ($scheme === '') {
			return null;
		}

		if ($scheme === 'file') {
			return 'file://';
		}

		$host = (string) ($parts['host'] ?? '');

		if ($host === '') {
			return null;
		}

		$authority = $scheme . '://';
		$user = (string) ($parts['user'] ?? '');
		$pass = (string) ($parts['pass'] ?? '');

		if ($user !== '') {
			$authority .= $user;

			if ($pass !== '') {
				$authority .= ':' . $pass;
			}

			$authority .= '@';
		}

		if (str_contains($host, ':') && !str_starts_with($host, '[')) {
			$host = '[' . $host . ']';
		}

		$authority .= $host;

		if (isset($parts['port'])) {
			$authority .= ':' . $parts['port'];
		}

		return $authority;
	}

	private static function buildUrlQueryAndFragment(array $parts): string
	{
		$suffix = '';

		if (isset($parts['query']) && $parts['query'] !== '') {
			$suffix .= '?' . $parts['query'];
		}

		if (isset($parts['fragment']) && $parts['fragment'] !== '') {
			$suffix .= '#' . $parts['fragment'];
		}

		return $suffix;
	}

	private static function extractRegistryRelativePath(string $candidate_url, string $registry_url): ?string
	{
		if ($registry_url === '') {
			return null;
		}

		$candidate_parts = parse_url($candidate_url);
		$registry_parts = parse_url($registry_url);

		if (!is_array($candidate_parts) || !is_array($registry_parts)) {
			return null;
		}

		$candidate_scheme = strtolower((string) ($candidate_parts['scheme'] ?? ''));
		$registry_scheme = strtolower((string) ($registry_parts['scheme'] ?? ''));

		if ($candidate_scheme === '' || $candidate_scheme !== $registry_scheme) {
			return null;
		}

		if ($candidate_scheme !== 'file') {
			$candidate_authority = strtolower((string) ($candidate_parts['host'] ?? '')) . ':' . (string) ($candidate_parts['port'] ?? '');
			$registry_authority = strtolower((string) ($registry_parts['host'] ?? '')) . ':' . (string) ($registry_parts['port'] ?? '');

			if ($candidate_authority !== $registry_authority) {
				return null;
			}
		}

		$candidate_path = str_replace('\\', '/', (string) ($candidate_parts['path'] ?? ''));
		$registry_path = str_replace('\\', '/', (string) ($registry_parts['path'] ?? ''));

		if ($candidate_path === '' || $registry_path === '') {
			return null;
		}

		$registry_dir = rtrim(str_replace('\\', '/', dirname($registry_path)), '/');

		if ($registry_dir === '') {
			$registry_dir = '/';
		}

		if ($registry_dir === '/') {
			return ltrim($candidate_path, '/') . self::buildUrlQueryAndFragment($candidate_parts);
		}

		if (!str_starts_with($candidate_path, $registry_dir . '/')) {
			return null;
		}

		return ltrim(substr($candidate_path, strlen($registry_dir)), '/')
			. self::buildUrlQueryAndFragment($candidate_parts);
	}

	private static function rebuildThemeData(): void
	{
		PackagePathHelper::reset();
		PackageThemeScanHelper::reset();
		ob_start();

		try {
			CLICommandBuildThemeData::create();
		} finally {
			ob_end_clean();
		}
	}

	private static function refreshInstalledPackageDiscoveryState(): void
	{
		PackageConfig::reset();
		PackagePathHelper::reset();
		PackageThemeScanHelper::reset();
	}

	/**
	 * @param array{
	 *     manifest_version?: int,
	 *     registries: array<string, array<string, mixed>>,
	 *     packages: array<string, array<string, mixed>>
	 * } $manifest
	 * @param array{
	 *     lockfile_version?: int,
	 *     packages: array<string, array<string, mixed>>
	 * } $current_lock
	 * @return array{
	 *     packages: array<string, array<string, mixed>>,
	 *     package_summaries: array<string, array<string, mixed>>,
	 *     removed_package_keys: string[],
	 *     ordered_package_keys: list<string>
	 * }
	 */
	private static function buildNextLockState(array $manifest, array $current_lock, string $lock_base_dir, bool $update): array
	{
		$next_packages = [];
		$package_summaries = [];

		foreach ($manifest['packages'] as $package_key => $manifest_package) {
			$current_locked_package = $current_lock['packages'][$package_key] ?? null;
			$locked_package = self::buildLockedPackage(
				$manifest,
				$package_key,
				$manifest_package,
				$current_locked_package,
				$lock_base_dir,
				$update
			);
			$action = !isset($current_lock['packages'][$package_key])
				? 'added'
				: (
					PackageLockfile::exportPackage($current_lock['packages'][$package_key], $manifest_package['type'], $manifest_package['id'])
					=== PackageLockfile::exportPackage($locked_package, $manifest_package['type'], $manifest_package['id'])
						? 'unchanged'
						: 'updated'
				);

			$next_packages[$package_key] = $locked_package;
			$package_summaries[$package_key] = self::buildPackageSummary($package_key, $locked_package, $action);
		}

		self::resolveAutoInstalledRegistryDependencies(
			$manifest,
			$current_lock['packages'],
			$next_packages,
			$package_summaries,
			$lock_base_dir,
			$update
		);

		$removed_package_keys = array_values(array_diff(
			array_keys($current_lock['packages']),
			array_keys($next_packages)
		));
		sort($removed_package_keys);
		$ordered_package_keys = self::validateAndOrderPackages($next_packages);
		ksort($next_packages);
		ksort($package_summaries);

		return [
			'packages' => $next_packages,
			'package_summaries' => $package_summaries,
			'removed_package_keys' => $removed_package_keys,
			'ordered_package_keys' => $ordered_package_keys,
		];
	}

	/**
	 * @param array<string, mixed> $package
	 * @return array<string, mixed>
	 */
	private static function buildPackageSummary(string $package_key, array $package, string $action): array
	{
		return [
			'package_key' => $package_key,
			'type' => $package['type'],
			'id' => $package['id'],
			'package' => $package['package'],
			'source_type' => $package['resolved']['type'] ?? $package['source']['type'] ?? null,
			'resolved_version' => $package['resolved']['version'] ?? null,
			'action' => $action,
			'auto_installed' => (bool) ($package['auto_installed'] ?? false),
			'required_by' => $package['required_by'] ?? [],
		];
	}

	/**
	 * @param list<string> $ordered_package_keys
	 * @return list<string>
	 */
	private static function buildMigrationModules(array $ordered_package_keys): array
	{
		$modules = ['framework'];

		foreach ($ordered_package_keys as $package_key) {
			if ($package_key === 'core:framework') {
				continue;
			}

			$modules[] = PackageModuleHelper::buildModuleFromPackageKey($package_key);
		}

		$modules[] = 'app';

		return array_values(array_unique($modules));
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
	 *     registries: array<string, array<string, mixed>>,
	 *     packages: array<string, array<string, mixed>>
	 * } $manifest
	 * @param array<string, mixed>|null $current_locked_package
	 * @return array<string, mixed>
	 */
	private static function buildLockedPackage(
		array $manifest,
		string $package_key,
		array $manifest_package,
		?array $current_locked_package,
		string $lock_base_dir,
		bool $update
	): array {
		$source = is_array($manifest_package['source'] ?? null) ? $manifest_package['source'] : null;

		if (!is_array($source)) {
			throw new RuntimeException("Manifest package '{$package_key}' is missing a source definition.");
		}

		$source_type = $source['type'] ?? null;

		return match ($source_type) {
			'dev' => self::buildDevLockedPackage($manifest_package, $lock_base_dir),
			'registry' => self::buildRegistryLockedPackage($manifest['registries'], $manifest_package, $current_locked_package, $lock_base_dir, $update),
			default => throw new RuntimeException("Package install does not support source type '{$source_type}' for '{$package_key}'."),
		};
	}

	/**
	 * @param array<string, mixed> $manifest_package
	 * @return array<string, mixed>
	 */
	private static function buildDevLockedPackage(array $manifest_package, string $lock_base_dir): array
	{
		$type = PackageTypeHelper::normalizeType($manifest_package['type'] ?? null, 'Dev package');
		$id = PackageTypeHelper::normalizeId($manifest_package['id'] ?? null, 'Dev package');
		$source = $manifest_package['source'];
		$source_path = $source['path'] ?? PackageTypeHelper::getDefaultPath($type, 'dev', $id);
		$resolved_path = $source['resolved_path'] ?? self::resolveAbsolutePath($source_path);

		if (!is_dir($resolved_path)) {
			throw new RuntimeException("Dev package '{$type}:{$id}' path does not exist: {$source_path}");
		}

		$metadata = PackageMetadataHelper::loadFromSourcePath($resolved_path);
		self::assertPackageMetadataMatchesManifest($metadata, $manifest_package, "{$type}:{$id}");

		return [
			'type' => $type,
			'id' => $id,
			'package' => $metadata['package'],
			'source' => [
				'type' => 'dev',
				'path' => $source_path,
			],
			'resolved' => [
				'type' => 'dev',
				'path' => self::toPathForStorage($resolved_path, $lock_base_dir),
				'version' => $metadata['version'],
			],
			'dependencies' => $metadata['dependencies'],
			'composer' => [
				'require' => $metadata['composer']['require'],
			],
			'assets' => $metadata['assets'],
		];
	}

	/**
	 * @param array<string, array{name: string, url: string, resolved_url: string}> $registries
	 * @param array<string, mixed> $manifest_package
	 * @param array<string, mixed>|null $current_locked_package
	 * @return array<string, mixed>
	 */
	private static function buildRegistryLockedPackage(
		array $registries,
		array $manifest_package,
		?array $current_locked_package,
		string $lock_base_dir,
		bool $update
	): array {
		$type = PackageTypeHelper::normalizeType($manifest_package['type'] ?? null, 'Registry package');
		$id = PackageTypeHelper::normalizeId($manifest_package['id'] ?? null, 'Registry package');
		$source = $manifest_package['source'];
		$registry_name = trim((string) ($source['registry'] ?? ''));
		$package = trim((string) ($manifest_package['package'] ?? ''));

		if ($registry_name === '' || !isset($registries[$registry_name])) {
			throw new RuntimeException("Registry package '{$type}:{$id}' references unknown registry alias '{$registry_name}'.");
		}

		if ($package === '') {
			throw new RuntimeException("Registry package '{$type}:{$id}' is missing package.");
		}

		$requested_version = self::determineRequestedVersion($manifest_package, $current_locked_package, $update);
		$resolved_package = PackageRegistryClient::resolvePackage($registries[$registry_name], $package, $requested_version);

		if ($resolved_package['type'] !== $type || $resolved_package['id'] !== $id) {
			throw new RuntimeException(
				"Registry package '{$package}' resolved to '{$resolved_package['type']}:{$resolved_package['id']}', expected '{$type}:{$id}'."
			);
		}

		$target_relative_path = PackageTypeHelper::getDefaultPath($type, 'registry', $id);
		$target_absolute_path = self::resolveAbsolutePath($target_relative_path);

		return [
			'type' => $type,
			'id' => $id,
			'package' => $package,
			'source' => array_filter([
				'type' => 'registry',
				'registry' => $registry_name,
				'resolved_registry_url' => $resolved_package['registry_url'],
				'version' => is_string($source['version'] ?? null) && trim((string) $source['version']) !== '' ? trim((string) $source['version']) : null,
			], static fn (mixed $value): bool => $value !== null),
			'resolved' => [
				'type' => 'registry',
				'registry' => $registry_name,
				'registry_url' => $resolved_package['registry_url'],
				'version' => $resolved_package['version'],
				'path' => self::toPathForStorage($target_absolute_path, $lock_base_dir),
				'dist_url' => $resolved_package['dist']['url'],
				'dist_sha256' => $resolved_package['dist']['sha256'],
			],
			'dependencies' => $resolved_package['dependencies'],
			'composer' => [
				'require' => $resolved_package['composer_require'],
			],
			'assets' => $resolved_package['assets'],
		];
	}

	/**
	 * @param array<string, mixed> $manifest_package
	 * @param array<string, mixed>|null $current_locked_package
	 */
	private static function determineRequestedVersion(array $manifest_package, ?array $current_locked_package, bool $update): ?string
	{
		$source = is_array($manifest_package['source'] ?? null) ? $manifest_package['source'] : [];
		$manifest_constraint = is_string($source['version'] ?? null) && trim((string) $source['version']) !== ''
			? trim((string) $source['version'])
			: null;

		if ($update || $current_locked_package === null) {
			return $manifest_constraint;
		}

		if (trim((string) ($manifest_package['package'] ?? '')) !== trim((string) ($current_locked_package['package'] ?? ''))) {
			return $manifest_constraint;
		}

		$current_source = is_array($current_locked_package['source'] ?? null) ? $current_locked_package['source'] : [];

		if (($current_source['type'] ?? null) !== 'registry') {
			return $manifest_constraint;
		}

		if (trim((string) ($current_source['registry'] ?? '')) !== trim((string) ($source['registry'] ?? ''))) {
			return $manifest_constraint;
		}

		$current_version = trim((string) (($current_locked_package['resolved']['version'] ?? null) ?? ''));

		if ($current_version === '') {
			return $manifest_constraint;
		}

		if ($manifest_constraint !== null && !PluginVersionHelper::matches($current_version, $manifest_constraint)) {
			return $manifest_constraint;
		}

		return $current_version;
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @param array<string, mixed> $manifest_package
	 */
	private static function assertPackageMetadataMatchesManifest(array $metadata, array $manifest_package, string $package_key): void
	{
		if ($metadata['type'] !== $manifest_package['type']) {
			throw new RuntimeException("Dev package '{$package_key}' metadata type '{$metadata['type']}' does not match manifest type '{$manifest_package['type']}'.");
		}

		if ($metadata['id'] !== $manifest_package['id']) {
			throw new RuntimeException("Dev package '{$package_key}' metadata id '{$metadata['id']}' does not match manifest id '{$manifest_package['id']}'.");
		}

		$manifest_package_name = trim((string) ($manifest_package['package'] ?? ''));

		if ($manifest_package_name !== '' && $metadata['package'] !== $manifest_package_name) {
			throw new RuntimeException("Dev package '{$package_key}' metadata package '{$metadata['package']}' does not match manifest package '{$manifest_package_name}'.");
		}
	}

	/**
	 * @param array{
	 *     registries: array<string, array{name: string, url: string, resolved_url: string}>,
	 *     packages: array<string, array<string, mixed>>
	 * } $manifest
	 * @param array<string, array<string, mixed>> $current_lock_packages
	 * @param array<string, array<string, mixed>> $next_packages
	 * @param array<string, array<string, mixed>> $package_summaries
	 */
	private static function resolveAutoInstalledRegistryDependencies(
		array $manifest,
		array $current_lock_packages,
		array &$next_packages,
		array &$package_summaries,
		string $lock_base_dir,
		bool $update
	): void {
		$manifest_package_map = PackageDependencyHelper::buildPackageNameMap($manifest['packages']);
		$processed_requests = [];

		while (true) {
			$requests = self::collectAutoInstallDependencyRequests($manifest['registries'], $next_packages, $manifest_package_map);
			$changed = false;

			foreach ($requests as $package_name => $request) {
				$signature = json_encode($request, JSON_THROW_ON_ERROR);

				if (($processed_requests[$package_name] ?? null) === $signature) {
					continue;
				}

				$processed_requests[$package_name] = $signature;
				$constraint = PluginVersionHelper::combineConstraints($request['constraints']);
				$current_locked_package = self::findCurrentLockedDependency($current_lock_packages, $package_name);
				$request_version = self::determineAutoInstalledRequestedVersion($current_locked_package, $constraint, $update);
				$resolved_package = self::resolveDependencyFromCandidates($request['registries'], $package_name, $request_version);
				$package_key = PackageTypeHelper::getKey($resolved_package['type'], $resolved_package['id']);
				$locked_package = self::buildRegistryLockedPackage(
					$request['registries'],
					[
						'type' => $resolved_package['type'],
						'id' => $resolved_package['id'],
						'package' => $package_name,
						'source' => [
							'type' => 'registry',
							'registry' => $resolved_package['registry_name'],
							'version' => $constraint === '' ? null : $constraint,
						],
					],
					$current_lock_packages[$package_key] ?? null,
					$lock_base_dir,
					$update
				);
				$locked_package['auto_installed'] = true;
				$locked_package['required_by'] = $request['required_by'];
				$action = !isset($current_lock_packages[$package_key])
					? 'auto_added'
					: (
						PackageLockfile::exportPackage($current_lock_packages[$package_key], $locked_package['type'], $locked_package['id'])
						=== PackageLockfile::exportPackage($locked_package, $locked_package['type'], $locked_package['id'])
							? 'unchanged'
							: 'auto_updated'
					);

				$previous_export = isset($next_packages[$package_key])
					? PackageLockfile::exportPackage($next_packages[$package_key], $locked_package['type'], $locked_package['id'])
					: null;
				$next_packages[$package_key] = $locked_package;
				$package_summaries[$package_key] = self::buildPackageSummary($package_key, $locked_package, $action);

				if ($previous_export !== PackageLockfile::exportPackage($locked_package, $locked_package['type'], $locked_package['id'])) {
					$changed = true;
				}
			}

			if (!$changed) {
				return;
			}
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $registries
	 * @param array<string, array<string, mixed>> $packages
	 * @param array<string, string> $manifest_package_map
	 * @return array<string, array{
	 *     registries: array<string, array{name: string, url: string, resolved_url: string}>,
	 *     constraints: list<string>,
	 *     required_by: list<string>
	 * }>
	 */
	private static function collectAutoInstallDependencyRequests(array $registries, array $packages, array $manifest_package_map): array
	{
		$requests = [];

		foreach ($packages as $package_key => $package) {
			$dependencies = PackageDependencyHelper::normalizeDependencies(
				$package['dependencies'] ?? [],
				"Package '{$package_key}'"
			);

			foreach ($dependencies as $dependency_package => $constraint) {
				if (isset($manifest_package_map[$dependency_package])) {
					continue;
				}

				if (!isset($requests[$dependency_package])) {
					$requests[$dependency_package] = [
						'registries' => [],
						'constraints' => [],
						'required_by' => [],
					];
				}

				foreach (self::getCandidateRegistriesForDependency($registries, $package) as $registry_name => $registry) {
					$requests[$dependency_package]['registries'][$registry_name] = $registry;
				}

				if (!in_array($constraint, $requests[$dependency_package]['constraints'], true)) {
					$requests[$dependency_package]['constraints'][] = $constraint;
				}

				if (!in_array($package_key, $requests[$dependency_package]['required_by'], true)) {
					$requests[$dependency_package]['required_by'][] = $package_key;
				}
			}
		}

		foreach ($requests as &$request) {
			sort($request['constraints']);
			sort($request['required_by']);
			ksort($request['registries']);
		}
		unset($request);
		ksort($requests);

		return $requests;
	}

	/**
	 * @param array<string, array<string, mixed>> $registries
	 * @param array<string, mixed> $package
	 * @return array<string, array{name: string, url: string, resolved_url: string}>
	 */
	private static function getCandidateRegistriesForDependency(array $registries, array $package): array
	{
		$resolved = is_array($package['resolved'] ?? null) ? $package['resolved'] : [];
		$source = is_array($package['source'] ?? null) ? $package['source'] : [];
		$registry_name = trim((string) ($source['registry'] ?? $resolved['registry'] ?? ''));

		if ($registry_name !== '' && isset($registries[$registry_name])) {
			return [
				$registry_name => $registries[$registry_name],
			];
		}

		if ($registries === []) {
			throw new RuntimeException("Package '{$package['type']}:{$package['id']}' depends on registry packages, but no registries are configured.");
		}

		return $registries;
	}

	/**
	 * @param array<string, array<string, mixed>> $current_lock_packages
	 * @return array<string, mixed>|null
	 */
	private static function findCurrentLockedDependency(array $current_lock_packages, string $package_name): ?array
	{
		foreach ($current_lock_packages as $package) {
			if (trim((string) ($package['package'] ?? '')) === $package_name) {
				return $package;
			}
		}

		return null;
	}

	private static function determineAutoInstalledRequestedVersion(?array $current_locked_package, string $constraint, bool $update): ?string
	{
		if ($update || $current_locked_package === null) {
			return $constraint !== '' ? $constraint : null;
		}

		$current_version = trim((string) (($current_locked_package['resolved']['version'] ?? null) ?? ''));

		if ($current_version === '') {
			return $constraint !== '' ? $constraint : null;
		}

		if ($constraint !== '' && !PluginVersionHelper::matches($current_version, $constraint)) {
			return $constraint;
		}

		return $current_version;
	}

	/**
	 * @param array<string, array{name: string, url: string, resolved_url: string}> $candidate_registries
	 * @return array{
	 *     registry_name: string,
	 *     registry_url: string,
	 *     package: string,
	 *     type: string,
	 *     id: string,
	 *     version: string,
	 *     dependencies: array<string, string>,
	 *     composer_require: array<string, string>,
	 *     assets: array{
	 *         public: list<array{source: string, target: string}>
	 *     },
	 *     dist: array{
	 *         type: string,
	 *         url: string,
	 *         sha256: string
	 *     }
	 * }
	 */
	private static function resolveDependencyFromCandidates(array $candidate_registries, string $package_name, ?string $requested_version): array
	{
		$best = null;
		$errors = [];

		foreach ($candidate_registries as $registry_name => $registry) {
			try {
				$resolved = PackageRegistryClient::resolvePackage($registry, $package_name, $requested_version);
			} catch (RuntimeException $e) {
				$errors[] = "{$registry_name}: {$e->getMessage()}";

				continue;
			}

			if ($best === null || PluginVersionHelper::compare($resolved['version'], $best['version']) > 0) {
				$best = $resolved;
			}
		}

		if ($best !== null) {
			return $best;
		}

		throw new RuntimeException(
			"Unable to resolve dependency '{$package_name}' from configured registries."
			. ($errors !== [] ? ' ' . implode(' | ', $errors) : '')
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $packages
	 * @return list<string>
	 */
	private static function validateAndOrderPackages(array $packages): array
	{
		$missing = PackageDependencyHelper::findMissingDependencies($packages);

		if ($missing !== []) {
			$messages = [];

			foreach ($missing as $package_key => $dependencies) {
				$messages[] = "{$package_key} -> " . implode(', ', array_keys($dependencies));
			}

			throw new RuntimeException('Package dependency resolution failed. Missing dependencies: ' . implode('; ', $messages));
		}

		$mismatches = PackageDependencyHelper::findDependencyVersionMismatches($packages);

		if ($mismatches !== []) {
			$messages = [];

			foreach ($mismatches as $package_key => $dependencies) {
				$parts = [];

				foreach ($dependencies as $dependency_package => $mismatch) {
					$resolved_version = $mismatch['resolved_version'] ?? 'unknown';
					$parts[] = "{$dependency_package} ({$resolved_version} does not satisfy {$mismatch['constraint']})";
				}

				$messages[] = "{$package_key} -> " . implode(', ', $parts);
			}

			throw new RuntimeException('Package dependency resolution failed. Version conflicts: ' . implode('; ', $messages));
		}

		return PackageDependencyHelper::sortPackageKeysByDependencies($packages);
	}

	/**
	 * @param array<string, array<string, mixed>> $current_lock_packages
	 * @param array<string, array<string, mixed>> $next_lock_packages
	 */
	private static function removeStaleRegistryDirectories(array $current_lock_packages, array $next_lock_packages): void
	{
		foreach ($current_lock_packages as $package_key => $current_locked_package) {
			if (($current_locked_package['type'] ?? null) === 'plugin') {
				continue;
			}

			$current_source = is_array($current_locked_package['source'] ?? null) ? $current_locked_package['source'] : [];
			$current_resolved = is_array($current_locked_package['resolved'] ?? null) ? $current_locked_package['resolved'] : [];
			$current_type = $current_resolved['type'] ?? $current_source['type'] ?? null;

			if ($current_type !== 'registry') {
				continue;
			}

			$next_locked_package = $next_lock_packages[$package_key] ?? null;
			$next_source = is_array($next_locked_package['source'] ?? null) ? $next_locked_package['source'] : [];
			$next_resolved = is_array($next_locked_package['resolved'] ?? null) ? $next_locked_package['resolved'] : [];
			$next_type = $next_resolved['type'] ?? $next_source['type'] ?? null;

			$current_path = self::resolveStoredPath(
				is_string($current_resolved['path'] ?? null)
					? $current_resolved['path']
					: PackageTypeHelper::getDefaultPath($current_locked_package['type'], 'registry', $current_locked_package['id']),
				DEPLOY_ROOT
			);

			self::assertRegistryTargetSafe($current_path, "stale directory removal for '{$package_key}'");

			if ($next_type === 'registry') {
				$next_path = self::resolveStoredPath(
					is_string($next_resolved['path'] ?? null)
						? $next_resolved['path']
						: PackageTypeHelper::getDefaultPath($current_locked_package['type'], 'registry', $current_locked_package['id']),
					DEPLOY_ROOT
				);

				self::assertRegistryTargetSafe($next_path, "next registry path for '{$package_key}'");

				if ($current_path !== $next_path && is_dir($current_path)) {
					self::removeDirectory($current_path);
				}

				continue;
			}

			if (is_dir($current_path)) {
				self::removeDirectory($current_path);
			}
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $current_lock_packages
	 * @param array<string, array<string, mixed>> $next_lock_packages
	 */
	private static function installRegistryPackages(array $current_lock_packages, array $next_lock_packages): void
	{
		foreach ($next_lock_packages as $package_key => $next_locked_package) {
			if (($next_locked_package['type'] ?? null) === 'plugin') {
				continue;
			}

			$resolved = is_array($next_locked_package['resolved'] ?? null) ? $next_locked_package['resolved'] : [];

			if (($resolved['type'] ?? null) !== 'registry') {
				continue;
			}

			$target_absolute_path = self::resolveStoredPath((string) $resolved['path'], DEPLOY_ROOT);
			self::assertRegistryTargetSafe($target_absolute_path, "install target for '{$package_key}'");

			$current_locked_package = $current_lock_packages[$package_key] ?? null;
			$install_required = !is_dir($target_absolute_path);

			if ($current_locked_package !== null) {
				$install_required = $install_required
					|| PackageLockfile::exportPackage($current_locked_package, $next_locked_package['type'], $next_locked_package['id'])
						!== PackageLockfile::exportPackage($next_locked_package, $next_locked_package['type'], $next_locked_package['id']);
			}

			if (!$install_required) {
				continue;
			}

			self::installRegistryPackage($next_locked_package, $target_absolute_path);
		}
	}

	/**
	 * @param array<string, mixed> $locked_package
	 */
	private static function installRegistryPackage(array $locked_package, string $target_absolute_path): void
	{
		$package_key = ($locked_package['type'] ?? '') . ':' . ($locked_package['id'] ?? '');
		self::assertRegistryTargetSafe($target_absolute_path, "install for '{$package_key}'");

		$package = trim((string) ($locked_package['package'] ?? ''));
		$dist_type = trim((string) ($locked_package['resolved']['dist_type'] ?? 'zip'));
		$dist_url = trim((string) ($locked_package['resolved']['dist_url'] ?? ''));
		$dist_sha256 = trim((string) ($locked_package['resolved']['dist_sha256'] ?? ''));

		if ($dist_type !== 'zip') {
			throw new RuntimeException("Registry package '{$package}' uses unsupported dist type '{$dist_type}'.");
		}

		if ($dist_url === '' || $dist_sha256 === '') {
			throw new RuntimeException("Registry package '{$package_key}' is missing distribution metadata.");
		}

		if (!class_exists(ZipArchive::class)) {
			throw new RuntimeException('ZipArchive extension is required for registry package installs.');
		}

		$staging_root = rtrim(DEPLOY_ROOT, '/') . '/tmp/package-sync/' . $locked_package['type'] . '-' . $locked_package['id'] . '-' . bin2hex(random_bytes(8));
		$archive_path = $staging_root . '/package.zip';
		$extract_path = $staging_root . '/extract';
		$backup_path = null;

		self::ensureDirectory(dirname($archive_path));
		self::ensureDirectory($extract_path);

		try {
			$actual_hash = self::downloadRegistryPackageArchive($dist_url, $archive_path, $package);

			if (!hash_equals($dist_sha256, $actual_hash)) {
				throw new RuntimeException("Registry package '{$package}' failed SHA-256 verification.");
			}

			$zip = new ZipArchive();
			$zip_result = $zip->open($archive_path);

			if ($zip_result !== true) {
				throw new RuntimeException("Unable to open registry package archive for '{$package}'.");
			}

			if (!$zip->extractTo($extract_path)) {
				$zip->close();

				throw new RuntimeException("Unable to extract registry package '{$package}'.");
			}

			$zip->close();
			$package_root = self::resolveExtractedPackageRoot($extract_path);

			if (file_exists($target_absolute_path)) {
				$backup_path = dirname($target_absolute_path) . '/.' . basename($target_absolute_path) . '.bak-' . bin2hex(random_bytes(4));
				self::runFilesystemOperation(
					static fn (): bool => rename($target_absolute_path, $backup_path),
					"Unable to move existing package path {$target_absolute_path} to backup {$backup_path}"
				);
			}

			self::copyDirectory($package_root, $target_absolute_path);
		} catch (Throwable $e) {
			if (is_dir($target_absolute_path)) {
				self::removeDirectory($target_absolute_path);
			}

			if ($backup_path !== null && is_dir($backup_path)) {
				self::runFilesystemOperation(
					static fn (): bool => rename($backup_path, $target_absolute_path),
					"Unable to restore backup package path {$backup_path} to {$target_absolute_path}"
				);
			}

			self::removeDirectory($staging_root);

			throw $e;
		}

		if ($backup_path !== null && is_dir($backup_path)) {
			self::removeDirectory($backup_path);
		}

		self::removeDirectory($staging_root);
	}

	private static function downloadRegistryPackageArchive(string $dist_url, string $archive_path, string $package): string
	{
		$context = stream_context_create([
			'http' => [
				'timeout' => self::REGISTRY_DOWNLOAD_TIMEOUT_SECONDS,
				'follow_location' => 1,
			],
			'https' => [
				'timeout' => self::REGISTRY_DOWNLOAD_TIMEOUT_SECONDS,
				'follow_location' => 1,
			],
		]);
		$download_error = null;
		set_error_handler(static function (int $_severity, string $message) use (&$download_error): bool {
			$download_error = $message;

			return true;
		});

		try {
			$read_handle = fopen($dist_url, 'rb', false, $context);
		} finally {
			restore_error_handler();
		}

		if (!is_resource($read_handle)) {
			throw new RuntimeException(
				"Unable to download registry package '{$package}' from {$dist_url}"
				. ($download_error !== null ? ': ' . $download_error : '')
			);
		}

		stream_set_timeout($read_handle, self::REGISTRY_DOWNLOAD_TIMEOUT_SECONDS);
		$write_handle = fopen($archive_path, 'wb');

		if (!is_resource($write_handle)) {
			fclose($read_handle);

			throw new RuntimeException("Unable to store registry package archive for '{$package}'.");
		}

		$hash_context = hash_init('sha256');

		try {
			while (!feof($read_handle)) {
				$chunk = fread($read_handle, 1024 * 1024);

				if ($chunk === false) {
					throw new RuntimeException("Unable to read registry package '{$package}' from {$dist_url}");
				}

				if ($chunk === '') {
					$meta = stream_get_meta_data($read_handle);

					if ($meta['timed_out'] === true) {
						throw new RuntimeException(
							"Timed out downloading registry package '{$package}' from {$dist_url} after "
							. self::REGISTRY_DOWNLOAD_TIMEOUT_SECONDS . ' seconds.'
						);
					}

					continue;
				}

				hash_update($hash_context, $chunk);

				if (fwrite($write_handle, $chunk) === false) {
					throw new RuntimeException("Unable to store registry package archive for '{$package}'.");
				}
			}
		} finally {
			fclose($read_handle);
			fclose($write_handle);
		}

		return strtolower(hash_final($hash_context));
	}

	private static function resolveExtractedPackageRoot(string $extract_path): string
	{
		$metadata_matches = glob(rtrim($extract_path, '/') . '/.registry-package.json') ?: [];

		if (count($metadata_matches) === 1) {
			return rtrim($extract_path, '/');
		}

		$children = array_values(array_filter(
			scandir($extract_path) ?: [],
			static fn (string $entry): bool => $entry !== '.' && $entry !== '..'
		));

		if (count($children) === 1) {
			$candidate = rtrim($extract_path, '/') . '/' . $children[0];

			if (is_dir($candidate) && is_file($candidate . '/.registry-package.json')) {
				return $candidate;
			}
		}

		throw new RuntimeException("Registry package archive does not expose exactly one .registry-package.json at its root: {$extract_path}");
	}

	private static function writeTempPluginManifest(array $packages, string $base_dir): string
	{
		$temp_path = self::createScopedTempFile($base_dir, '.radaptor-plugin-manifest-');

		PackageBridgeHelper::writePluginManifest($temp_path, $packages);

		return $temp_path;
	}

	private static function writeTempLockfile(array $lockfile, string $base_dir): string
	{
		$temp_path = self::createScopedTempFile($base_dir, '.radaptor-lock-');

		PackageLockfile::write($lockfile, $temp_path);

		return $temp_path;
	}

	private static function createScopedTempFile(string $base_dir, string $prefix): string
	{
		self::ensureDirectory($base_dir);
		$temp_path = tempnam($base_dir, $prefix);

		if ($temp_path === false) {
			throw new RuntimeException("Unable to create temporary file for prefix '{$prefix}' in '{$base_dir}'.");
		}

		return $temp_path;
	}

	private static function removeTempFile(string $path): void
	{
		if (is_file($path)) {
			unlink($path);
		}
	}

	private static function resolveAbsolutePath(string $path): string
	{
		if (str_starts_with($path, '/')) {
			return self::normalizePath($path);
		}

		return self::normalizePath(DEPLOY_ROOT . ltrim($path, '/'));
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

	private static function resolveStoredPath(string $path, string $base_dir): string
	{
		$normalized = str_replace('\\', '/', $path);

		if (str_starts_with($normalized, '/')) {
			return self::normalizePath($normalized);
		}

		return self::normalizePath(rtrim($base_dir, '/') . '/' . ltrim($normalized, '/'));
	}

	private static function ensureDirectory(string $directory): void
	{
		if (is_dir($directory)) {
			return;
		}

		$warning = null;
		set_error_handler(static function (int $_severity, string $message) use (&$warning): bool {
			$warning = $message;

			return true;
		});

		try {
			$created = mkdir($directory, 0o755, true);
		} finally {
			restore_error_handler();
		}

		clearstatcache(true, $directory);

		if ($created || is_dir($directory)) {
			return;
		}

		throw new RuntimeException("Unable to create directory: {$directory}" . ($warning !== null ? ': ' . $warning : ''));
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

			self::ensureDirectory(dirname($destination_path));
			self::runFilesystemOperation(
				static fn (): bool => copy($source_path, $destination_path),
				"Unable to copy file from {$source_path} to {$destination_path}"
			);
		}
	}

	private static function removeDirectory(string $directory): void
	{
		if (!file_exists($directory)) {
			return;
		}

		if (is_file($directory) || is_link($directory)) {
			self::runFilesystemOperation(
				static fn (): bool => unlink($directory),
				"Unable to remove path: {$directory}"
			);

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

			self::runFilesystemOperation(
				static fn (): bool => unlink($path),
				"Unable to remove path: {$path}"
			);
		}

		self::runFilesystemOperation(
			static fn (): bool => rmdir($directory),
			"Unable to remove directory: {$directory}"
		);
	}

	private static function runFilesystemOperation(callable $operation, string $error_message): mixed
	{
		$warning = null;
		set_error_handler(static function (int $_severity, string $message) use (&$warning): bool {
			$warning = $message;

			return true;
		});

		try {
			$result = $operation();
		} finally {
			restore_error_handler();
		}

		if ($result === false) {
			throw new RuntimeException($error_message . ($warning !== null ? ': ' . $warning : ''));
		}

		return $result;
	}

	private static function normalizePath(string $path): string
	{
		$path = str_replace('\\', '/', $path);

		// Do NOT use realpath() — it follows symlinks, corrupting registry paths
		// when packages/registry/ is symlinked to packages/dev/.
		if ($path !== '' && $path[0] === '/') {
			$prefix = '/';
			$path = substr($path, 1);
		} else {
			$prefix = '';
		}

		$parts = [];

		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			if ($segment === '..' && $parts !== [] && end($parts) !== '..') {
				array_pop($parts);
			} else {
				$parts[] = $segment;
			}
		}

		return rtrim($prefix . implode('/', $parts), '/');
	}

	private static function assertRegistryTargetSafe(string $absolute_path, string $operation): void
	{
		$base = rtrim(self::normalizePath(DEPLOY_ROOT), '/') . '/';
		$normalized = self::normalizePath($absolute_path);

		if (!str_starts_with($normalized, $base)) {
			throw new RuntimeException(
				"Registry {$operation} refused: target '{$normalized}' is outside DEPLOY_ROOT."
			);
		}

		$relative = substr($normalized, strlen($base));

		if (!str_starts_with($relative, 'packages/registry/')) {
			throw new RuntimeException(
				"Registry {$operation} refused: target '{$relative}' is not under packages/registry/. "
				. 'Registry operations may only target packages/registry/ directories.'
			);
		}

		$check = $normalized;
		$stop = rtrim($base, '/');

		while ($check !== $stop && $check !== '' && $check !== '/') {
			if (is_link($check)) {
				throw new RuntimeException(
					"Registry {$operation} refused: path component '{$check}' is a symlink. "
					. 'packages/registry/ entries must be real directories, not symlinks.'
				);
			}

			$check = dirname($check);
		}
	}
}
