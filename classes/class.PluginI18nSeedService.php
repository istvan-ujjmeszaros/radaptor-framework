<?php

class PluginI18nSeedService
{
	/** @var list<string> */
	private const EXCLUDED_DIRECTORIES = [
		'.git',
		'generated',
		'node_modules',
		'tmp',
		'vendor',
	];

	/**
	 * @return array{
	 *     dry_run: bool,
	 *     all: bool,
	 *     plugins_processed: int,
	 *     files_processed: int,
	 *     inserted: int,
	 *     updated: int,
	 *     imported: int,
	 *     skipped: int,
	 *     deleted: int,
	 *     has_errors: bool,
	 *     plugins: list<array<string, mixed>>
	 * }
	 */
	public static function seed(?string $plugin_id = null, bool $all = false, bool $dry_run = false): array
	{
		return self::seedPaths(PluginManifest::getPath(), PluginLockfile::getPath(), $plugin_id, $all, $dry_run);
	}

	/**
	 * @return array{
	 *     dry_run: bool,
	 *     all: bool,
	 *     plugins_processed: int,
	 *     files_processed: int,
	 *     inserted: int,
	 *     updated: int,
	 *     imported: int,
	 *     skipped: int,
	 *     deleted: int,
	 *     has_errors: bool,
	 *     plugins: list<array<string, mixed>>
	 * }
	 */
	public static function seedPaths(
		string $manifest_path,
		string $lock_path,
		?string $plugin_id = null,
		bool $all = false,
		bool $dry_run = false
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
			$plugin_result = self::seedSinglePlugin(
				$current_plugin_id,
				is_array($manifest_plugin) ? $manifest_plugin : null,
				is_array($lock_plugin) ? $lock_plugin : null,
				$dry_run
			);
			$plugin_results[] = $plugin_result;
			$files_processed += count($plugin_result['files']);
			$inserted += (int) $plugin_result['totals']['inserted'];
			$updated += (int) $plugin_result['totals']['updated'];
			$imported += (int) $plugin_result['totals']['imported'];
			$skipped += (int) $plugin_result['totals']['skipped'];
			$deleted += (int) $plugin_result['totals']['deleted'];
			$has_errors = $has_errors || !empty($plugin_result['errors']);
		}

