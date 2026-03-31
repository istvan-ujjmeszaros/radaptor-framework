<?php

class PackageThemeScanHelper
{
	/** @var list<string>|null */
	private static ?array $_activeRoots = null;
	private static ?string $_activeRootsCacheKey = null;
	/** @var array<string, string>|null */
	private static ?array $_themeNamesByPackageRoot = null;

	public static function shouldSkipPath(string $path): bool
	{
		if (PackagePathHelper::shouldSkipPath($path)) {
			return true;
		}

		$normalized_path = self::normalizePath($path);
		$legacy_theme_name = self::resolveLegacyThemeNameFromPath($normalized_path);

		if ($legacy_theme_name !== null && self::hasActiveThemeName($legacy_theme_name)) {
			foreach (self::getActiveRoots() as $active_root) {
				if ($normalized_path === $active_root || str_starts_with($normalized_path, $active_root . '/')) {
					return false;
				}
			}

			return true;
		}

		if (!self::isThemePackagePath($normalized_path)) {
			return false;
		}

		$active_roots = self::getActiveRoots();

		if ($active_roots === []) {
			return true;
		}

		foreach ($active_roots as $active_root) {
			if ($normalized_path === $active_root || str_starts_with($normalized_path, $active_root . '/')) {
				return false;
			}

			if (str_starts_with($active_root, $normalized_path . '/')) {
				return false;
			}
		}

		return true;
	}

	public static function extractThemeName(string $path): ?string
	{
		$normalized_path = self::normalizePath($path);

		foreach (self::getThemeNamesByPackageRoot() as $package_root => $theme_name) {
			if ($normalized_path === $package_root || str_starts_with($normalized_path, $package_root . '/')) {
				return $theme_name;
			}
		}

		if (preg_match('#/themes/(?:dev|registry)/([^/]+)/(?:theme|core|plugins)/#', $normalized_path, $matches) === 1) {
			return $matches[1];
		}

		if (preg_match('#/themes/([^/]+)/#', $normalized_path, $matches) === 1) {
			return $matches[1];
		}

		if (preg_match('#/templates-common/default-([^/]+)/#', $normalized_path, $matches) === 1) {
			return $matches[1];
		}

		return null;
	}

	public static function reset(): void
	{
		self::$_activeRoots = null;
		self::$_activeRootsCacheKey = null;
		self::$_themeNamesByPackageRoot = null;
	}

	/**
	 * @return list<string>
	 */
	private static function getActiveRoots(): array
	{
		$cache_key = self::buildCacheKey();

		if (self::$_activeRoots !== null && self::$_activeRootsCacheKey === $cache_key) {
			return self::$_activeRoots;
		}

		if (!file_exists(PackageLockfile::getPath())) {
			self::$_activeRoots = [];
			self::$_activeRootsCacheKey = $cache_key;

			return self::$_activeRoots;
		}

		$lock = PackageLockfile::load();
		$packages = $lock['packages'];
		$plugin_ids = [];
		$core_ids = [];
		$roots = [];
		$theme_names_by_package_root = [];

		foreach ($packages as $package) {
			if (($package['type'] ?? null) === 'plugin') {
				$plugin_ids[] = PackageTypeHelper::normalizeId($package['id'] ?? null, 'Theme package owner');
			}

			if (($package['type'] ?? null) === 'core') {
				$core_ids[] = PackageTypeHelper::normalizeId($package['id'] ?? null, 'Theme package owner');
			}
		}

		$plugin_ids = array_values(array_unique($plugin_ids));
		$core_ids = array_values(array_unique($core_ids));
		sort($plugin_ids);
		sort($core_ids);

		foreach ($packages as $package) {
			if (($package['type'] ?? null) !== 'theme') {
				continue;
			}

			$package_root = PackagePathHelper::getPackageRoot(
				PackageTypeHelper::normalizeType($package['type'] ?? null, 'Theme package'),
				PackageTypeHelper::normalizeId($package['id'] ?? null, 'Theme package')
			);

			if (!is_string($package_root) || !is_dir($package_root)) {
				continue;
			}

			$package_root = self::normalizePath($package_root);
			$theme_name = self::resolveThemeName($package_root);

			if ($theme_name !== null) {
				$theme_names_by_package_root[$package_root] = $theme_name;
			}

			$theme_root = $package_root . '/theme';

			if (is_dir($theme_root)) {
				$roots[] = $theme_root;
			}

			foreach ($core_ids as $core_id) {
				$core_root = $package_root . '/core/' . $core_id;

				if (is_dir($core_root)) {
					$roots[] = $core_root;
				}
			}

			foreach ($plugin_ids as $plugin_id) {
				$plugin_root = $package_root . '/plugins/' . $plugin_id;

				if (is_dir($plugin_root)) {
					$roots[] = $plugin_root;
				}
			}
		}

		$roots = array_values(array_unique(array_map([self::class, 'normalizePath'], $roots)));
		sort($roots);
		self::$_activeRoots = $roots;
		self::$_themeNamesByPackageRoot = $theme_names_by_package_root;
		self::$_activeRootsCacheKey = $cache_key;

		return self::$_activeRoots;
	}

	/**
	 * @return array<string, string>
	 */
	private static function getThemeNamesByPackageRoot(): array
	{
		self::getActiveRoots();

		return self::$_themeNamesByPackageRoot ?? [];
	}

	private static function buildCacheKey(): string
	{
		$path = PackageLockfile::getPath();

		if (!file_exists($path)) {
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

	private static function isThemePackagePath(string $path): bool
	{
		$relative = str_replace('\\', '/', str_replace(DEPLOY_ROOT, '', $path));
		$relative = ltrim($relative, '/');

		return preg_match('#^themes/(dev|registry)/[^/]+(?:/|$)#', $relative) === 1;
	}

	private static function resolveThemeName(string $package_root): ?string
	{
		$search_roots = [
			rtrim($package_root, '/') . '/theme',
			$package_root,
		];

		foreach ($search_roots as $search_root) {
			if (!is_dir($search_root)) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($search_root, FilesystemIterator::SKIP_DOTS)
			);

			foreach ($iterator as $file) {
				if (!$file instanceof SplFileInfo || !$file->isFile()) {
					continue;
				}

				$filename = $file->getFilename();

				if (preg_match('/^ThemeData\.(.+)\.php$/', $filename, $name_matches) === 1) {
					return $name_matches[1];
				}
			}
		}

		return null;
	}

	private static function resolveLegacyThemeNameFromPath(string $path): ?string
	{
		if (preg_match('#/app/themes/([^/]+)(?:/|$)#', $path, $matches) === 1) {
			return $matches[1];
		}

		if (preg_match('#/templates-common/default-([^/]+)(?:/|$)#', $path, $matches) === 1) {
			return $matches[1];
		}

		return null;
	}

	private static function hasActiveThemeName(string $theme_name): bool
	{
		return in_array($theme_name, array_values(self::getThemeNamesByPackageRoot()), true);
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
