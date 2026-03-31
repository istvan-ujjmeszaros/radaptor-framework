<?php

class PluginDescriptorDiscovery
{
	/** @var list<array<string, string>> */
	private static array $_warnings = [];

	/**
	 * Discover installed plugin descriptors from the plugins/dev and plugins/registry directories.
	 *
	 * @return array<string, array{
	 *     id: string,
	 *     base_path: string,
	 *     source_type: string,
	 *     descriptor_class: string,
	 *     descriptor_file: string,
	 *     tag_contexts: string[],
	 *     comment_contexts: string[],
	 *     dependencies: array<string, string>
	 * }>
	 */
	public static function discover(): array
	{
		$plugins = [];
		self::$_warnings = [];
		$claimed_directory_ids = [];

		foreach (self::listDescriptorCandidates() as $candidate) {
			if (
				$candidate['source_type'] === 'registry'
				&& isset($claimed_directory_ids[$candidate['directory_id']])
			) {
				self::$_warnings[] = [
					'plugin_id' => $candidate['directory_id'],
					'type' => 'dev_overrides_registry',
					'dev_base_path' => 'plugins/dev/' . $candidate['directory_id'],
					'registry_base_path' => 'plugins/registry/' . $candidate['directory_id'],
				];

				continue;
			}

			$plugin = self::loadPluginDescriptorFromFile($candidate['absolute_path']);
			$class_name = get_class($plugin);
			$plugin_data = self::normalizePlugin(
				$plugin,
				$class_name,
				$candidate['descriptor_file'],
				$candidate['source_type']
			);

			if (isset($plugins[$plugin_data['id']])) {
				$plugins[$plugin_data['id']] = self::resolveDuplicatePlugin($plugins[$plugin_data['id']], $plugin_data);

				continue;
			}

			$plugins[$plugin_data['id']] = $plugin_data;
			$claimed_directory_ids[$candidate['directory_id']] = true;
		}

		ksort($plugins);

		return $plugins;
	}

	/**
	 * Discover only the plugins represented by the current lockfile.
	 *
	 * @param array<string, array<string, mixed>>|null $lock_plugins
	 * @return array<string, array{
	 *     id: string,
	 *     base_path: string,
	 *     descriptor_class: string,
	 *     descriptor_file: string,
	 *     tag_contexts: string[],
	 *     comment_contexts: string[],
	 *     dependencies: array<string, string>
	 * }>
	 */
	public static function discoverInstalled(?array $lock_plugins = null): array
	{
		if ($lock_plugins === null) {
			if (!file_exists(PluginLockfile::getPath())) {
				return self::discover();
			}

			$lock_plugins = PluginLockfile::load()['plugins'];
		}

		if ($lock_plugins === []) {
			return [];
		}

		$discovered = self::discover();
		$plugins_by_base_path = [];

		foreach ($discovered as $plugin) {
			$plugins_by_base_path[$plugin['base_path']] = $plugin;
		}

		$installed = [];

		foreach ($lock_plugins as $plugin_id => $lock_plugin) {
			$plugin = self::findDiscoveredPluginForLockEntry($plugin_id, $lock_plugin, $discovered, $plugins_by_base_path);

			if ($plugin['id'] !== $plugin_id) {
				throw new RuntimeException("Locked plugin '{$plugin_id}' resolved to descriptor id '{$plugin['id']}'.");
			}

			$installed[$plugin_id] = $plugin;
		}

		ksort($installed);

		return $installed;
	}

	/**
	 * @return array{
	 *     id: string,
	 *     base_path: string,
	 *     source_type: string,
	 *     descriptor_class: string,
	 *     descriptor_file: string,
	 *     tag_contexts: string[],
	 *     comment_contexts: string[],
	 *     dependencies: array<string, string>
	 * }
	 */
	private static function normalizePlugin(AbstractPlugin $plugin, string $class_name, string $descriptor_file, string $source_type): array
	{
		$base_path = self::toRelativePath($plugin->getBasePath());

		return [
			'id' => $plugin->getId(),
			'base_path' => $base_path,
			'source_type' => $source_type,
			'descriptor_class' => $class_name,
			'descriptor_file' => $descriptor_file,
			'tag_contexts' => array_values($plugin->getTagContexts()),
			'comment_contexts' => array_values($plugin->getCommentContexts()),
			'dependencies' => PluginPackageMetadataHelper::loadDependenciesFromSourcePath($plugin->getBasePath()),
		];
	}

