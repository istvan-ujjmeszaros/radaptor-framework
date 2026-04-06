<?php

class PackagePublishService
{
	/**
	 * @return array{
	 *     package_key: string,
	 *     package: string,
	 *     version: string,
	 *     source_path: string,
	 *     registry_root: string,
	 *     registry_rebuilt: bool,
	 *     packaged_files: int,
	 *     dist_url: string,
	 *     dist_path: string,
	 *     sha256: string,
	 *     build: array<string, mixed>|null
	 * }
	 */
	public static function publish(
		string $package_key,
		?string $registry_root = null,
		?string $manifest_path = null,
		?string $lock_path = null
	): array {
		[$type, $id] = self::splitPackageKey($package_key);
		$source_path = self::resolveSourcePath($type, $id, $manifest_path, $lock_path);

		return self::publishFromSourcePath($source_path, $registry_root, $package_key);
	}

	/**
	 * @return array{
	 *     registry_root: string,
	 *     package_keys: list<string>,
	 *     published: array<string, array<string, mixed>>
	 * }
	 */
	public static function publishAll(
		?string $registry_root = null,
		?string $manifest_path = null,
		?string $lock_path = null
	): array {
		$registry_root = LocalRegistryRootResolver::resolve($registry_root);
		$discovered = self::discoverPublishablePackages($manifest_path, $lock_path);

		if ($discovered === []) {
			throw new RuntimeException('No publishable first-party packages were found under packages/dev/.');
		}

		$published = [];

		foreach ($discovered as $package_key => $source_path) {
			$published[$package_key] = self::publishFromSourcePath($source_path, $registry_root, $package_key);
		}

		return [
			'registry_root' => $registry_root,
			'package_keys' => array_keys($published),
			'published' => $published,
		];
	}

