<?php

class PluginBackupService
{
	/**
	 * @param array<string, mixed>|null $manifest_plugin
	 * @param array<string, mixed>|null $lock_plugin
	 * @return array{
	 *     backup_dir: string,
	 *     filesystem_path: string|null,
	 *     filesystem_backed_up: bool
	 * }
	 */
	public static function backupPlugin(string $plugin_id, ?array $manifest_plugin, ?array $lock_plugin): array
	{
		$plugin_id = PluginIdHelper::normalize($plugin_id, 'Plugin backup');
		$timestamp = date('Ymd_His');
		$backup_dir = DEPLOY_ROOT . 'tmp/plugin-backups/' . $plugin_id . '/' . $timestamp;

		self::ensureDirectory($backup_dir);

		$metadata = [
			'plugin_id' => $plugin_id,
			'created_at' => date('c'),
			'manifest_plugin' => $manifest_plugin,
			'lock_plugin' => $lock_plugin,
		];

		if (file_put_contents(
			$backup_dir . '/metadata.json',
			json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
			LOCK_EX
		) === false) {
			throw new RuntimeException("Unable to write plugin backup metadata for '{$plugin_id}'.");
		}

		$filesystem_path = self::getFilesystemPath($manifest_plugin, $lock_plugin);
		$filesystem_backed_up = false;

		if ($filesystem_path !== null && is_dir($filesystem_path)) {
			self::copyDirectory($filesystem_path, $backup_dir . '/filesystem');
			$filesystem_backed_up = true;
		}

		return [
			'backup_dir' => $backup_dir,
			'filesystem_path' => $filesystem_path,
			'filesystem_backed_up' => $filesystem_backed_up,
		];
	}

	/**
	 * @param array<string, mixed>|null $manifest_plugin
	 * @param array<string, mixed>|null $lock_plugin
	 */
	public static function getFilesystemPath(?array $manifest_plugin, ?array $lock_plugin): ?string
	{
		$resolved = is_array($lock_plugin['resolved'] ?? null) ? $lock_plugin['resolved'] : null;
		$source = is_array($manifest_plugin['source'] ?? null) ? $manifest_plugin['source'] : null;

		if (isset($resolved['resolved_path']) && is_string($resolved['resolved_path'])) {
			return $resolved['resolved_path'];
		}

		if (isset($resolved['path']) && is_string($resolved['path'])) {
			$path = $resolved['path'];

			return str_starts_with($path, '/')
				? rtrim($path, '/')
				: rtrim(DEPLOY_ROOT . ltrim($path, '/'), '/');
		}

		if (isset($source['resolved_path']) && is_string($source['resolved_path'])) {
			return $source['resolved_path'];
		}

		if (isset($source['path']) && is_string($source['path'])) {
			$path = $source['path'];

			return str_starts_with($path, '/')
				? rtrim($path, '/')
				: rtrim(DEPLOY_ROOT . ltrim($path, '/'), '/');
		}

		return null;
	}

	private static function ensureDirectory(string $directory): void
	{
		if (is_dir($directory)) {
			return;
		}

		if (!mkdir($directory, 0o755, true) && !is_dir($directory)) {
			throw new RuntimeException("Unable to create directory: {$directory}");
		}
	}

	private static function copyDirectory(string $source, string $destination): void
	{
		self::ensureDirectory($destination);
		$items = scandir($source);

		if ($items === false) {
			throw new RuntimeException("Unable to read directory: {$source}");
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$source_path = $source . '/' . $item;
			$destination_path = $destination . '/' . $item;

			if (is_dir($source_path) && !is_link($source_path)) {
				self::copyDirectory($source_path, $destination_path);

				continue;
			}

			if (!copy($source_path, $destination_path)) {
				throw new RuntimeException("Unable to copy plugin backup file to {$destination_path}");
			}
		}
	}
}
