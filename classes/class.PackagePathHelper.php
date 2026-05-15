<?php

require_once __DIR__ . '/class.PackageLocalOverrideHelper.php';

class PackagePathHelper
{
	/** @var array<string, array{root: string, source_type: string, type: string, id: string}>|null */
	private static ?array $_activePackages = null;

	private static ?string $_cacheKey = null;

	public static function reset(): void
	{
		self::$_activePackages = null;
		self::$_cacheKey = null;
	}

	public static function getPackageRoot(string $type, string $id): ?string
	{
		$key = PackageTypeHelper::getKey($type, $id);
		$packages = self::getActivePackages();

		if (isset($packages[$key])) {
			return $packages[$key]['root'];
		}

		$manifest = PackageLocalOverrideHelper::loadEffectiveManifest();
		$manifest_package = is_array($manifest['packages'][$key] ?? null) ? $manifest['packages'][$key] : null;
		$manifest_source = is_array($manifest_package['source'] ?? null) ? $manifest_package['source'] : [];
		$manifest_root = trim((string) ($manifest_source['resolved_path'] ?? ''));

		if ($manifest_root !== '' && is_dir($manifest_root)) {
			return self::normalizePath($manifest_root);
		}

		foreach (self::getDefaultFallbackSourceTypes($type) as $source_type) {
			$default_path = DEPLOY_ROOT . PackageTypeHelper::getDefaultPath($type, $source_type, $id);

			if (is_dir($default_path)) {
				return self::normalizePath($default_path);
			}
		}

		return null;
	}

	public static function getFrameworkRoot(): ?string
	{
		return self::getPackageRoot('core', 'framework');
	}

	public static function getCmsRoot(): ?string
	{
		return self::getPackageRoot('core', 'cms');
	}

	/**
	 * @param list<string>|null $types
	 * @return list<string>
	 */
	public static function getActivePackageRoots(?array $types = null): array
	{
		$roots = [];
		$type_filter = $types !== null ? array_fill_keys(array_map(
			static fn (string $type): string => PackageTypeHelper::normalizeType($type),
			$types
		), true) : null;

		foreach (self::getActivePackages() as $package) {
			if ($type_filter !== null && !isset($type_filter[$package['type']])) {
				continue;
			}

			$roots[] = $package['root'];
		}

		$roots = array_values(array_unique(array_map([self::class, 'normalizePath'], $roots)));
		sort($roots);

		return $roots;
	}

	/**
	 * @param list<string>|null $types
	 * @return list<string>
	 */
	public static function getScannableRoots(?array $types = null): array
	{
		$roots = [self::normalizePath(DEPLOY_ROOT)];

		foreach (self::getActivePackageRoots($types) as $root) {
			if (!self::isPathInside($root, DEPLOY_ROOT)) {
				$roots[] = $root;
			}
		}

		$roots = array_values(array_unique(array_map([self::class, 'normalizePath'], $roots)));
		sort($roots);

		return $roots;
	}

	public static function shouldSkipPath(string $path): bool
	{
		$normalized_path = self::normalizePath($path);
		$packages = self::getActivePackages();

		if ($packages === []) {
			return false;
		}

		foreach ($packages as $package) {
			if ($normalized_path === $package['root'] || str_starts_with($normalized_path, $package['root'] . '/')) {
				return false;
			}
		}

		return self::isManagedPackagePath($normalized_path);
	}

	public static function getPathPriority(string $absolute_path): int
	{
		$normalized_path = self::normalizePath($absolute_path);
		$matched_packages = [];

		foreach (self::getActivePackages() as $package) {
			if ($normalized_path === $package['root'] || str_starts_with($normalized_path, $package['root'] . '/')) {
				$matched_packages[] = $package;
			}
		}

		if ($matched_packages !== []) {
			usort(
				$matched_packages,
				static fn (array $left, array $right): int => strlen($right['root']) <=> strlen($left['root'])
			);

			return $matched_packages[0]['source_type'] === 'dev' ? 30 : 20;
		}

		$relative = self::toStoragePath($normalized_path);

		return match (true) {
			str_starts_with($relative, 'packages/dev/core/'),
			str_starts_with($relative, 'packages/dev/themes/') => 30,
			str_starts_with($relative, 'packages/registry/core/'),
			str_starts_with($relative, 'packages/registry/themes/') => 20,
			default => 10,
		};
	}

	public static function toStoragePath(string $absolute_path): string
	{
		$normalized_path = self::normalizePath($absolute_path);
		$normalized_root = self::normalizePath(DEPLOY_ROOT);

		if ($normalized_path === $normalized_root) {
			return '';
		}

		if (self::isPathInside($normalized_path, $normalized_root)) {
			return ltrim(substr($normalized_path, strlen($normalized_root)), '/');
		}

		return $normalized_path;
	}

	public static function resolveStoragePath(string $path): string
	{
		if (str_starts_with($path, '/')) {
			return self::normalizePath($path);
		}

		return self::normalizePath(DEPLOY_ROOT . ltrim($path, '/'));
	}

