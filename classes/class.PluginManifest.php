<?php

class PluginManifest
{
	public static function getPath(): string
	{
		return DEPLOY_ROOT . 'plugins.json';
	}

	/**
	 * @param array{
	 *     manifest_version?: int,
	 *     plugins?: array<string, array<string, mixed>>
	 * } $manifest
	 * @return array{
	 *     manifest_version: int,
	 *     dev?: array<string, array<string, mixed>>,
	 *     registry?: array<string, array<string, mixed>>
	 * }
	 */
	public static function exportDocument(array $manifest): array
	{
		$dev_plugins = [];
		$registry_plugins = [];

		foreach (($manifest['plugins'] ?? []) as $plugin_id => $plugin) {
			$plugin_id = PluginIdHelper::normalize($plugin_id, 'Manifest plugin');
			$source = is_array($plugin['source'] ?? null) ? $plugin['source'] : [];
			$source_type = self::requireSourceType($source['type'] ?? null, $plugin_id);
			$exported_plugin = self::exportPlugin($plugin, $plugin_id);

			if ($source_type === 'registry') {
				$registry_plugins[$plugin_id] = $exported_plugin;

				continue;
			}

			$dev_plugins[$plugin_id] = $exported_plugin;
		}

		ksort($dev_plugins);
		ksort($registry_plugins);
		$document = [
			'manifest_version' => max(1, (int) ($manifest['manifest_version'] ?? 1)),
		];

		if ($dev_plugins !== []) {
			$document['dev'] = $dev_plugins;
		}

		if ($registry_plugins !== []) {
			$document['registry'] = $registry_plugins;
		}

		return $document;
	}

	/**
	 * @param array<string, mixed> $plugin
	 * @return array<string, mixed>
	 */
	public static function exportPlugin(array $plugin, string $plugin_id): array
	{
		$plugin_id = PluginIdHelper::normalize($plugin_id, 'Manifest plugin');
		$export = [];
		$source = is_array($plugin['source'] ?? null) ? $plugin['source'] : [];
		$source_type = self::requireSourceType($source['type'] ?? null, $plugin_id);

		if (array_key_exists('package', $plugin)) {
			$export['package'] = $plugin['package'];
		}

		if ($source_type === 'dev') {
			$path = $source['path'] ?? null;

			if (
				is_string($path)
				&& $path !== ''
				&& $path !== self::getDefaultDevPath($plugin_id)
			) {
				throw new RuntimeException("Custom dev plugin paths are not supported for '{$plugin_id}'.");
			}
		} else {
			$registry_url = $source['resolved_registry_url'] ?? $source['registry'] ?? null;

			if (!is_string($registry_url) || $registry_url === '') {
				throw new RuntimeException("Registry plugin '{$plugin_id}' is missing a registry URL.");
			}

			$export['registry'] = $registry_url;

			if (isset($source['version']) && is_string($source['version']) && $source['version'] !== '') {
				$export['version'] = $source['version'];
			}
		}

		$extra = [];

		foreach ($plugin as $key => $value) {
			if (in_array($key, ['plugin_id', 'package', 'source'], true)) {
				continue;
			}

			if ($key === 'dependencies') {
				$extra[$key] = PluginDependencyHelper::normalizeDependencies(
					$value,
					"Manifest plugin '{$plugin_id}'"
				);

				continue;
			}

			$extra[$key] = $value;
		}

		ksort($extra);

		foreach ($extra as $key => $value) {
			$export[$key] = $value;
		}

		return $export;
	}

	/**
	 * @param array{
	 *     manifest_version?: int,
	 *     plugins?: array<string, array<string, mixed>>
	 * } $manifest
	 */
	public static function write(array $manifest, ?string $path = null): void
	{
		$path ??= self::getPath();
		$document = self::exportDocument($manifest);
		$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$result = file_put_contents($path, $json . "\n", LOCK_EX);

		if ($result === false) {
			throw new RuntimeException("Unable to write plugin manifest: {$path}");
		}
	}

	/**
	 * @return array{
	 *     manifest_version: int,
	 *     plugins: array<string, array<string, mixed>>,
	 *     path: string,
	 *     base_dir: string
	 * }
	 */
	public static function load(): array
	{
		return self::loadFromPath(self::getPath());
	}

	/**
	 * @return array{
	 *     manifest_version: int,
	 *     plugins: array<string, array<string, mixed>>,
	 *     path: string,
	 *     base_dir: string
	 * }
	 */
	public static function loadFromPath(string $path): array
	{
		if (!file_exists($path)) {
			throw new RuntimeException("Plugin manifest not found: {$path}");
		}

		$base_dir = dirname($path);
		$data = self::decodeJsonFile($path);

		if (array_key_exists('plugins', $data) || array_key_exists('registries', $data)) {
			throw new RuntimeException("Legacy plugin manifest format is no longer supported: {$path}");
		}

		$plugins = [];

		foreach ([
			'dev' => 'dev',
			'registry' => 'registry',
		] as $section_key => $source_type) {
			foreach (($data[$section_key] ?? []) as $plugin_id => $plugin) {
				if (!is_array($plugin)) {
					continue;
				}

				$normalized_plugin = self::normalizeSectionPlugin(
					$plugin_id,
					$plugin,
					$source_type,
					$base_dir
				);
				$plugins[$normalized_plugin['plugin_id']] = $normalized_plugin;
			}
		}

		ksort($plugins);

		return [
			'manifest_version' => max(1, (int) ($data['manifest_version'] ?? 1)),
			'plugins' => $plugins,
			'path' => $path,
			'base_dir' => $base_dir,
		];
	}

