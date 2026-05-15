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

		foreach ([
			'RADAPTOR_PACKAGE_REGISTRY_ROOT',
			'RADAPTOR_PLUGIN_REGISTRY_ROOT',
		] as $env_name) {
			$env_root = getenv($env_name);

			if (is_string($env_root) && trim($env_root) !== '') {
				$candidates[] = trim($env_root);
			}
		}

		$candidates[] = DEPLOY_ROOT . '../radaptor_plugin_registry';
		$candidates[] = DEPLOY_ROOT . '../radaptor-plugin-registry';
		$candidates[] = '/workspace/radaptor_plugin_registry';
		$candidates[] = '/workspace/radaptor-plugin-registry';
		$candidates[] = '/radaptor_plugin_registry';
		$candidates[] = '/radaptor-plugin-registry';

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