	public static function shortenPath(string $path): string
	{
		$normalized_path = self::normalizePath($path);
		$framework_root = self::getPackageRoot('core', 'framework');
		$cms_root = self::getPackageRoot('core', 'cms');
		$app_root = self::normalizePath(DEPLOY_ROOT . 'app');

		if (is_string($framework_root) && self::isPathInside($normalized_path, $framework_root)) {
			return 'fw/' . ltrim(substr($normalized_path, strlen($framework_root)), '/');
		}

		if (is_string($cms_root) && self::isPathInside($normalized_path, $cms_root)) {
			return 'cms/' . ltrim(substr($normalized_path, strlen($cms_root)), '/');
		}

		if (self::isPathInside($normalized_path, $app_root)) {
			return ltrim(substr($normalized_path, strlen($app_root)), '/');
		}

		return self::toStoragePath($normalized_path);
	}

	/**
	 * @return array<string, array{root: string, source_type: string, type: string, id: string}>
	 */
	private static function getActivePackages(): array
	{
		$cache_key = self::buildCacheKey();

		if (self::$_activePackages !== null && self::$_cacheKey === $cache_key) {
			return self::$_activePackages;
		}

		$packages = [];
		$lock_path = PackageLockfile::getPath();

		if (is_file($lock_path)) {
			$lock = PackageLockfile::load();

			foreach ($lock['packages'] as $package) {
				$type = PackageTypeHelper::normalizeType($package['type'] ?? null, 'Active package');
				$id = PackageTypeHelper::normalizeId($package['id'] ?? null, 'Active package');
				$resolved = is_array($package['resolved'] ?? null) ? $package['resolved'] : [];
				$source = is_array($package['source'] ?? null) ? $package['source'] : [];
				$active_root = self::resolveActivePackageRoot($type, $id, $resolved, $source);

				if ($active_root === null) {
					continue;
				}

				$packages[PackageTypeHelper::getKey($type, $id)] = [
					'root' => $active_root['root'],
					'source_type' => $active_root['source_type'],
					'type' => $type,
					'id' => $id,
				];
			}
		}

		$manifest = PackageLocalOverrideHelper::loadEffectiveManifest();

		foreach ($manifest['packages'] as $package) {
			$type = PackageTypeHelper::normalizeType($package['type'] ?? null, 'Manifest package');
			$id = PackageTypeHelper::normalizeId($package['id'] ?? null, 'Manifest package');
			$key = PackageTypeHelper::getKey($type, $id);

			if (isset($packages[$key])) {
				continue;
			}

			$source = is_array($package['source'] ?? null) ? $package['source'] : [];
			$active_root = self::resolveActivePackageRoot($type, $id, [], $source);

			if ($active_root === null) {
				continue;
			}

			$packages[$key] = [
				'root' => $active_root['root'],
				'source_type' => $active_root['source_type'],
				'type' => $type,
				'id' => $id,
			];
		}

		self::$_activePackages = $packages;
		self::$_cacheKey = $cache_key;

		return self::$_activePackages;
	}

	/**
	 * @return array{root: string, source_type: string}|null
	 */
	private static function resolveActivePackageRoot(string $type, string $id, array $resolved, array $source): ?array
	{
		/** @var list<array{path: string, source_type: string}> $candidate_paths */
		$candidate_paths = [];

		foreach (
			[
				['path' => $resolved['path'] ?? null, 'source_type' => trim((string) ($resolved['type'] ?? ''))],
				['path' => $source['path'] ?? null, 'source_type' => trim((string) ($source['type'] ?? ''))],
			] as $candidate
		) {
			if (!is_string($candidate['path']) || trim($candidate['path']) === '') {
				continue;
			}

			$candidate_paths[] = [
				'path' => $candidate['path'],
				'source_type' => in_array($candidate['source_type'], ['dev', 'registry'], true)
					? $candidate['source_type']
					: '',
			];
		}

		$override_root = PackageLocalOverrideHelper::getResolvedOverridePath($type, $id);

		if (is_string($override_root) && $override_root !== '') {
			$candidate_paths[] = [
				'path' => $override_root,
				'source_type' => 'dev',
			];
		}

		foreach (self::getDefaultFallbackSourceTypes($type) as $source_type) {
			$candidate_paths[] = [
				'path' => PackageTypeHelper::getDefaultPath($type, $source_type, $id),
				'source_type' => $source_type,
			];
		}

		$seen = [];

		foreach ($candidate_paths as $candidate) {
			$stored_path = $candidate['path'];

			if (isset($seen[$stored_path])) {
				continue;
			}

			$seen[$stored_path] = true;
			$root = self::resolveStoragePath($stored_path);

			if (is_dir($root)) {
				return [
					'root' => $root,
					'source_type' => $candidate['source_type'] !== '' ? $candidate['source_type'] : 'registry',
				];
			}
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	private static function getDefaultFallbackSourceTypes(string $type): array
	{
		PackageTypeHelper::normalizeType($type, 'Package');

		return ['registry'];
	}

	private static function buildCacheKey(): string
	{
		$path = PackageLockfile::getPath();

		if (!is_file($path)) {
			return 'missing';
		}

		$mtime = filemtime($path);
		$size = filesize($path);

		return implode(':', [
			$path,
			$mtime === false ? 'mtime-missing' : (string) $mtime,
			$size === false ? 'size-missing' : (string) $size,
		]);
	}

	private static function isManagedPackagePath(string $path): bool
	{
		$relative = self::toStoragePath($path);

		return preg_match('#^packages/(dev|registry)/(core|themes)/[^/]+(?:/|$)#', $relative) === 1;
	}

	private static function isPathInside(string $path, string $root): bool
	{
		$normalized_path = self::normalizePath($path);
		$normalized_root = self::normalizePath($root);

		return $normalized_path === $normalized_root || str_starts_with($normalized_path, $normalized_root . '/');
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
