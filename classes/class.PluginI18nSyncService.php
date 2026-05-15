<?php

class PluginI18nSyncService
{
	/**
	 * @return array{
	 *     dry_run: bool,
	 *     all: bool,
	 *     mode: string,
	 *     plugins_processed: int,
	 *     files_processed: int,
	 *     conflicts: int,
	 *     inserted: int,
	 *     updated: int,
	 *     imported: int,
	 *     skipped: int,
	 *     deleted: int,
	 *     has_errors: bool,
	 *     plugins: list<array<string, mixed>>
	 * }
	 */
	public static function sync(
		?string $plugin_id = null,
		bool $all = false,
		bool $dry_run = false,
		string $mode = CsvImportMode::Upsert->value
	): array {
		return self::syncPaths(PluginManifest::getPath(), PluginLockfile::getPath(), $plugin_id, $all, $dry_run, $mode);
	}

	/**
	 * @return array{
	 *     dry_run: bool,
	 *     all: bool,
	 *     mode: string,
	 *     plugins_processed: int,
	 *     files_processed: int,
	 *     conflicts: int,
	 *     inserted: int,
	 *     updated: int,
	 *     imported: int,
	 *     skipped: int,
	 *     deleted: int,
	 *     has_errors: bool,
	 *     plugins: list<array<string, mixed>>
	 * }
	 */
	public static function syncPaths(
		string $manifest_path,
		string $lock_path,
		?string $plugin_id = null,
		bool $all = false,
		bool $dry_run = false,
		string $mode = CsvImportMode::Upsert->value
	): array {
		$manifest = file_exists($manifest_path)
			? PluginManifest::loadFromPath($manifest_path)
			: [
				'manifest_version' => 1,
				'plugins' => [],
				'path' => $manifest_path,
				'base_dir' => dirname($manifest_path),
			];
		$lock = file_exists($lock_path)
			? PluginLockfile::loadFromPath($lock_path)
			: [
				'lockfile_version' => 1,
				'plugins' => [],
				'path' => $lock_path,
				'base_dir' => dirname($lock_path),
			];

		if (!$all && ($plugin_id === null || $plugin_id === '')) {
			throw new RuntimeException('Provide a plugin id or use --all.');
		}

		$plugin_ids = $all ? array_keys($lock['plugins']) : [$plugin_id];
		sort($plugin_ids);
		$plugin_results = [];
		$files_processed = 0;
		$conflicts = 0;
		$inserted = 0;
		$updated = 0;
		$imported = 0;
		$skipped = 0;
		$deleted = 0;
		$has_errors = false;

		foreach ($plugin_ids as $current_plugin_id) {
			if ($current_plugin_id === '') {
				continue;
			}

			$manifest_plugin = $manifest['plugins'][$current_plugin_id] ?? null;
			$lock_plugin = $lock['plugins'][$current_plugin_id] ?? null;
			$plugin_result = self::syncSinglePlugin(
				$current_plugin_id,
				is_array($manifest_plugin) ? $manifest_plugin : null,
				is_array($lock_plugin) ? $lock_plugin : null,
				$dry_run,
				$mode
			);
			$plugin_results[] = $plugin_result;
			$files_processed += (int) ($plugin_result['files_processed'] ?? 0);
			$conflicts += (int) ($plugin_result['conflicts'] ?? 0);
			$inserted += (int) ($plugin_result['inserted'] ?? 0);
			$updated += (int) ($plugin_result['updated'] ?? 0);
			$imported += (int) ($plugin_result['imported'] ?? 0);
			$skipped += (int) ($plugin_result['skipped'] ?? 0);
			$deleted += (int) ($plugin_result['deleted'] ?? 0);
			$has_errors = $has_errors || !empty($plugin_result['errors']);
		}

		return [
			'dry_run' => $dry_run,
			'all' => $all,
			'mode' => $mode,
			'plugins_processed' => count($plugin_results),
			'files_processed' => $files_processed,
			'conflicts' => $conflicts,
			'inserted' => $inserted,
			'updated' => $updated,
			'imported' => $imported,
			'skipped' => $skipped,
			'deleted' => $deleted,
			'has_errors' => $has_errors,
			'plugins' => $plugin_results,
		];
	}