	/**
	 * @return list<array{
	 *     source_type: string,
	 *     directory_id: string,
	 *     absolute_path: string,
	 *     descriptor_file: string
	 * }>
	 */
	private static function listDescriptorCandidates(): array
	{
		$candidates = [];

		foreach (['dev', 'registry'] as $source_type) {
			$pattern = DEPLOY_ROOT . 'plugins/' . $source_type . '/*/Plugin.*.php';
			$paths = glob($pattern);

			if ($paths === false) {
				continue;
			}

			sort($paths);

			foreach ($paths as $absolute_path) {
				$descriptor_file = self::toRelativePath($absolute_path);

				if (!preg_match('#^plugins/(dev|registry)/([^/]+)/Plugin\..+\.php$#', $descriptor_file, $matches)) {
					continue;
				}

				$candidates[] = [
					'source_type' => $source_type,
					'directory_id' => $matches[2],
					'absolute_path' => $absolute_path,
					'descriptor_file' => $descriptor_file,
				];
			}
		}

		return $candidates;
	}

	private static function loadPluginDescriptorFromFile(string $absolute_path): AbstractPlugin
	{
		$class_name = AutoloaderFailsafe::getClassNameFromFile(new SplFileInfo($absolute_path));

		if ($class_name === null) {
			throw new RuntimeException("Unable to determine plugin descriptor class from {$absolute_path}");
		}

		if (!class_exists($class_name, false)) {
			require_once $absolute_path;
		}

		if (!class_exists($class_name)) {
			throw new RuntimeException("Plugin descriptor class '{$class_name}' was not loaded from {$absolute_path}.");
		}

		if (!is_subclass_of($class_name, AbstractPlugin::class)) {
			throw new RuntimeException("Plugin descriptor file does not declare an AbstractPlugin subclass: {$absolute_path}");
		}

		/** @var AbstractPlugin $plugin */
		$plugin = new $class_name();

		return $plugin;
	}

	/**
	 * @param array<string, mixed> $lock_plugin
	 * @param array<string, array<string, mixed>> $discovered
	 * @param array<string, array<string, mixed>> $plugins_by_base_path
	 * @return array{
	 *     id: string,
	 *     base_path: string,
	 *     source_type: string,
	 *     descriptor_class: string,
	 *     descriptor_file: string,
	 *     tag_contexts: string[],
	 *     comment_contexts: string[],
	 *     dependencies: array<string, string>
	 * }
	 */
	private static function findDiscoveredPluginForLockEntry(
		string $plugin_id,
		array $lock_plugin,
		array $discovered,
		array $plugins_by_base_path
	): array {
		$base_path = self::getLockPluginBasePath($lock_plugin);
		$descriptor_file = isset($lock_plugin['descriptor_file']) && is_string($lock_plugin['descriptor_file'])
			? ltrim($lock_plugin['descriptor_file'], '/')
			: null;
		$candidate = null;

		if ($base_path !== null && isset($plugins_by_base_path[$base_path])) {
			$candidate = $plugins_by_base_path[$base_path];
		} elseif (isset($discovered[$plugin_id])) {
			$candidate = $discovered[$plugin_id];
		}

		if ($candidate === null) {
			throw new RuntimeException("Locked plugin descriptor not found: {$plugin_id}");
		}

		if (
			isset($discovered[$plugin_id])
			&& ($discovered[$plugin_id]['source_type'] ?? null) === 'dev'
			&& ($candidate['source_type'] ?? null) !== 'dev'
		) {
			$candidate = $discovered[$plugin_id];
		}

		if ($base_path !== null && ($candidate['base_path'] ?? null) !== $base_path) {
			if (($candidate['source_type'] ?? null) !== 'dev') {
				throw new RuntimeException("Locked plugin base path mismatch: {$plugin_id}");
			}
		}

		if ($descriptor_file !== null && ($candidate['descriptor_file'] ?? null) !== $descriptor_file) {
			throw new RuntimeException("Locked plugin descriptor file mismatch: {$plugin_id}");
		}

		return $candidate;
	}

