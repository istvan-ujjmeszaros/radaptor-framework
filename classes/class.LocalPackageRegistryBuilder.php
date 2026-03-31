<?php

class LocalPackageRegistryBuilder
{
	/** @var array<int, string> */
	private const array DEFAULT_DIST_EXCLUDE = [
		'.git',
		'.githooks',
		'.gitignore',
		'.php-cs-fixer.php',
		'.php-cs-fixer.cache',
		'.registry-package.json',
	];

	/**
	 * @param array{
	 *     package: string,
	 *     type: string,
	 *     id: string,
	 *     version: string,
	 *     dependencies: array<string, string>,
	 *     composer: array{
	 *         require: array<string, string>
	 *     },
	 *     assets: array{
	 *         public: list<array{source: string, target: string}>
	 *     },
	 *     dist_exclude: list<string>
	 * } $metadata
	 * @param list<string> $tracked_files
	 * @return array{
	 *     registry_root: string,
	 *     registry_path: string,
	 *     package: string,
	 *     type: string,
	 *     id: string,
	 *     version: string,
	 *     dependencies: array<string, string>,
	 *     composer_require: array<string, string>,
	 *     assets: array{
	 *         public: list<array{source: string, target: string}>
	 *     },
	 *     dist_url: string,
	 *     dist_path: string,
	 *     sha256: string,
	 *     packaged_files: int
	 * }
	 */
	public static function publishPackage(string $registry_root, string $package_root, array $metadata, array $tracked_files): array
	{
		$registry_root = self::normalizePath($registry_root);
		$package_root = self::normalizePath($package_root);
		$packages_dir = $registry_root . '/packages';
		$registry_path = $registry_root . '/registry.json';

		if (!is_dir($package_root)) {
			throw new RuntimeException("Package source directory does not exist: {$package_root}");
		}

		if (!is_dir($packages_dir) && !mkdir($packages_dir, 0o777, true) && !is_dir($packages_dir)) {
			throw new RuntimeException("Unable to create registry packages directory: {$packages_dir}");
		}

		$registry = self::loadExistingRegistry($registry_path);
		self::migrateLegacyArtifactPaths($registry_root, $registry);
		$archive = self::buildPackageArchive($registry_root, $package_root, $metadata, $tracked_files);

		if (!isset($registry['packages'][$metadata['package']])) {
			$registry['packages'][$metadata['package']] = [
				'latest' => $metadata['version'],
				'versions' => [],
			];
		}

		if (!isset($registry['packages'][$metadata['package']]['versions']) || !is_array($registry['packages'][$metadata['package']]['versions'])) {
			$registry['packages'][$metadata['package']]['versions'] = [];
		}

		$registry['packages'][$metadata['package']]['latest'] = $metadata['version'];
		$registry['packages'][$metadata['package']]['versions'][$metadata['version']] = [
			'type' => $metadata['type'],
			'id' => $metadata['id'],
			'dependencies' => $metadata['dependencies'],
			'composer' => [
				'require' => $metadata['composer']['require'],
			],
			'assets' => [
				'public' => $metadata['assets']['public'],
			],
			'dist' => [
				'type' => 'zip',
				'url' => $archive['url'],
				'sha256' => $archive['sha256'],
			],
		];

		self::sortRegistry($registry);
		$json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		if (file_put_contents($registry_path, $json . "\n", LOCK_EX) === false) {
			throw new RuntimeException("Unable to write local package registry catalog: {$registry_path}");
		}

		return [
			'registry_root' => $registry_root,
			'registry_path' => $registry_path,
			'package' => $metadata['package'],
			'type' => $metadata['type'],
			'id' => $metadata['id'],
			'version' => $metadata['version'],
			'dependencies' => $metadata['dependencies'],
			'composer_require' => $metadata['composer']['require'],
			'assets' => $metadata['assets'],
			'dist_url' => $archive['url'],
			'dist_path' => $archive['path'],
			'sha256' => $archive['sha256'],
			'packaged_files' => $archive['packaged_files'],
		];
	}

	/**
	 * @return array{
	 *     registry_version: int,
	 *     name: string,
	 *     packages: array<string, array<string, mixed>>
	 * }
	 */
	private static function loadExistingRegistry(string $registry_path): array
	{
		if (!file_exists($registry_path)) {
			return [
				'registry_version' => 1,
				'name' => 'Local Radaptor Package Registry',
				'packages' => [],
			];
		}

		$json = file_get_contents($registry_path);

		if ($json === false || trim($json) === '') {
			return [
				'registry_version' => 1,
				'name' => 'Local Radaptor Package Registry',
				'packages' => [],
			];
		}

		try {
			$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return [
				'registry_version' => 1,
				'name' => 'Local Radaptor Package Registry',
				'packages' => [],
			];
		}

		if (!is_array($data)) {
			return [
				'registry_version' => 1,
				'name' => 'Local Radaptor Package Registry',
				'packages' => [],
			];
		}

		return [
			'registry_version' => 1,
			'name' => is_string($data['name'] ?? null) && trim((string) $data['name']) !== '' ? trim((string) $data['name']) : 'Local Radaptor Package Registry',
			'packages' => is_array($data['packages'] ?? null) ? $data['packages'] : [],
		];
	}

