<?php

class PluginRegistryClient
{
	/**
	 * @param array<string, mixed> $registry
	 * @return array<string, mixed>
	 */
	public static function fetchCatalog(array $registry): array
	{
		try {
			return PackageRegistryClient::fetchCatalog($registry);
		} catch (RuntimeException $e) {
			if (str_contains($e->getMessage(), 'Package registry URL is invalid')) {
				throw new RuntimeException(str_replace('Package', 'Plugin', $e->getMessage()), 0, $e);
			}

			throw $e;
		}
	}

	/**
	 * @param array<string, mixed> $registry
	 * @return array{
	 *     registry_name: string,
	 *     registry_url: string,
	 *     package: string,
	 *     version: string,
	 *     plugin_id: string,
	 *     dependencies: array<string, string>,
	 *     composer_require: array<string, string>,
	 *     dist: array{
	 *         type: string,
	 *         url: string,
	 *         sha256: string
	 *     }
	 * }
	 */
	public static function resolvePackage(array $registry, string $package, ?string $requested_version = null): array
	{
		try {
			$resolved = PackageRegistryClient::resolvePackage($registry, $package, $requested_version);
		} catch (RuntimeException $e) {
			if (str_contains($e->getMessage(), 'Package registry URL is invalid')) {
				throw new RuntimeException(str_replace('Package', 'Plugin', $e->getMessage()), 0, $e);
			}

			throw $e;
		}

		if ($resolved['type'] !== 'plugin') {
			throw new RuntimeException("Registry package '{$package}' resolved to package type '{$resolved['type']}', expected 'plugin'.");
		}

		return [
			'registry_name' => $resolved['registry_name'],
			'registry_url' => $resolved['registry_url'],
			'package' => $resolved['package'],
			'version' => $resolved['version'],
			'plugin_id' => $resolved['id'],
			'dependencies' => $resolved['dependencies'],
			'composer_require' => $resolved['composer_require'],
			'dist' => $resolved['dist'],
		];
	}

	public static function isSupportedRegistryUrl(string $url): bool
	{
		return PackageRegistryClient::isSupportedRegistryUrl($url);
	}
}
