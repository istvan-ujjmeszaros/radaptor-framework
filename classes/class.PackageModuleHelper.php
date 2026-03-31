<?php

class PackageModuleHelper
{
	public static function buildModule(string $type, string $id): string
	{
		return PackageTypeHelper::normalizeType($type, 'Package module') . ':'
			. PackageTypeHelper::normalizeId($id, 'Package module');
	}

	public static function buildModuleFromPackageKey(string $package_key): string
	{
		$parts = explode(':', $package_key, 2);

		if (count($parts) !== 2) {
			throw new RuntimeException("Package key '{$package_key}' does not map to a package module.");
		}

		return self::buildModule($parts[0], $parts[1]);
	}

	public static function normalizeRequestedModule(string $module): string
	{
		$module = trim($module);

		if ($module === '') {
			throw new RuntimeException('Requested module name must not be empty.');
		}

		if ($module === 'framework' || $module === 'app') {
			return $module;
		}

		if (str_contains($module, ':')) {
			[$type, $id] = explode(':', $module, 2);

			return self::buildModule($type, $id);
		}

		return self::buildModule('plugin', $module);
	}

	public static function isPackageModule(string $module): bool
	{
		return preg_match('/^(core|theme|plugin):[a-z0-9][a-z0-9_-]*$/', trim($module)) === 1;
	}
}
