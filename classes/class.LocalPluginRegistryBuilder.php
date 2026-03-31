<?php

class LocalPluginRegistryBuilder
{
	/**
	 * @param array{
	 *     package: string,
	 *     plugin_id: string,
	 *     version: string,
	 *     dependencies: array<string, string>,
	 *     composer: array{
	 *         require: array<string, string>
	 *     },
	 *     assets?: array{
	 *         public: list<array{source: string, target: string}>
	 *     },
	 *     dist_exclude: list<string>
	 * } $metadata
	 * @param list<string> $tracked_files
	 * @return array{
	 *     registry_root: string,
	 *     registry_path: string,
	 *     package: string,
	 *     plugin_id: string,
	 *     version: string,
	 *     dependencies: array<string, string>,
	 *     composer_require: array<string, string>,
	 *     dist_url: string,
	 *     dist_path: string,
	 *     sha256: string,
	 *     packaged_files: int
	 * }
	 */
	public static function publishPackage(string $registry_root, string $package_root, array $metadata, array $tracked_files): array
	{
		$build = LocalPackageRegistryBuilder::publishPackage($registry_root, $package_root, [
			'package' => $metadata['package'],
			'type' => 'plugin',
			'id' => $metadata['plugin_id'],
			'version' => $metadata['version'],
			'dependencies' => $metadata['dependencies'],
			'composer' => $metadata['composer'],
			'assets' => [
				'public' => $metadata['assets']['public'] ?? [],
			],
			'dist_exclude' => $metadata['dist_exclude'],
		], $tracked_files);

		return [
			'registry_root' => $build['registry_root'],
			'registry_path' => $build['registry_path'],
			'package' => $build['package'],
			'plugin_id' => $build['id'],
			'version' => $build['version'],
			'dependencies' => $build['dependencies'],
			'composer_require' => $build['composer_require'],
			'dist_url' => $build['dist_url'],
			'dist_path' => $build['dist_path'],
			'sha256' => $build['sha256'],
			'packaged_files' => $build['packaged_files'],
		];
	}
}
