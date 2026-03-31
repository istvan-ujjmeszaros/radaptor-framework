<?php

class PluginLockfile
{
	public static function getPath(): string
	{
		return DEPLOY_ROOT . 'plugins.lock.json';
	}

	/**
	 * @param array{
	 *     lockfile_version?: int,
	 *     plugins?: array<string, array<string, mixed>>
	 * } $lockfile
	 * @return array{
	 *     lockfile_version: int,
	 *     plugins: array<string, array<string, mixed>>
	 * }
	 */
	public static function exportDocument(array $lockfile): array
	{
		$plugins = [];

		foreach (($lockfile['plugins'] ?? []) as $plugin_id => $plugin) {
			$plugin_id = PluginIdHelper::normalize($plugin_id, 'Locked plugin');
			$plugins[$plugin_id] = self::exportPlugin($plugin, $plugin_id);
		}

		ksort($plugins);

		return [
			'lockfile_version' => max(1, (int) ($lockfile['lockfile_version'] ?? 1)),
			'plugins' => $plugins,
		];
	}

	/**
	 * @param array<string, mixed> $plugin
	 * @return array<string, mixed>
	 */
	public static function exportPlugin(array $plugin, string $plugin_id): array
	{
		$plugin_id = PluginIdHelper::normalize($plugin['plugin_id'] ?? $plugin_id, 'Locked plugin');
		$export = [];
		$known_keys = [
			'package',
			'plugin_id',
			'source',
			'resolved',
			'descriptor_class',
			'descriptor_file',
			'composer',
		];

		if (array_key_exists('package', $plugin)) {
			$export['package'] = $plugin['package'];
		}

		$export['plugin_id'] = $plugin_id;

		if (isset($plugin['source']) && is_array($plugin['source'])) {
			$export['source'] = self::stripTransientSourceFields($plugin['source']);
		}

		if (isset($plugin['resolved']) && is_array($plugin['resolved'])) {
			$export['resolved'] = self::stripTransientSourceFields($plugin['resolved']);
		}

		if (array_key_exists('descriptor_class', $plugin)) {
			$export['descriptor_class'] = $plugin['descriptor_class'];
		}

		if (array_key_exists('descriptor_file', $plugin)) {
			$export['descriptor_file'] = $plugin['descriptor_file'];
		}

		if (array_key_exists('dependencies', $plugin)) {
			$export['dependencies'] = PluginDependencyHelper::normalizeDependencies(
				$plugin['dependencies'],
				"Locked plugin '{$plugin_id}'"
			);
		}

		if (isset($plugin['composer']) && is_array($plugin['composer'])) {
			$composer = self::normalizeComposer($plugin['composer'], $plugin_id);

			if ($composer['require'] !== []) {
				$export['composer'] = $composer;
			}
		}

		$extra = [];

		foreach ($plugin as $key => $value) {
			if ($key === 'resolved_path' || $key === 'dependencies' || in_array($key, $known_keys, true)) {
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
	 *     lockfile_version?: int,
	 *     plugins?: array<string, array<string, mixed>>
	 * } $lockfile
	 */
	public static function write(array $lockfile, ?string $path = null): void
	{
		$path ??= self::getPath();
		$document = self::exportDocument($lockfile);
		$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$result = file_put_contents($path, $json . "\n", LOCK_EX);

		if ($result === false) {
			throw new RuntimeException("Unable to write plugin lockfile: {$path}");
		}
	}

	/**
	 * @return array{
	 *     lockfile_version: int,
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
	 *     lockfile_version: int,
	 *     plugins: array<string, array<string, mixed>>,
	 *     path: string,
	 *     base_dir: string
	 * }
	 */
	public static function loadFromPath(string $path): array
	{
		if (!file_exists($path)) {
			throw new RuntimeException("Plugin lockfile not found: {$path}");
		}

		$base_dir = dirname($path);
		$data = self::decodeJsonFile($path);

		$plugins = [];

		foreach (($data['plugins'] ?? []) as $plugin_id => $plugin) {
			if (!is_array($plugin)) {
				continue;
			}

			$normalized = $plugin;
			$normalized['plugin_id'] = PluginIdHelper::normalize(
				$plugin['plugin_id'] ?? $plugin_id,
				'Locked plugin'
			);
			$plugin_id = $normalized['plugin_id'];

			if (isset($plugin['source']) && is_array($plugin['source'])) {
				$normalized['source'] = self::normalizeSource($plugin['source'], $base_dir, $plugin_id);
			}

			if (isset($plugin['resolved']) && is_array($plugin['resolved'])) {
				$normalized['resolved'] = self::normalizeSource($plugin['resolved'], $base_dir, $plugin_id);
			}

			if (array_key_exists('dependencies', $plugin)) {
				$normalized['dependencies'] = PluginDependencyHelper::normalizeDependencies(
					$plugin['dependencies'],
					"Locked plugin '{$plugin_id}'"
				);
			}

			if (isset($plugin['composer']) && is_array($plugin['composer'])) {
				$normalized['composer'] = self::normalizeComposer($plugin['composer'], $plugin_id);
			}

			$plugins[$normalized['plugin_id']] = $normalized;
		}

		ksort($plugins);

		return [
			'lockfile_version' => (int) ($data['lockfile_version'] ?? 0),
			'plugins' => $plugins,
			'path' => $path,
			'base_dir' => $base_dir,
		];
	}

	/**
	 * @param array<string, mixed> $composer
	 * @return array{
	 *     require: array<string, string>
	 * }
	 */
	private static function normalizeComposer(array $composer, string $plugin_id): array
	{
		return [
			'require' => PluginDependencyHelper::normalizeDependencies(
				$composer['require'] ?? [],
				"Locked plugin '{$plugin_id}' composer.require"
			),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalizeSource(array $source, string $base_dir, string $plugin_id = ''): array
	{
		$normalized = $source;
		$normalized['type'] = self::requireSourceType($source['type'] ?? null, $plugin_id);
		$normalized_type = $normalized['type'];

		if (
			$plugin_id !== ''
			&& $normalized_type === 'dev'
			&& (!isset($normalized['path']) || !is_string($normalized['path']) || trim($normalized['path']) === '')
		) {
			$normalized['path'] = 'plugins/dev/' . $plugin_id;
		}

		if (isset($source['path']) && is_string($source['path'])) {
			$normalized['resolved_path'] = self::resolvePath($base_dir, $source['path']);
		} elseif (isset($normalized['path']) && is_string($normalized['path'])) {
			$normalized['resolved_path'] = self::resolvePath($base_dir, $normalized['path']);
		}

		return $normalized;
	}

	private static function requireSourceType(mixed $source_type, string $plugin_id = ''): string
	{
		$source_type = trim((string) $source_type);

		if (in_array($source_type, ['dev', 'registry'], true)) {
			return $source_type;
		}

		$plugin_label = $plugin_id !== '' ? " '{$plugin_id}'" : '';

		throw new RuntimeException("Plugin{$plugin_label} uses unsupported lock source type '{$source_type}'.");
	}

	/**
	 * @param array<string, mixed> $source
	 * @return array<string, mixed>
	 */
	private static function stripTransientSourceFields(array $source): array
	{
		unset($source['resolved_path']);
		ksort($source);

		return $source;
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