	/**
	 * @return list<array<string, string>>
	 */
	public static function getWarnings(): array
	{
		return self::$_warnings;
	}

	/**
	 * @param array{
	 *     id: string,
	 *     base_path: string,
	 *     source_type: string,
	 *     descriptor_class: string,
	 *     descriptor_file: string,
	 *     tag_contexts: string[],
	 *     comment_contexts: string[],
	 *     dependencies: array<string, string>
	 * } $existing
	 * @param array{
	 *     id: string,
	 *     base_path: string,
	 *     source_type: string,
	 *     descriptor_class: string,
	 *     descriptor_file: string,
	 *     tag_contexts: string[],
	 *     comment_contexts: string[],
	 *     dependencies: array<string, string>
	 * } $candidate
	 * @return array{
	 *     id: string,
	 *     base_path: string,
	 *     source_type: string,
	 *     descriptor_class: string,
	 *     descriptor_file: string,
	 *     tag_contexts: string[],
	 *     comment_contexts: string[],
	 *     dependencies: array<string, string>
	 * }
	 */
	private static function resolveDuplicatePlugin(array $existing, array $candidate): array
	{
		$existing_priority = self::getSourcePriority($existing['source_type']);
		$candidate_priority = self::getSourcePriority($candidate['source_type']);

		if ($existing_priority === $candidate_priority) {
			throw new RuntimeException("Duplicate plugin id discovered: {$candidate['id']}");
		}

		$winner = $existing_priority >= $candidate_priority ? $existing : $candidate;
		$shadowed = $winner === $existing ? $candidate : $existing;

		if (
			$winner['source_type'] === 'dev'
			&& $shadowed['source_type'] === 'registry'
		) {
			self::$_warnings[] = [
				'plugin_id' => (string) $winner['id'],
				'type' => 'dev_overrides_registry',
				'dev_base_path' => (string) $winner['base_path'],
				'registry_base_path' => (string) $shadowed['base_path'],
			];
		}

		return $winner;
	}

	private static function getSourcePriority(string $source_type): int
	{
		return match ($source_type) {
			'dev' => 20,
			'registry' => 10,
			default => 0,
		};
	}

	/**
	 * @param array<string, mixed> $lock_plugin
	 */
	private static function getLockPluginBasePath(array $lock_plugin): ?string
	{
		$resolved = $lock_plugin['resolved'] ?? null;
		$source = $lock_plugin['source'] ?? null;

		if (is_array($resolved) && isset($resolved['resolved_path']) && is_string($resolved['resolved_path'])) {
			return self::toRelativePath($resolved['resolved_path']);
		}

		if (is_array($source) && isset($source['resolved_path']) && is_string($source['resolved_path'])) {
			return self::toRelativePath($source['resolved_path']);
		}

		if (is_array($resolved) && isset($resolved['path']) && is_string($resolved['path'])) {
			return ltrim(str_replace('\\', '/', $resolved['path']), '/');
		}

		if (is_array($source) && isset($source['path']) && is_string($source['path'])) {
			return ltrim(str_replace('\\', '/', $source['path']), '/');
		}

		return null;
	}

	private static function toRelativePath(string $path): string
	{
		$normalized = str_replace('\\', '/', $path);
		$root = str_replace('\\', '/', DEPLOY_ROOT);

		if (str_starts_with($normalized, $root)) {
			$normalized = substr($normalized, strlen($root));
		}

		return ltrim($normalized, '/');
	}
}
