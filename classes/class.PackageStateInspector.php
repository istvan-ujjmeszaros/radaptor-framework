<?php

class PackageStateInspector
{
	/**
	 * @return array{
	 *     mode: string,
	 *     app_root: string,
	 *     manifest_path: string,
	 *     lock_path: string,
	 *     local_manifest_path: string,
	 *     local_lock_path: string,
	 *     workspace_registry: array{
	 *         available: bool,
	 *         root: string|null,
	 *         path: string|null,
	 *         error: string|null
	 *     },
	 *     issues: list<string>,
	 *     packages: list<array<string, mixed>>,
	 *     summary: array<string, int>
	 * }
	 */
	public static function getStatus(bool $ignore_local_overrides = false): array
	{
		return self::getStatusForAppRoot(DEPLOY_ROOT, $ignore_local_overrides);
	}

	/**
	 * @return array{
	 *     mode: string,
	 *     app_root: string,
	 *     manifest_path: string,
	 *     lock_path: string,
	 *     local_manifest_path: string,
	 *     local_lock_path: string,
	 *     workspace_registry: array{
	 *         available: bool,
	 *         root: string|null,
	 *         path: string|null,
	 *         error: string|null
	 *     },
	 *     issues: list<string>,
	 *     packages: list<array<string, mixed>>,
	 *     summary: array<string, int>
	 * }
	 */
	public static function getStatusForAppRoot(
		string $app_root,
		bool $ignore_local_overrides = false,
		?string $dev_root = null
	): array {
		PackageLocalOverrideHelper::reset();
		$app_root = self::normalizePath($app_root);
		$committed_manifest_path = $app_root . '/radaptor.json';
		$committed_lock_path = $app_root . '/radaptor.lock.json';
		$local_manifest_path = $app_root . '/radaptor.local.json';
		$local_lock_path = $app_root . '/radaptor.local.lock.json';
		$committed_manifest = PackageManifest::loadFromPath($committed_manifest_path);
		$committed_lock = is_file($committed_lock_path)
			? PackageLockfile::loadFromPath($committed_lock_path)
			: null;
		$issues = [];
		$mode = 'registry-first';
		$local_override_document = null;
		$effective_manifest = $committed_manifest;
		$active_lock = $committed_lock;

		if (!$ignore_local_overrides && is_file($local_manifest_path)) {
			if (!PackageLocalOverrideHelper::isWorkspaceDevModeEnabled()) {
				$mode = 'inconsistent';
				$issues[] = 'Local overrides exist, but RADAPTOR_WORKSPACE_DEV_MODE=1 is not enabled.';
				$local_override_document = self::decodeJsonFile($local_manifest_path);
			} else {
				try {
					$local_override_document = self::loadLocalOverrideDocumentForAppRoot($app_root, $dev_root);
					$effective_manifest = self::loadEffectiveManifestForAppRoot($app_root, false, $dev_root);
					$active_lock = is_file($local_lock_path)
						? PackageLockfile::loadFromPath($local_lock_path)
						: $committed_lock;
					$mode = 'workspace-dev';
				} catch (Throwable $e) {
					$mode = 'inconsistent';
					$issues[] = $e->getMessage();
					$local_override_document = self::decodeJsonFile($local_manifest_path);
				}
			}
		}

		$catalog = WorkspacePackageRegistryCatalog::inspect();
		$packages = self::buildPackageEntries(
			$app_root,
			$committed_manifest,
			$effective_manifest,
			$committed_lock,
			$active_lock,
			$local_override_document,
			$mode,
			$catalog,
			$dev_root
		);

		return [
			'mode' => $ignore_local_overrides ? 'registry-first' : $mode,
			'app_root' => $app_root,
			'manifest_path' => $committed_manifest['path'],
			'lock_path' => $active_lock['path'] ?? $committed_lock_path,
			'local_manifest_path' => $local_manifest_path,
			'local_lock_path' => $local_lock_path,
			'workspace_registry' => [
				'available' => $catalog['available'],
				'root' => $catalog['root'],
				'path' => $catalog['path'],
				'error' => $catalog['error'],
			],
			'issues' => $issues,
			'packages' => array_values($packages),
			'summary' => self::summarizePackages($packages),
		];
	}