	/**
	 * @param array<string, mixed> $plugin
	 * @return array<string, mixed>
	 */
	private static function normalizeSectionPlugin(
		string $plugin_id,
		array $plugin,
		string $source_type,
		string $base_dir
	): array {
		$plugin_id = PluginIdHelper::normalize($plugin_id, 'Manifest plugin');
		$normalized = [
			'plugin_id' => $plugin_id,
		];

		if (array_key_exists('package', $plugin)) {
			$normalized['package'] = $plugin['package'];
		}

		foreach ($plugin as $key => $value) {
			if (in_array($key, ['package', 'path', 'registry', 'version'], true)) {
				continue;
			}

			if ($key === 'dependencies') {
				$normalized['dependencies'] = PluginDependencyHelper::normalizeDependencies(
					$value,
					"Manifest plugin '{$plugin_id}'"
				);

				continue;
			}

			$normalized[$key] = $value;
		}

		$source = [
			'type' => $source_type,
		];

		if (
			$source_type === 'dev'
			&& isset($plugin['path'])
			&& is_string($plugin['path'])
			&& $plugin['path'] !== ''
		) {
			if ($plugin['path'] !== self::getDefaultDevPath($plugin_id)) {
				throw new RuntimeException("Custom dev plugin paths are not supported for '{$plugin_id}'.");
			}

			$source['path'] = $plugin['path'];
		}

		if ($source_type === 'registry') {
			if (!isset($plugin['registry']) || !is_string($plugin['registry']) || trim($plugin['registry']) === '') {
				throw new RuntimeException("Registry plugin '{$plugin_id}' is missing a registry URL.");
			}

			$source['registry'] = trim($plugin['registry']);

			if (isset($plugin['version']) && is_string($plugin['version']) && $plugin['version'] !== '') {
				$source['version'] = $plugin['version'];
			}
		}

		$normalized['source'] = self::normalizeSource($source, $base_dir, $plugin_id);

		return $normalized;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalizeSource(
		array $source,
		string $base_dir,
		string $plugin_id = ''
	): array {
		$normalized = $source;
		$normalized['type'] = self::requireSourceType($source['type'] ?? null, $plugin_id);
		$normalized_type = $normalized['type'];

		if (
			$plugin_id !== ''
			&& $normalized_type === 'dev'
			&& (!isset($normalized['path']) || !is_string($normalized['path']) || trim($normalized['path']) === '')
		) {
			$normalized['path'] = self::getDefaultDevPath($plugin_id);
		}

		if ($normalized_type === 'dev') {
			if (isset($source['path']) && is_string($source['path'])) {
				$normalized['resolved_path'] = self::resolvePath($base_dir, $source['path']);
			} elseif (isset($normalized['path']) && is_string($normalized['path'])) {
				$normalized['resolved_path'] = self::resolvePath($base_dir, $normalized['path']);
			}

			return $normalized;
		}

		$registry_url = isset($normalized['registry']) && is_string($normalized['registry'])
			? trim($normalized['registry'])
			: '';

		if ($registry_url === '' || !self::isUrlLocation($registry_url)) {
			throw new RuntimeException("Registry plugin '{$plugin_id}' has an invalid registry URL.");
		}

		$normalized['resolved_registry_url'] = $registry_url;

		return $normalized;
	}

	private static function getDefaultDevPath(string $plugin_id): string
	{
		return 'plugins/dev/' . $plugin_id;
	}

	private static function requireSourceType(mixed $source_type, string $plugin_id = ''): string
	{
		$source_type = trim((string) $source_type);

		if (in_array($source_type, ['dev', 'registry'], true)) {
			return $source_type;
		}

		$plugin_label = $plugin_id !== '' ? " '{$plugin_id}'" : '';

		throw new RuntimeException("Plugin{$plugin_label} uses unsupported source type '{$source_type}'.");
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decodeJsonFile(string $path): array
	{
		$json = file_get_contents($path);

		if ($json === false) {
			throw new RuntimeException("Unable to read JSON file: {$path}");
		}

		try {
			$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException("Invalid JSON in {$path}: " . $e->getMessage(), 0, $e);
		}

		if (!is_array($data)) {
			throw new RuntimeException("JSON root must be an object: {$path}");
		}

		return $data;
	}

	private static function resolvePath(string $base_dir, string $path): string
	{
		if ($path === '') {
			return $base_dir;
		}

		if (str_starts_with($path, '/')) {
			return self::normalizePath($path);
		}

		return self::normalizePath($base_dir . '/' . $path);
	}

	private static function isUrlLocation(string $location): bool
	{
		return preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1;
	}

	private static function normalizePath(string $path): string
	{
		$path = str_replace('\\', '/', $path);
		$real = realpath($path);

		if ($real !== false) {
			return rtrim(str_replace('\\', '/', $real), '/');
		}

		$prefix = '';

		if (str_starts_with($path, '/')) {
			$prefix = '/';
		}

		$segments = [];

		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			if ($segment === '..') {
				array_pop($segments);

				continue;
			}

			$segments[] = $segment;
		}

		return $prefix . implode('/', $segments);
	}
}
