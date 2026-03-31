<?php

class PluginLifecycleManager
{
	/**
	 * @param array<string, structSQLTable> $db_data
	 */
	public static function runAfterBuildDb(string $dsn, array $db_data): void
	{
		foreach (self::getInstalledPlugins() as $plugin) {
			$plugin->afterBuildDb($dsn, $db_data);
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $lock_plugins
	 */
	public static function runAfterSync(array $lock_plugins): void
	{
		foreach (self::getInstalledPlugins($lock_plugins) as $plugin) {
			$plugin->afterSync();
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $lock_plugins
	 */
	public static function runBeforeUninstall(string $plugin_id, array $lock_plugins): void
	{
		$plugin = self::getInstalledPlugin($plugin_id, $lock_plugins);

		if ($plugin === null) {
			return;
		}

		$plugin->beforeUninstall();
	}

	/**
	 * @param array<string, array<string, mixed>>|null $lock_plugins
	 * @return array<string, AbstractPlugin>
	 */
	public static function getInstalledPlugins(?array $lock_plugins = null): array
	{
		if ($lock_plugins === null && file_exists(PluginLockfile::getPath())) {
			$lock_plugins = PluginLockfile::load()['plugins'];
		}

		$discovered_plugins = PluginDescriptorDiscovery::discoverInstalled($lock_plugins);
		$ordered_plugin_ids = $lock_plugins !== null && $lock_plugins !== []
			? PluginDependencyHelper::sortPluginIdsByDependencies($lock_plugins)
			: array_keys($discovered_plugins);
		$instances = [];

		foreach ($ordered_plugin_ids as $plugin_id) {
			$descriptor = $discovered_plugins[$plugin_id] ?? null;

			if ($descriptor === null) {
				continue;
			}

			$instances[$plugin_id] = self::instantiatePlugin($descriptor);
		}

		return $instances;
	}

	/**
	 * @param array<string, array<string, mixed>>|null $lock_plugins
	 */
	public static function getInstalledPlugin(string $plugin_id, ?array $lock_plugins = null): ?AbstractPlugin
	{
		return self::getInstalledPlugins($lock_plugins)[$plugin_id] ?? null;
	}

	/**
	 * @param array<string, mixed> $descriptor
	 */
	private static function instantiatePlugin(array $descriptor): AbstractPlugin
	{
		$class_name = $descriptor['descriptor_class'] ?? null;

		if (!is_string($class_name) || $class_name === '') {
			throw new RuntimeException('Plugin descriptor is missing descriptor_class.');
		}

		if (!class_exists($class_name)) {
			throw new RuntimeException("Plugin descriptor class is not available: {$class_name}");
		}

		$plugin = new $class_name();

		if (!$plugin instanceof AbstractPlugin) {
			throw new RuntimeException("Plugin descriptor class '{$class_name}' is not an AbstractPlugin.");
		}

		return $plugin;
	}
}