	/**
	 * @return array{
	 *     mode: string,
	 *     app_root: string,
	 *     issues: list<string>,
	 *     packages: list<array<string, mixed>>
	 * }
	 */
	public static function getCommittedStatusForAppRoot(string $app_root): array
	{
		return self::getStatusForAppRoot($app_root, true, null);
	}

	/**
	 * @return list<string>
	 */
	public static function findStaleWorkspaceConsumersForPackage(string $package_key): array
	{
		$stale = [];

		foreach (WorkspaceConsumerDiscovery::discoverCommittedConsumerRoots() as $consumer_root) {
			try {
				$status = self::getCommittedStatusForAppRoot($consumer_root);
			} catch (Throwable) {
				continue;
			}

			foreach ($status['packages'] as $package) {
				if (($package['package_key'] ?? null) !== $package_key) {
					continue;
				}

				if (($package['freshness'] ?? null) === 'behind') {
					$stale[] = basename($consumer_root);
				}
			}
		}

		sort($stale);

		return array_values(array_unique($stale));
	}

	/**
	 * @param array{
	 *     packages: array<string, array<string, mixed>>
	 * } $committed_manifest
	 * @param array{
	 *     packages: array<string, array<string, mixed>>
	 * } $effective_manifest
	 * @param array{
	 *     packages: array<string, array<string, mixed>>
	 * }|null $committed_lock
	 * @param array{
	 *     packages: array<string, array<string, mixed>>
	 * }|null $active_lock
	 * @param array<string, mixed>|null $local_override_document
	 * @param array{
	 *     available: bool,
	 *     packages: array<string, array<string, mixed>>
	 * } $catalog
	 * @return array<string, array<string, mixed>>
	 */
	private static function buildPackageEntries(
		string $app_root,
		array $committed_manifest,
		array $effective_manifest,
		?array $committed_lock,
		?array $active_lock,
		?array $local_override_document,
		string $mode,
		array $catalog,
		?string $dev_root = null
	): array {
		$packages = [];

		foreach ($committed_manifest['packages'] as $package_key => $committed_package) {
			$type = (string) $committed_package['type'];
			$id = (string) $committed_package['id'];
			$package_name = trim((string) ($committed_package['package'] ?? ''));
			$registry_path = self::normalizePath(
				$app_root . '/' . PackageTypeHelper::getDefaultPath($type, 'registry', $id)
			);
			$registry_copy_present = is_dir($registry_path);
			$effective_package = $effective_manifest['packages'][$package_key] ?? $committed_package;
			$active_locked_package = $active_lock['packages'][$package_key] ?? null;
			$override_source = self::getOverrideSourceDocument($local_override_document, $type, $id);
			$is_dev_override = $mode === 'workspace-dev'
				? (($effective_package['source']['type'] ?? null) === 'dev')
				: ($override_source !== null);

			if ($is_dev_override) {
				$active_path = self::resolveDevActivePath(
					$effective_package,
					$active_locked_package,
					$override_source,
					$app_root,
					$dev_root
				);
				$metadata = self::loadMetadataIfPresent($active_path);
				$repo = $active_path !== null ? GitRepositoryInspector::inspect($active_path) : null;
				$version = trim((string) ($metadata['version'] ?? ($active_locked_package['resolved']['version'] ?? '')));
				$freshness = self::determineFreshness($catalog, $package_name, $version);

				$packages[$package_key] = [
					'package_key' => $package_key,
					'type' => $type,
					'id' => $id,
					'package' => $package_name,
					'source_type' => 'dev',
					'active_path' => $active_path,
					'registry_path' => $registry_path,
					'registry_copy_present' => $registry_copy_present,
					'version' => $version !== '' ? $version : null,
					'source_commit' => ($repo['is_repository'] ?? false) === true ? $repo['commit'] : null,
					'source_dirty' => ($repo['is_repository'] ?? false) === true ? $repo['dirty'] : null,
					'freshness' => $freshness,
				];

				continue;
			}

			$locked_package = $committed_lock['packages'][$package_key] ?? $active_locked_package;
			$active_path = self::resolveRegistryActivePath($locked_package, $app_root, $type, $id);
			$version = trim((string) ($locked_package['resolved']['version'] ?? ''));

			$packages[$package_key] = [
				'package_key' => $package_key,
				'type' => $type,
				'id' => $id,
				'package' => $package_name,
				'source_type' => 'registry',
				'active_path' => $active_path,
				'registry_path' => $registry_path,
				'registry_copy_present' => $registry_copy_present,
				'version' => $version !== '' ? $version : null,
				'source_commit' => null,
				'source_dirty' => null,
				'freshness' => self::determineFreshness($catalog, $package_name, $version),
			];
		}

		ksort($packages);

		return $packages;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function getOverrideSourceDocument(?array $local_override_document, string $type, string $id): ?array
	{
		if (!is_array($local_override_document)) {
			return null;
		}

		$section = PackageTypeHelper::getSectionForType($type);
		$package = $local_override_document[$section][$id] ?? null;

		if (!is_array($package)) {
			return null;
		}

		$source = $package['source'] ?? null;

		return is_array($source) ? $source : null;
	}

	/**
	 * @param array<string, mixed> $effective_package
	 * @param array<string, mixed>|null $active_locked_package
	 * @param array<string, mixed>|null $override_source
	 */
	private static function resolveDevActivePath(
		array $effective_package,
		?array $active_locked_package,
		?array $override_source,
		string $app_root,
		?string $dev_root = null
	): ?string {
		$candidates = [
			$effective_package['source']['resolved_path'] ?? null,
		];

		$locked_source_type = $active_locked_package['resolved']['type'] ?? $active_locked_package['source']['type'] ?? null;

		if ($locked_source_type === 'dev') {
			$candidates[] = $active_locked_package['resolved']['resolved_path'] ?? null;
			$candidates[] = $active_locked_package['resolved']['path'] ?? null;
			$candidates[] = $active_locked_package['source']['resolved_path'] ?? null;
			$candidates[] = $active_locked_package['source']['path'] ?? null;
		}

		foreach ($candidates as $candidate) {
			if (!is_string($candidate) || trim($candidate) === '') {
				continue;
			}

			$resolved = self::resolveStoredPath($candidate, $app_root);

			if (is_dir($resolved)) {
				return $resolved;
			}
		}

		if (!is_array($override_source)) {
			return null;
		}

		$location = trim((string) ($override_source['location'] ?? ''));

		if ($location === '') {
			return null;
		}

		$resolved_dev_root = trim((string) ($dev_root ?? getenv('RADAPTOR_DEV_ROOT')));

		if ($resolved_dev_root === '') {
			return null;
		}

		return self::normalizePath(rtrim($resolved_dev_root, '/') . '/' . ltrim(str_replace('\\', '/', $location), '/'));
	}

	/**
	 * @param array<string, mixed>|null $locked_package
	 */
	private static function resolveRegistryActivePath(?array $locked_package, string $app_root, string $type, string $id): string
	{
		$path = trim((string) ($locked_package['resolved']['path'] ?? ''));

		if ($path === '') {
			$path = PackageTypeHelper::getDefaultPath($type, 'registry', $id);
		}

		return self::resolveStoredPath($path, $app_root);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function loadMetadataIfPresent(?string $active_path): ?array
	{
		if (!is_string($active_path) || $active_path === '') {
			return null;
		}

		$metadata_path = rtrim($active_path, '/') . '/.registry-package.json';

		if (!is_file($metadata_path)) {
			return null;
		}

		try {
			return PackageMetadataHelper::loadFromSourcePath($active_path);
		} catch (Throwable) {
			return null;
		}
	}

	private static function determineFreshness(array $catalog, string $package_name, string $version): string
	{
		if (($catalog['available'] ?? false) !== true || $version === '') {
			return 'unknown';
		}

		$latest = WorkspacePackageRegistryCatalog::getLatestVersion($catalog, $package_name);

		if ($latest === null) {
			return 'unknown';
		}

		return PluginVersionHelper::compare($version, $latest) < 0 ? 'behind' : 'up-to-date';
	}

	/**
	 * @param array<string, array<string, mixed>> $packages
	 * @return array<string, int>
	 */
	private static function summarizePackages(array $packages): array
	{
		$summary = [
			'total_packages' => count($packages),
			'dev_packages' => 0,
			'registry_packages' => 0,
			'fresh_up_to_date' => 0,
			'fresh_behind' => 0,
			'fresh_unknown' => 0,
		];

		foreach ($packages as $package) {
			if (($package['source_type'] ?? null) === 'dev') {
				$summary['dev_packages']++;
			} else {
				$summary['registry_packages']++;
			}

			$freshness = $package['freshness'] ?? 'unknown';

			if ($freshness === 'behind') {
				$summary['fresh_behind']++;
			} elseif ($freshness === 'up-to-date') {
				$summary['fresh_up_to_date']++;
			} else {
				$summary['fresh_unknown']++;
			}
		}

		return $summary;
	}

	/**
	 * Support mixed runtime states where PackageStateInspector is newer than the
	 * already-loaded PackageLocalOverrideHelper in a consumer app process.
	 *
	 * @return array<string, mixed>
	 */
	private static function loadLocalOverrideDocumentForAppRoot(string $app_root, ?string $dev_root = null): array
	{
		if (method_exists(PackageLocalOverrideHelper::class, 'loadLocalOverrideDocumentForAppRoot')) {
			/** @var array<string, mixed> */
			return PackageLocalOverrideHelper::loadLocalOverrideDocumentForAppRoot($app_root, $dev_root);
		}

		return PackageLocalOverrideHelper::loadLocalOverrideDocument();
	}

	/**
	 * Support mixed runtime states where PackageStateInspector is newer than the
	 * already-loaded PackageLocalOverrideHelper in a consumer app process.
	 *
	 * @return array{
	 *     manifest_version: int,
	 *     registries: array<string, array{name: string, url: string, resolved_url: string}>,
	 *     packages: array<string, array<string, mixed>>,
	 *     path: string,
	 *     base_dir: string
	 * }
	 */
	private static function loadEffectiveManifestForAppRoot(
		string $app_root,
		bool $ignore_local_overrides = false,
		?string $dev_root = null
	): array {
		if (method_exists(PackageLocalOverrideHelper::class, 'loadEffectiveManifestForAppRoot')) {
			return PackageLocalOverrideHelper::loadEffectiveManifestForAppRoot($app_root, $ignore_local_overrides, $dev_root);
		}

		return PackageLocalOverrideHelper::loadEffectiveManifest($ignore_local_overrides);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function decodeJsonFile(string $path): ?array
	{
		$json = @file_get_contents($path);

		if ($json === false || trim($json) === '') {
			return null;
		}

		try {
			$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}

		return is_array($data) ? $data : null;
	}

	private static function resolveStoredPath(string $path, string $base_dir): string
	{
		$normalized = str_replace('\\', '/', $path);

		if (str_starts_with($normalized, '/')) {
			return self::normalizePath($normalized);
		}

		return self::normalizePath(rtrim($base_dir, '/') . '/' . ltrim($normalized, '/'));
	}

	private static function normalizePath(string $path): string
	{
		$path = str_replace('\\', '/', $path);
		$prefix = str_starts_with($path, '/') ? '/' : '';

		if ($prefix === '/') {
			$path = substr($path, 1);
		}

		$segments = [];

		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			if ($segment === '..' && $segments !== [] && end($segments) !== '..') {
				array_pop($segments);

				continue;
			}

			$segments[] = $segment;
		}

		return rtrim($prefix . implode('/', $segments), '/');
	}
}
