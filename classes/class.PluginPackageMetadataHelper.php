<?php

class PluginPackageMetadataHelper
{
	/**
	 * @return array<string, string>
	 */
	public static function loadDependenciesFromSourcePath(string $source_path): array
	{
		$metadata_path = rtrim($source_path, '/') . '/.registry-package.json';

		if (!is_file($metadata_path)) {
			return [];
		}

		return self::loadFromSourcePath($source_path)['dependencies'];
	}

	/**
	 * @return array<string, string>
	 */
	public static function loadComposerRequireFromSourcePath(string $source_path): array
	{
		$metadata_path = rtrim($source_path, '/') . '/.registry-package.json';

		if (!is_file($metadata_path)) {
			return [];
		}

		return self::loadFromSourcePath($source_path)['composer']['require'];
	}

	/**
	 * @return array{
	 *     package: string,
	 *     plugin_id: string,
	 *     version: string,
	 *     dependencies: array<string, string>,
	 *     composer: array{
	 *         require: array<string, string>
	 *     },
	 *     assets: array{
	 *         public: list<array{source: string, target: string}>
	 *     },
	 *     dist_exclude: list<string>
	 * }
	 */
	public static function loadFromSourcePath(string $source_path): array
	{
		$metadata = PackageMetadataHelper::loadFromSourcePath($source_path);

		if ($metadata['type'] !== 'plugin') {
			throw new RuntimeException("Package metadata '{$metadata['package']}' must use type 'plugin' for plugin workflows.");
		}

		return [
			'package' => $metadata['package'],
			'plugin_id' => $metadata['id'],
			'version' => $metadata['version'],
			'dependencies' => $metadata['dependencies'],
			'composer' => $metadata['composer'],
			'assets' => $metadata['assets'],
			'dist_exclude' => $metadata['dist_exclude'],
		];
	}
}