	/**
	 * @param array<string, mixed>|null $manifest_plugin
	 * @param array<string, mixed>|null $lock_plugin
	 * @return array<string, mixed>
	 */
	private static function syncSinglePlugin(
		string $plugin_id,
		?array $manifest_plugin,
		?array $lock_plugin,
		bool $dry_run,
		string $mode
	): array {
		$plugin_path = PluginI18nSeedService::resolvePluginPath($manifest_plugin, $lock_plugin);
		$seed_dirs = $plugin_path !== null ? PluginI18nSeedService::listSeedDirectories($plugin_path) : [];

		if ($plugin_path === null || !is_dir($plugin_path)) {
			return [
				'plugin_id' => $plugin_id,
				'plugin_path' => $plugin_path,
				'seed_dir' => null,
				'seed_dirs' => [],
				'status' => 'error',
				'files_processed' => 0,
				'conflicts' => 0,
				'inserted' => 0,
				'updated' => 0,
				'imported' => 0,
				'skipped' => 0,
				'deleted' => 0,
				'errors' => ['plugin_filesystem_missing'],
				'files' => [],
			];
		}

		if ($seed_dirs === []) {
			return [
				'plugin_id' => $plugin_id,
				'plugin_path' => $plugin_path,
				'seed_dir' => null,
				'seed_dirs' => [],
				'status' => 'no_seeds',
				'files_processed' => 0,
				'conflicts' => 0,
				'inserted' => 0,
				'updated' => 0,
				'imported' => 0,
				'skipped' => 0,
				'deleted' => 0,
				'errors' => [],
				'files' => [],
			];
		}

		$aggregate = [
			'files_processed' => 0,
			'conflicts' => 0,
			'inserted' => 0,
			'updated' => 0,
			'imported' => 0,
			'skipped' => 0,
			'deleted' => 0,
			'errors' => [],
			'files' => [],
			'has_errors' => false,
		];

		foreach ($seed_dirs as $seed_dir) {
			$result = I18nSeedSyncService::syncDirectory($seed_dir, [
				'dry_run' => $dry_run,
				'mode' => $mode,
			]);
			$aggregate['files_processed'] += (int) $result['files_processed'];
			$aggregate['conflicts'] += (int) $result['conflicts'];
			$aggregate['inserted'] += (int) $result['inserted'];
			$aggregate['updated'] += (int) $result['updated'];
			$aggregate['imported'] += (int) $result['imported'];
			$aggregate['skipped'] += (int) $result['skipped'];
			$aggregate['deleted'] += (int) $result['deleted'];
			$aggregate['has_errors'] = $aggregate['has_errors'] || (bool) $result['has_errors'];

			foreach ($result['files'] as $file) {
				$file['seed_dir'] = $seed_dir;
				$aggregate['files'][] = $file;
			}
		}

		return [
			'plugin_id' => $plugin_id,
			'plugin_path' => $plugin_path,
			'seed_dir' => count($seed_dirs) === 1 ? $seed_dirs[0] : null,
			'seed_dirs' => $seed_dirs,
			'status' => $aggregate['has_errors'] ? 'error' : ($aggregate['files_processed'] > 0 ? 'ok' : 'no_seeds'),
			'files_processed' => $aggregate['files_processed'],
			'conflicts' => $aggregate['conflicts'],
			'inserted' => $aggregate['inserted'],
			'updated' => $aggregate['updated'],
			'imported' => $aggregate['imported'],
			'skipped' => $aggregate['skipped'],
			'deleted' => $aggregate['deleted'],
			'errors' => self::flattenErrors($aggregate['files']),
			'files' => $aggregate['files'],
		];
	}

	/**
	 * @param list<array<string, mixed>> $files
	 * @return list<string>
	 */
	private static function flattenErrors(array $files): array
	{
		$errors = [];

		foreach ($files as $file) {
			foreach (($file['errors'] ?? []) as $error) {
				$error = trim((string) $error);

				if ($error === '') {
					continue;
				}

				$errors[$error] = true;
			}
		}

		return array_keys($errors);
	}
}
