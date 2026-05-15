<?php

class LocalRegistryRootResolver
{
	public static function resolve(?string $registry_root = null): string
	{
		if (is_string($registry_root) && trim($registry_root) !== '') {
			$resolved = self::normalizePath(trim($registry_root));

			if (!is_dir($resolved)) {
				throw new RuntimeException("Package registry root does not exist: {$resolved}");
			}

			return $resolved;
		}

		$candidates = [];

		$env_root = getenv('RADAPTOR_PACKAGE_REGISTRY_ROOT');

		if (is_string($env_root) && trim($env_root) !== '') {
			$candidates[] = trim($env_root);
		}

		$candidates[] = DEPLOY_ROOT . '../radaptor_package_registry';
		$candidates[] = DEPLOY_ROOT . '../radaptor-package-registry';
		$candidates[] = '/workspace/radaptor_package_registry';
		$candidates[] = '/workspace/radaptor-package-registry';
		$candidates[] = '/radaptor_package_registry';
		$candidates[] = '/radaptor-package-registry';

		foreach ($candidates as $candidate) {
			$resolved = self::normalizePath($candidate);

			if (is_dir($resolved)) {
				return $resolved;
			}
		}

		throw new RuntimeException('Unable to locate the local package registry root. Pass --registry-root or set RADAPTOR_PACKAGE_REGISTRY_ROOT.');
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
