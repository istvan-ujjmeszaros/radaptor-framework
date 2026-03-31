<?php

class PackageConfig
{
	/** @var array<string, array<string, mixed>> */
	private static array $_cache = [];

	/**
	 * @return array<string, mixed>
	 */
	public static function load(string $type, string $id, ?string $base_path = null): array
	{
		$type = PackageTypeHelper::normalizeType($type, 'Package config');
		$id = PackageTypeHelper::normalizeId($id, 'Package config');
		$resolved_base_path = self::resolveBasePath($type, $id, $base_path);
		$cache_key = implode(':', [$type, $id, $resolved_base_path ?? '']);

		if (isset(self::$_cache[$cache_key])) {
			return self::$_cache[$cache_key];
		}

		$config = [];
		$default_config_path = $resolved_base_path !== null
			? rtrim($resolved_base_path, '/') . '/config/default.php'
			: null;

		if ($default_config_path !== null && is_file($default_config_path)) {
			$config = array_replace_recursive($config, self::loadConfigFile($default_config_path));
		}

		foreach (self::getAppOverridePaths($type, $id) as $override_path) {
			if (!is_file($override_path)) {
				continue;
			}

			$config = array_replace_recursive($config, self::loadConfigFile($override_path));
		}

		self::$_cache[$cache_key] = $config;

		return $config;
	}

	public static function get(string $type, string $id, string $key, mixed $default = null, ?string $base_path = null): mixed
	{
		$config = self::load($type, $id, $base_path);

		return array_key_exists($key, $config) ? $config[$key] : $default;
	}

	public static function reset(): void
	{
		self::$_cache = [];
	}

	public static function getAppConfigDirectory(): string
	{
		return DEPLOY_ROOT . 'config/packages';
	}

	public static function getAppOverridePath(string $type, string $id, bool $local = false): string
	{
		$basename = self::buildConfigBasename($type, $id);
		$suffix = $local ? '.local.php' : '.php';

		return self::getAppConfigDirectory() . '/' . $basename . $suffix;
	}

	public static function getAppExamplePath(string $type, string $id): string
	{
		return self::getAppConfigDirectory() . '/' . self::buildConfigBasename($type, $id) . '.php.example';
	}

	/**
	 * @return list<string>
	 */
	private static function getAppOverridePaths(string $type, string $id): array
	{
		return [
			self::getAppOverridePath($type, $id, false),
			self::getAppOverridePath($type, $id, true),
		];
	}

	private static function buildConfigBasename(string $type, string $id): string
	{
		return PackageTypeHelper::normalizeType($type, 'Package config') . '.'
			. PackageTypeHelper::normalizeId($id, 'Package config');
	}

	private static function resolveBasePath(string $type, string $id, ?string $base_path): ?string
	{
		if (is_string($base_path) && trim($base_path) !== '') {
			return self::normalizePath($base_path);
		}

		$installed_path = self::resolveInstalledBasePath($type, $id);

		if ($installed_path !== null) {
			return $installed_path;
		}

		foreach (['dev', 'registry'] as $source_type) {
			$candidate = DEPLOY_ROOT . PackageTypeHelper::getDefaultPath($type, $source_type, $id);

			if (is_dir($candidate)) {
				return self::normalizePath($candidate);
			}
		}

		return null;
	}

	private static function resolveInstalledBasePath(string $type, string $id): ?string
	{
		$lock_path = PackageLockfile::getPath();

		if (!file_exists($lock_path)) {
			return null;
		}

		try {
			$lock = PackageLockfile::loadFromPath($lock_path);
		} catch (Throwable $exception) {
			throw new RuntimeException(
				"Unable to load package lockfile while resolving config base path: {$lock_path}",
				0,
				$exception
			);
		}

		$package = $lock['packages'][PackageTypeHelper::getKey($type, $id)] ?? null;

		if (!is_array($package)) {
			return null;
		}

		$resolved = is_array($package['resolved'] ?? null) ? $package['resolved'] : [];
		$source = is_array($package['source'] ?? null) ? $package['source'] : [];
		$resolved_path = $resolved['resolved_path'] ?? $source['resolved_path'] ?? null;

		if (!is_string($resolved_path) || $resolved_path === '') {
			$path = $resolved['path'] ?? $source['path'] ?? null;

			if (is_string($path) && $path !== '') {
				$resolved_path = str_starts_with($path, '/')
					? $path
					: DEPLOY_ROOT . ltrim($path, '/');
			}
		}

		if (!is_string($resolved_path) || $resolved_path === '' || !is_dir($resolved_path)) {
			return null;
		}

		return self::normalizePath($resolved_path);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function loadConfigFile(string $path): array
	{
		$config = require $path;

		if (!is_array($config)) {
			throw new RuntimeException("Package config file must return an array: {$path}");
		}

		return $config;
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
