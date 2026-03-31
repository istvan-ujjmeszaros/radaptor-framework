<?php

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

		foreach (['dev', 'registry'] as $source_type) {
			$default_path = DEPLOY_ROOT . PackageTypeHelper::getDefaultPath($type, $source_type, $id);

			if (is_dir($default_path)) {
				return self::normalizePath($default_path);
			}
		}

		return null;
	}

	public static function getFrameworkRoot(): ?string
	{
		$root = self::getPackageRoot('core', 'framework');

		if ($root !== null) {
			return $root;
		}

		$legacy_root = DEPLOY_ROOT . 'radaptor/radaptor-framework';

		return is_dir($legacy_root) ? self::normalizePath($legacy_root) : null;
	}

	public static function getCmsRoot(): ?string
	{
		$root = self::getPackageRoot('core', 'cms');

		if ($root !== null) {
			return $root;
		}

		$legacy_root = DEPLOY_ROOT . 'radaptor/radaptor-cms';

		return is_dir($legacy_root) ? self::normalizePath($legacy_root) : null;
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

		foreach ([
			'core:framework' => DEPLOY_ROOT . 'radaptor/radaptor-framework',
			'core:cms' => DEPLOY_ROOT . 'radaptor/radaptor-cms',
		] as $package_key => $legacy_root) {
			if (!isset($packages[$package_key])) {
				continue;
			}

			$normalized_legacy_root = self::normalizePath($legacy_root);
			$active_root = $packages[$package_key]['root'];

			if (
				$active_root !== $normalized_legacy_root
				&& ($normalized_path === $normalized_legacy_root || str_starts_with($normalized_path, $normalized_legacy_root . '/'))
			) {
				return true;
			}
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
			str_starts_with($relative, 'plugins/dev/'),
			str_starts_with($relative, 'core/dev/'),
			str_starts_with($relative, 'themes/dev/') => 30,
			str_starts_with($relative, 'plugins/registry/'),
			str_starts_with($relative, 'core/registry/'),
			str_starts_with($relative, 'themes/registry/') => 20,
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
				$stored_path = $resolved['path'] ?? $source['path'] ?? null;

				if (!is_string($stored_path) || trim($stored_path) === '') {
					continue;
				}

				$root = self::resolveStoragePath($stored_path);

				if (!is_dir($root)) {
					continue;
				}

				$packages[PackageTypeHelper::getKey($type, $id)] = [
					'root' => $root,
					'source_type' => trim((string) ($resolved['type'] ?? $source['type'] ?? '')),
					'type' => $type,
					'id' => $id,
				];
			}
		}

		self::$_activePackages = $packages;
		self::$_cacheKey = $cache_key;

		return self::$_activePackages;
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

		return preg_match('#^(core|themes|plugins)/(dev|registry)/[^/]+(?:/|$)#', $relative) === 1;
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
