<?php

class PackageBridgeHelper
{
	/**
	 * @param array<string, array<string, mixed>> $packages
	 * @return array{
	 *     manifest_version: int,
	 *     plugins: array<string, array<string, mixed>>
	 * }
	 */
	public static function buildPluginManifest(array $packages): array
	{
		$plugins = [];

		foreach ($packages as $package) {
			if (($package['type'] ?? null) !== 'plugin') {
				continue;
			}

			$plugin_id = PackageTypeHelper::normalizeId($package['id'] ?? null, 'Plugin bridge package');
			$source = is_array($package['source'] ?? null) ? $package['source'] : [];
			$resolved = is_array($package['resolved'] ?? null) ? $package['resolved'] : [];
			$source_type = trim((string) ($source['type'] ?? $resolved['type'] ?? ''));

			if (!in_array($source_type, ['dev', 'registry'], true)) {
				throw new RuntimeException("Plugin bridge package '{$plugin_id}' uses unsupported source type '{$source_type}'.");
			}

			$plugin = [
				'package' => trim((string) ($package['package'] ?? '')),
				'source' => [
					'type' => $source_type,
				],
			];

			if ($plugin['package'] === '') {
				throw new RuntimeException("Plugin bridge package '{$plugin_id}' is missing package.");
			}

			if ($source_type === 'dev') {
				$plugin['source']['path'] = trim((string) ($source['path'] ?? PackageTypeHelper::getDefaultPath('plugin', 'dev', $plugin_id)));
			} else {
				$registry_url = trim((string) ($resolved['registry_url'] ?? $source['resolved_registry_url'] ?? ''));

				if ($registry_url === '') {
					throw new RuntimeException("Plugin bridge package '{$plugin_id}' is missing a registry URL.");
				}

				$plugin['source']['registry'] = $registry_url;
				$plugin['source']['resolved_registry_url'] = $registry_url;

				if (isset($resolved['version']) && trim((string) $resolved['version']) !== '') {
					$plugin['source']['version'] = trim((string) $resolved['version']);
				}
			}

			if (isset($package['dependencies'])) {
				$plugin['dependencies'] = PackageDependencyHelper::normalizeDependencies(
					$package['dependencies'],
					"Plugin bridge package '{$plugin_id}'"
				);
			}

			if (($package['auto_installed'] ?? false) === true) {
				$plugin['auto_installed'] = true;
			}

			if (isset($package['required_by']) && is_array($package['required_by']) && $package['required_by'] !== []) {
				$plugin['required_by'] = self::normalizeRequiredBy($package['required_by']);
			}

			$plugins[$plugin_id] = $plugin;
		}

		ksort($plugins);

		return [
			'manifest_version' => 1,
			'plugins' => $plugins,
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $packages
	 */
	public static function writePluginManifest(string $path, array $packages): void
	{
		PluginManifest::write(self::buildPluginManifest($packages), $path);
	}

	/**
	 * @param list<mixed> $required_by
	 * @return list<string>
	 */
	private static function normalizeRequiredBy(array $required_by): array
	{
		$normalized = [];

		foreach ($required_by as $value) {
			$value = trim((string) $value);

			if ($value === '') {
				continue;
			}

			if (str_starts_with($value, 'plugin:')) {
				$value = substr($value, strlen('plugin:'));
			}

			$normalized[] = PackageTypeHelper::normalizeId($value, 'Plugin bridge required_by');
		}

		$normalized = array_values(array_unique($normalized));
		sort($normalized);

		return $normalized;
	}
}