	/**
	 * @return array{
	 *     package_key: string,
	 *     package: string,
	 *     version: string,
	 *     source_path: string,
	 *     registry_root: string,
	 *     registry_rebuilt: bool,
	 *     packaged_files: int,
	 *     dist_url: string,
	 *     dist_path: string,
	 *     sha256: string,
	 *     build: array<string, mixed>|null
	 * }
	 */
	public static function publishFromSourcePath(
		string $source_path,
		?string $registry_root = null,
		?string $expected_package_key = null
	): array {
		$source_path = self::normalizePath($source_path);

		if (!is_dir($source_path)) {
			throw new RuntimeException("Package source directory does not exist: {$source_path}");
		}

		$repository = GitRepositoryInspector::inspect($source_path);

		if ($repository['git_available'] !== true || $repository['is_repository'] !== true) {
			throw new RuntimeException("First-party package source must live in a Git repository before publishing: {$source_path}");
		}

		$tracked_files = GitRepositoryInspector::listTrackedFiles($source_path);
		$metadata_relative_path = '.registry-package.json';

		if (is_file($source_path . '/' . $metadata_relative_path) && !in_array($metadata_relative_path, $tracked_files, true)) {
			$tracked_files[] = $metadata_relative_path;
			sort($tracked_files);
		}

		if ($tracked_files === []) {
			throw new RuntimeException("Package repository does not contain tracked files: {$source_path}");
		}

		$metadata = PackageMetadataHelper::loadFromSourcePath($source_path);
		$package_key = PackageTypeHelper::getKey($metadata['type'], $metadata['id']);

		if ($expected_package_key !== null && $package_key !== $expected_package_key) {
			throw new RuntimeException("Package metadata '{$package_key}' does not match requested package '{$expected_package_key}'.");
		}

		$registry_root = LocalRegistryRootResolver::resolve($registry_root);
		$build = LocalPackageRegistryBuilder::publishPackage($registry_root, $source_path, $metadata, $tracked_files);

		return [
			'package_key' => $package_key,
			'package' => $metadata['package'],
			'version' => $metadata['version'],
			'source_path' => $source_path,
			'registry_root' => $registry_root,
			'registry_rebuilt' => true,
			'packaged_files' => $build['packaged_files'],
			'dist_url' => $build['dist_url'],
			'dist_path' => $build['dist_path'],
			'sha256' => $build['sha256'],
			'build' => $build,
		];
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private static function splitPackageKey(string $package_key): array
	{
		$parts = explode(':', trim($package_key), 2);

		if (count($parts) !== 2) {
			throw new RuntimeException("Invalid package key '{$package_key}'. Use '<type>:<id>'.");
		}

		return [
			PackageTypeHelper::normalizeType($parts[0], 'Package'),
			PackageTypeHelper::normalizeId($parts[1], 'Package'),
		];
	}

	private static function resolveSourcePath(string $type, string $id, ?string $manifest_path, ?string $lock_path): string
	{
		$default_dev_path = self::normalizePath(DEPLOY_ROOT . PackageTypeHelper::getDefaultPath($type, 'dev', $id));

		if (is_dir($default_dev_path) && is_file($default_dev_path . '/.registry-package.json')) {
			return $default_dev_path;
		}

		foreach (self::discoverCandidateSourcePaths($type, $id, $manifest_path, $lock_path) as $candidate) {
			if (is_dir($candidate) && is_file($candidate . '/.registry-package.json')) {
				return $candidate;
			}
		}

		throw new RuntimeException("Unable to locate a publishable source checkout for '{$type}:{$id}'.");
	}

	/**
	 * @return list<string>
	 */
	private static function discoverCandidateSourcePaths(string $type, string $id, ?string $manifest_path, ?string $lock_path): array
	{
		$paths = [];
		$package_key = PackageTypeHelper::getKey($type, $id);

		foreach ([self::loadManifestSafely($manifest_path), self::loadLockfileSafely($lock_path)] as $document) {
			if ($document === null) {
				continue;
			}

			$package = $document['packages'][$package_key] ?? null;

			if (!is_array($package)) {
				continue;
			}

			foreach (['source', 'resolved'] as $section) {
				$path = trim((string) (($package[$section]['resolved_path'] ?? $package[$section]['path'] ?? '')));

				if ($path === '') {
					continue;
				}

				$paths[] = self::normalizePath($path);
			}
		}

		$paths[] = self::normalizePath(DEPLOY_ROOT . PackageTypeHelper::getDefaultPath($type, 'dev', $id));

		return array_values(array_unique($paths));
	}

	/**
	 * @return array{
	 *     packages: array<string, array<string, mixed>>
	 * }|null
	 */
	private static function loadManifestSafely(?string $manifest_path): ?array
	{
		try {
			return PackageManifest::loadFromPath($manifest_path ?? PackageManifest::getPath());
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * @return array{
	 *     packages: array<string, array<string, mixed>>
	 * }|null
	 */
	private static function loadLockfileSafely(?string $lock_path): ?array
	{
		try {
			return PackageLockfile::loadFromPath($lock_path ?? PackageLockfile::getPath());
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * @return array<string, string>
	 */
	private static function discoverPublishablePackages(?string $manifest_path, ?string $lock_path): array
	{
		$discovered = [];

		foreach ([self::loadManifestSafely($manifest_path), self::loadLockfileSafely($lock_path)] as $document) {
			if ($document === null) {
				continue;
			}

			foreach ($document['packages'] as $package_key => $package) {
				if (($package['type'] ?? null) === 'plugin') {
					continue;
				}

				foreach (self::discoverCandidateSourcePaths(
					(string) ($package['type'] ?? ''),
					(string) ($package['id'] ?? ''),
					$manifest_path,
					$lock_path
				) as $candidate) {
					if (is_file($candidate . '/.registry-package.json')) {
						$discovered[$package_key] = $candidate;
						break;
					}
				}
			}
		}

		foreach ([
			DEPLOY_ROOT . 'packages/dev/core/*/.registry-package.json',
			DEPLOY_ROOT . 'packages/dev/themes/*/.registry-package.json',
		] as $pattern) {
			$matches = glob($pattern) ?: [];
			sort($matches);

			foreach ($matches as $metadata_path) {
				$source_path = self::normalizePath(dirname($metadata_path));
				$metadata = PackageMetadataHelper::loadFromSourcePath($source_path);
				$package_key = PackageTypeHelper::getKey($metadata['type'], $metadata['id']);
				$discovered[$package_key] = $source_path;
			}
		}

		uksort($discovered, static function (string $left, string $right): int {
			[$left_type, $left_id] = explode(':', $left, 2);
			[$right_type, $right_id] = explode(':', $right, 2);
			$type_order = ['core' => 0, 'theme' => 1, 'plugin' => 2];

			return [$type_order[$left_type] ?? 99, $left_id] <=> [$type_order[$right_type] ?? 99, $right_id];
		});

		return $discovered;
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
}
