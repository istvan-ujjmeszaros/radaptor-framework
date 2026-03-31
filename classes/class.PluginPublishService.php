<?php

class PluginPublishService
{
	/**
	 * @return array{
	 *     plugin_id: string,
	 *     package: string,
	 *     version: string,
	 *     dependencies: array<string, string>,
	 *     composer_require: array<string, string>,
	 *     source_path: string,
	 *     registry_root: string,
	 *     registry_rebuilt: bool,
	 *     packaged_files: int,
	 *     dist_url: string,
	 *     dist_path: string,
	 *     sha256: string,
	 *     build: array<string, mixed>|null
	 * }
	 */
	public static function publish(
		string $plugin_id,
		?string $registry_root = null,
		?string $manifest_path = null
	): array {
		$manifest = PluginManifest::loadFromPath($manifest_path ?? PluginManifest::getPath());
		$plugin = $manifest['plugins'][$plugin_id] ?? null;

		if (!is_array($plugin)) {
			throw new RuntimeException("Plugin '{$plugin_id}' was not found in the manifest.");
		}

		$source = is_array($plugin['source'] ?? null) ? $plugin['source'] : [];

		if (($source['type'] ?? null) !== 'dev') {
			throw new RuntimeException("Only dev plugins can be published. '{$plugin_id}' uses source type '" . ($source['type'] ?? 'unknown') . "'.");
		}

		$source_path = $source['resolved_path'] ?? null;

		if (!is_string($source_path) || $source_path === '' || !is_dir($source_path)) {
			throw new RuntimeException("Dev plugin '{$plugin_id}' is missing a usable source path.");
		}

		$repository = PluginDevRepositoryInspector::inspect($source_path);

		if ($repository['git_available'] !== true || $repository['is_repository'] !== true) {
			throw new RuntimeException("Dev plugin '{$plugin_id}' must live in a Git repository before publishing.");
		}

		$tracked_files = PluginDevRepositoryInspector::listTrackedFiles($source_path);
		$metadata_relative_path = '.registry-package.json';

		if (is_file($source_path . '/' . $metadata_relative_path) && !in_array($metadata_relative_path, $tracked_files, true)) {
			$tracked_files[] = $metadata_relative_path;
			sort($tracked_files);
		}

		if ($tracked_files === []) {
			throw new RuntimeException("Dev plugin repository does not contain tracked files: {$source_path}");
		}

		$metadata = self::loadPackageMetadata($source_path);
		self::assertMetadataMatchesDescriptor($plugin_id, $source_path, $metadata);

		if ($metadata['plugin_id'] !== $plugin_id) {
			throw new RuntimeException("Plugin package metadata plugin_id '{$metadata['plugin_id']}' does not match manifest plugin '{$plugin_id}'.");
		}

		$manifest_package = $plugin['package'] ?? null;

		if (is_string($manifest_package) && $manifest_package !== '' && $metadata['package'] !== $manifest_package) {
			throw new RuntimeException("Plugin package metadata package '{$metadata['package']}' does not match manifest package '{$manifest_package}'.");
		}

		$registry_root = self::resolveRegistryRoot($registry_root);
		$build = LocalPluginRegistryBuilder::publishPackage($registry_root, $source_path, $metadata, $tracked_files);

		return [
			'plugin_id' => $plugin_id,
			'package' => $metadata['package'],
			'version' => $metadata['version'],
			'dependencies' => $metadata['dependencies'],
			'composer_require' => $metadata['composer']['require'],
			'source_path' => self::normalizePath($source_path),
			'registry_root' => $registry_root,
			'registry_rebuilt' => true,
			'packaged_files' => $build['packaged_files'],
			'dist_url' => $build['dist_url'],
			'dist_path' => $build['dist_path'],
			'sha256' => $build['sha256'],
			'build' => $build,
		];
	}

	private static function resolveRegistryRoot(?string $registry_root): string
	{
		if (is_string($registry_root) && trim($registry_root) !== '') {
			$resolved = self::normalizePath(trim($registry_root));

			if (!is_dir($resolved)) {
				throw new RuntimeException("Plugin registry root does not exist: {$resolved}");
			}

			return $resolved;
		}

		$candidates = [];
		$env_root = getenv('RADAPTOR_PLUGIN_REGISTRY_ROOT');

		if (is_string($env_root) && trim($env_root) !== '') {
			$candidates[] = trim($env_root);
		}

		$candidates[] = DEPLOY_ROOT . '../radaptor_plugin_registry';
		$candidates[] = '/radaptor_plugin_registry';

		foreach ($candidates as $candidate) {
			$resolved = self::normalizePath($candidate);

			if (is_dir($resolved)) {
				return $resolved;
			}
		}

		throw new RuntimeException('Unable to locate the local plugin registry root. Pass --registry-root or set RADAPTOR_PLUGIN_REGISTRY_ROOT.');
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
	 *     dist_exclude: list<string>
	 * }
	 */
	private static function loadPackageMetadata(string $source_path): array
	{
		return PluginPackageMetadataHelper::loadFromSourcePath($source_path);
	}

	/**
	 * @param array{
	 *     package: string,
	 *     plugin_id: string,
	 *     version: string,
	 *     dependencies: array<string, string>,
	 *     composer: array{
	 *         require: array<string, string>
	 *     },
	 *     dist_exclude: list<string>
	 * } $metadata
	 */
	private static function assertMetadataMatchesDescriptor(string $plugin_id, string $source_path, array $metadata): void
	{
		$plugin = self::loadDescriptorFromSourcePath($source_path);

		if ($plugin->getId() !== $plugin_id) {
			throw new RuntimeException("Dev plugin '{$plugin_id}' descriptor is not discoverable for publishing.");
		}
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

	private static function loadDescriptorFromSourcePath(string $source_path): AbstractPlugin
	{
		$descriptor_files = glob(rtrim($source_path, '/') . '/Plugin.*.php');

		if ($descriptor_files === false || $descriptor_files === []) {
			throw new RuntimeException("Dev plugin source does not contain a descriptor file: {$source_path}");
		}

		sort($descriptor_files);
		$class_name = self::extractClassNameFromFile($descriptor_files[0]);
		require_once $descriptor_files[0];

		if (!class_exists($class_name)) {
			throw new RuntimeException("Dev plugin descriptor class '{$class_name}' was not loaded from {$descriptor_files[0]}.");
		}

		if (!is_subclass_of($class_name, AbstractPlugin::class)) {
			throw new RuntimeException("Dev plugin descriptor file does not declare an AbstractPlugin subclass: {$descriptor_files[0]}");
		}

		/** @var AbstractPlugin $plugin */
		$plugin = new $class_name();

		return $plugin;
	}

	private static function extractClassNameFromFile(string $path): string
	{
		$contents = file_get_contents($path);

		if ($contents === false) {
			throw new RuntimeException("Unable to read dev plugin descriptor file: {$path}");
		}

		$tokens = token_get_all($contents);
		$token_count = count($tokens);

		for ($index = 0; $index < $token_count; $index++) {
			$token = $tokens[$index];

			if (!is_array($token) || $token[0] !== T_CLASS) {
				continue;
			}

			for ($look_ahead = $index + 1; $look_ahead < $token_count; $look_ahead++) {
				$next = $tokens[$look_ahead];

				if (is_array($next) && $next[0] === T_STRING) {
					return $next[1];
				}
			}
		}

		throw new RuntimeException("Unable to determine dev plugin descriptor class name from {$path}");
	}
}