	/**
	 * @param array{
	 *     package: string,
	 *     type: string,
	 *     id: string,
	 *     version: string,
	 *     dist_exclude: list<string>
	 * } $metadata
	 * @param list<string> $tracked_files
	 * @return array{
	 *     url: string,
	 *     path: string,
	 *     sha256: string,
	 *     packaged_files: int
	 * }
	 */
	private static function buildPackageArchive(string $registry_root, string $package_root, array $metadata, array $tracked_files): array
	{
		$package_slug = str_replace('/', '-', $metadata['package']);
		$archive_relative_path = 'packages/' . $package_slug . '/' . $metadata['version'] . '/plugin.zip';
		$archive_path = $registry_root . '/' . $archive_relative_path;
		$archive_dir = dirname($archive_path);

		if (!is_dir($archive_dir) && !mkdir($archive_dir, 0o777, true) && !is_dir($archive_dir)) {
			throw new RuntimeException("Unable to create package distribution directory: {$archive_dir}");
		}

		if (file_exists($archive_path) && !unlink($archive_path)) {
			throw new RuntimeException("Unable to replace existing package distribution archive: {$archive_path}");
		}

		$files = self::listPackageFiles($package_root, $metadata, $tracked_files);
		$zip = new ZipArchive();
		$open_result = $zip->open($archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

		if ($open_result !== true) {
			throw new RuntimeException("Unable to create package distribution archive: {$archive_path}");
		}

		foreach ($files as $file_path) {
			$relative_path = ltrim(substr($file_path, strlen(rtrim($package_root, '/'))), '/');
			$zip->addFile($file_path, $relative_path);
		}

		$zip->close();
		$sha256 = hash_file('sha256', $archive_path);

		if ($sha256 === false) {
			throw new RuntimeException("Unable to hash package distribution archive: {$archive_path}");
		}

		return [
			'url' => $archive_relative_path,
			'path' => $archive_path,
			'sha256' => strtolower($sha256),
			'packaged_files' => count($files),
		];
	}

	/**
	 * @param array{
	 *     registry_version: int,
	 *     name: string,
	 *     packages: array<string, array<string, mixed>>
	 * } $registry
	 */
	private static function migrateLegacyArtifactPaths(string $registry_root, array &$registry): void
	{
		foreach ($registry['packages'] as $package_name => &$package_entry) {
			if (!is_array($package_entry['versions'] ?? null)) {
				continue;
			}

			foreach ($package_entry['versions'] as $version => &$version_entry) {
				$dist = $version_entry['dist'] ?? null;

				if (!is_array($dist) || !is_string($dist['url'] ?? null)) {
					continue;
				}

				$current_url = $dist['url'];
				$target_url = 'packages/' . str_replace('/', '-', (string) $package_name) . '/' . $version . '/plugin.zip';

				if ($current_url === $target_url) {
					continue;
				}

				$current_path = $registry_root . '/' . ltrim($current_url, '/');
				$target_path = $registry_root . '/' . $target_url;

				if (!file_exists($current_path) && file_exists($target_path)) {
					$version_entry['dist']['url'] = $target_url;

					continue;
				}

				if (!file_exists($current_path)) {
					continue;
				}

				$target_dir = dirname($target_path);

				if (!is_dir($target_dir) && !mkdir($target_dir, 0o777, true) && !is_dir($target_dir)) {
					throw new RuntimeException("Unable to create migrated artifact directory: {$target_dir}");
				}

				if (!rename($current_path, $target_path)) {
					throw new RuntimeException("Unable to migrate legacy package artifact to versioned path: {$current_path}");
				}

				$version_entry['dist']['url'] = $target_url;
			}
			unset($version_entry);
		}
		unset($package_entry);
	}

	/**
	 * @param array{
	 *     registry_version: int,
	 *     name: string,
	 *     packages: array<string, array<string, mixed>>
	 * } $registry
	 */
	private static function sortRegistry(array &$registry): void
	{
		foreach ($registry['packages'] as &$package_entry) {
			if (isset($package_entry['versions']) && is_array($package_entry['versions'])) {
				ksort($package_entry['versions']);
			}
		}
		unset($package_entry);
		ksort($registry['packages']);
	}

	/**
	 * @param array{
	 *     dist_exclude: list<string>
	 * } $metadata
	 * @param list<string> $tracked_files
	 * @return list<string>
	 */
	private static function listPackageFiles(string $package_root, array $metadata, array $tracked_files): array
	{
		$files = [];

		foreach ($tracked_files as $relative_path) {
			$normalized_relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');

			if (!self::shouldIncludeInArchive($normalized_relative_path, $metadata['dist_exclude'])) {
				continue;
			}

			$file_path = $package_root . '/' . $normalized_relative_path;

			if (!is_file($file_path)) {
				continue;
			}

			$files[] = str_replace('\\', '/', $file_path);
		}

		sort($files);

		return $files;
	}

	/**
	 * @param list<string> $extra_patterns
	 */
	private static function shouldIncludeInArchive(string $relative_path, array $extra_patterns): bool
	{
		$parts = explode('/', str_replace('\\', '/', $relative_path));

		foreach ($parts as $part) {
			if (in_array($part, self::DEFAULT_DIST_EXCLUDE, true)) {
				return false;
			}
		}

		foreach ($extra_patterns as $pattern) {
			if (fnmatch($pattern, $relative_path)) {
				return false;
			}
		}

		return true;
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