		return [
			'dry_run' => $dry_run,
			'all' => $all,
			'plugins_processed' => count($plugin_results),
			'files_processed' => $files_processed,
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
	private static function seedSinglePlugin(
		string $plugin_id,
		?array $manifest_plugin,
		?array $lock_plugin,
		bool $dry_run
	): array {
		$plugin_path = self::resolvePluginPath($manifest_plugin, $lock_plugin);
		$seed_dirs = $plugin_path !== null ? self::listSeedDirectories($plugin_path) : [];
		$files = [];
		$errors = [];
		$totals = [
			'inserted' => 0,
			'updated' => 0,
			'imported' => 0,
			'skipped' => 0,
			'deleted' => 0,
		];

		if ($plugin_path === null || !is_dir($plugin_path)) {
			$errors[] = 'plugin_filesystem_missing';

			return [
				'plugin_id' => $plugin_id,
				'plugin_path' => $plugin_path,
				'seed_dir' => null,
				'seed_dirs' => [],
				'status' => 'error',
				'errors' => $errors,
				'files' => [],
				'totals' => $totals,
			];
		}

		if ($seed_dirs === []) {
			return [
				'plugin_id' => $plugin_id,
				'plugin_path' => $plugin_path,
				'seed_dir' => null,
				'seed_dirs' => [],
				'status' => 'no_seeds',
				'errors' => [],
				'files' => [],
				'totals' => $totals,
			];
		}

		foreach ($seed_dirs as $seed_dir) {
			$seed_files = glob($seed_dir . '/*.csv') ?: [];
			sort($seed_files);

			foreach ($seed_files as $seed_file) {
				$locale = basename($seed_file, '.csv');
				$file_result = self::importSeedFile($seed_file, $locale, $dry_run);
				$file_result['seed_dir'] = $seed_dir;
				$files[] = $file_result;
				$totals['inserted'] += (int) $file_result['inserted'];
				$totals['updated'] += (int) $file_result['updated'];
				$totals['imported'] += (int) $file_result['imported'];
				$totals['skipped'] += (int) $file_result['skipped'];
				$totals['deleted'] += (int) $file_result['deleted'];

				if (!empty($file_result['errors'])) {
					$errors = [...$errors, ...$file_result['errors']];
				}
			}
		}

		$status = empty($errors)
			? (empty($files) ? 'no_seeds' : 'ok')
			: 'error';

		return [
			'plugin_id' => $plugin_id,
			'plugin_path' => $plugin_path,
			'seed_dir' => count($seed_dirs) === 1 ? $seed_dirs[0] : null,
			'seed_dirs' => $seed_dirs,
			'status' => $status,
			'errors' => array_values(array_unique($errors)),
			'files' => $files,
			'totals' => $totals,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function importSeedFile(string $seed_file, string $locale, bool $dry_run): array
	{
		$csv_content = file_get_contents($seed_file);

		if ($csv_content === false) {
			return [
				'locale' => $locale,
				'file' => $seed_file,
				'inserted' => 0,
				'updated' => 0,
				'imported' => 0,
				'skipped' => 0,
				'deleted' => 0,
				'errors' => ["Unable to read seed file: {$seed_file}"],
			];
		}

		$dataset = new ImportExportDatasetI18nTranslations();

		try {
			$result = $dataset->import($csv_content, [
				'format' => 'auto',
				'mode' => CsvImportMode::InsertNew->value,
				'expect_locale' => $locale,
				'dry_run' => $dry_run ? '1' : '0',
			]);
		} catch (InvalidArgumentException $e) {
			return [
				'locale' => $locale,
				'file' => $seed_file,
				'inserted' => 0,
				'updated' => 0,
				'imported' => 0,
				'skipped' => 0,
				'deleted' => 0,
				'errors' => array_values(array_filter(explode("\n", $e->getMessage()))),
			];
		}

		return [
			'locale' => $locale,
			'file' => $seed_file,
			'format' => $result['format'] ?? 'auto',
			'mode' => $result['mode'] ?? CsvImportMode::InsertNew->value,
			'detected_locales' => $result['detected_locales'] ?? [$locale],
			'processed' => (int) $result['processed'],
			'inserted' => (int) $result['inserted'],
			'updated' => (int) $result['updated'],
			'imported' => (int) $result['imported'],
			'skipped' => (int) $result['skipped'],
			'deleted' => (int) $result['deleted'],
			'errors' => $result['errors'],
		];
	}

	/**
	 * @param array<string, mixed>|null $manifest_plugin
	 * @param array<string, mixed>|null $lock_plugin
	 */
	public static function resolvePluginPath(?array $manifest_plugin, ?array $lock_plugin): ?string
	{
		$resolved = is_array($lock_plugin['resolved'] ?? null) ? $lock_plugin['resolved'] : null;
		$lock_source = is_array($lock_plugin['source'] ?? null) ? $lock_plugin['source'] : null;
		$manifest_source = is_array($manifest_plugin['source'] ?? null) ? $manifest_plugin['source'] : null;

		foreach ([$resolved, $lock_source, $manifest_source] as $source) {
			if (!is_array($source)) {
				continue;
			}

			if (isset($source['resolved_path']) && is_string($source['resolved_path'])) {
				return rtrim($source['resolved_path'], '/');
			}

			if (isset($source['path']) && is_string($source['path'])) {
				$path = $source['path'];

				return str_starts_with($path, '/')
					? rtrim($path, '/')
					: rtrim(DEPLOY_ROOT . ltrim($path, '/'), '/');
			}
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	public static function listSeedDirectories(string $plugin_path): array
	{
		if (!is_dir($plugin_path)) {
			return [];
		}

		$directory_iterator = new RecursiveDirectoryIterator($plugin_path, FilesystemIterator::SKIP_DOTS);
		$filter = new RecursiveCallbackFilterIterator(
			$directory_iterator,
			static function (SplFileInfo $current): bool {
				if (!$current->isDir()) {
					return false;
				}

				return !in_array($current->getBasename(), self::EXCLUDED_DIRECTORIES, true);
			}
		);
		$iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
		$seed_dirs = [];

		foreach ($iterator as $file_info) {
			if (
				$file_info->isDir()
				&& $file_info->getBasename() === 'seeds'
				&& basename(str_replace('\\', '/', $file_info->getPath())) === 'i18n'
			) {
				$seed_dirs[] = rtrim(str_replace('\\', '/', $file_info->getPathname()), '/');
			}
		}

		sort($seed_dirs);

		return array_values(array_unique($seed_dirs));
	}
}
