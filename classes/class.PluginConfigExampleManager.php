<?php

class PluginConfigExampleManager
{
	/**
	 * @param array<string, array<string, mixed>> $plugins
	 */
	public static function syncInstalledExamples(array $plugins): void
	{
		self::ensureTargetDirectory();

		foreach ($plugins as $plugin_id => $plugin) {
			$plugin_id = PluginIdHelper::normalize($plugin_id, 'Plugin config example');
			$source_path = self::getPluginSourcePath($plugin);

			if ($source_path === null) {
				continue;
			}

			$example_source_path = rtrim($source_path, '/') . '/config/example.php';

			if (!is_file($example_source_path)) {
				continue;
			}

			$contents = file_get_contents($example_source_path);

			if ($contents === false) {
				throw new RuntimeException("Unable to read plugin config example: {$example_source_path}");
			}

			self::removeFileIfExists(self::getLegacyAppExamplePath($plugin_id));
			$target_path = self::getAppExamplePath($plugin_id);
			$result = file_put_contents($target_path, $contents, LOCK_EX);

			if ($result === false) {
				throw new RuntimeException("Unable to write plugin config example: {$target_path}");
			}
		}
	}

	public static function removeExample(string $plugin_id): void
	{
		$plugin_id = PluginIdHelper::normalize($plugin_id, 'Plugin config example');
		self::removeFileIfExists(self::getAppExamplePath($plugin_id));
		self::removeFileIfExists(self::getLegacyAppExamplePath($plugin_id));
	}

	private static function ensureTargetDirectory(): void
	{
		$directory = PackageConfig::getAppConfigDirectory();

		if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
			throw new RuntimeException("Unable to create plugin config directory: {$directory}");
		}
	}

	/**
	 * @param array<string, mixed> $plugin
	 */
	private static function getPluginSourcePath(array $plugin): ?string
	{
		$resolved = is_array($plugin['resolved'] ?? null) ? $plugin['resolved'] : [];
		$source = is_array($plugin['source'] ?? null) ? $plugin['source'] : [];
		$resolved_path = $resolved['resolved_path'] ?? $source['resolved_path'] ?? null;

		if (!is_string($resolved_path) || $resolved_path === '') {
			$path = $resolved['path'] ?? $source['path'] ?? null;

			if (is_string($path) && $path !== '') {
				$resolved_path = str_starts_with($path, '/')
					? $path
					: DEPLOY_ROOT . ltrim($path, '/');
			}
		}

		if (!is_string($resolved_path) || $resolved_path === '' || !is_dir($resolved_path)) {
			return null;
		}

		return rtrim($resolved_path, '/');
	}

	private static function getAppExamplePath(string $plugin_id): string
	{
		$plugin_id = PluginIdHelper::normalize($plugin_id, 'Plugin config example');

		return PackageConfig::getAppExamplePath('plugin', $plugin_id);
	}

	private static function getLegacyAppExamplePath(string $plugin_id): string
	{
		$plugin_id = PluginIdHelper::normalize($plugin_id, 'Plugin config example');

		return DEPLOY_ROOT . 'config/plugins/' . $plugin_id . '.php.example';
	}

	private static function removeFileIfExists(string $path): void
	{
		if (is_file($path) && !unlink($path)) {
			throw new RuntimeException("Unable to remove plugin config example: {$path}");
		}
	}
}
