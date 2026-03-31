<?php

class I18nShippedSyncService
{
	/**
	 * @param array{
	 *     locales?: list<string>,
	 *     mode?: string,
	 *     dry_run?: bool,
	 *     build?: bool
	 * } $options
	 * @return array{
	 *     dry_run: bool,
	 *     mode: string,
	 *     build_requested: bool,
	 *     build_ran: bool,
	 *     built_locales: list<string>,
	 *     locales_filter: list<string>,
	 *     groups_processed: int,
	 *     files_processed: int,
	 *     conflicts: int,
	 *     inserted: int,
	 *     updated: int,
	 *     imported: int,
	 *     skipped: int,
	 *     deleted: int,
	 *     has_errors: bool,
	 *     groups: list<array<string, mixed>>
	 * }
	 */
	public static function sync(array $options = []): array
	{
		return self::syncPaths(
			I18nShippedSeedRegistry::getSyncTargets(),
			PluginLockfile::getPath(),
			$options
		);
	}

	/**
	 * @param list<array{group_type:string,group_id:string,input_dir:string}> $core_seed_targets
	 * @param array{
	 *     locales?: list<string>,
	 *     mode?: string,
	 *     dry_run?: bool,
	 *     build?: bool
	 * } $options
	 * @return array{
	 *     dry_run: bool,
	 *     mode: string,
	 *     build_requested: bool,
	 *     build_ran: bool,
	 *     built_locales: list<string>,
	 *     locales_filter: list<string>,
	 *     groups_processed: int,
	 *     files_processed: int,
	 *     conflicts: int,
	 *     inserted: int,
	 *     updated: int,
	 *     imported: int,
	 *     skipped: int,
	 *     deleted: int,
	 *     has_errors: bool,
	 *     groups: list<array<string, mixed>>
	 * }
	 */
	public static function syncPaths(array $core_seed_targets, string $lock_path, array $options = []): array
	{
		$dry_run = (bool) ($options['dry_run'] ?? false);
		$build_requested = (bool) ($options['build'] ?? true);
		$mode = trim((string) ($options['mode'] ?? CsvImportMode::Upsert->value));
		$locales_filter = self::normalizeLocales($options['locales'] ?? []);
		$targets = [
			...$core_seed_targets,
			...self::discoverPluginSeedTargets($lock_path),
		];

		usort($targets, static function (array $left, array $right): int {
			return [$left['group_type'], $left['group_id']] <=> [$right['group_type'], $right['group_id']];
		});

		$groups = [];
		$files_processed = 0;
		$conflicts = 0;
		$inserted = 0;
		$updated = 0;
		$imported = 0;
		$skipped = 0;
		$deleted = 0;
		$has_errors = false;
		$locales_to_build = [];

		foreach ($targets as $target) {
			$group = self::syncTarget($target, $locales_filter, $mode, $dry_run);
			$groups[] = $group;
			$files_processed += (int) ($group['files_processed'] ?? 0);
			$conflicts += (int) ($group['conflicts'] ?? 0);
			$inserted += (int) ($group['inserted'] ?? 0);
			$updated += (int) ($group['updated'] ?? 0);
			$imported += (int) ($group['imported'] ?? 0);
			$skipped += (int) ($group['skipped'] ?? 0);
			$deleted += (int) ($group['deleted'] ?? 0);
			$has_errors = $has_errors || (bool) ($group['has_errors'] ?? false);

			foreach (($group['locales'] ?? []) as $locale) {
				$locales_to_build[(string) $locale] = true;
			}
		}

		$built_locales = [];
		$build_ran = false;

		if (!$dry_run && $build_requested && !$has_errors && !empty($locales_to_build)) {
			$build_ran = true;
			$built_locales = self::buildLocales(array_keys($locales_to_build));
		}

		return [
			'dry_run' => $dry_run,
			'mode' => $mode,
			'build_requested' => $build_requested,
			'build_ran' => $build_ran,
			'built_locales' => $built_locales,
			'locales_filter' => $locales_filter,
			'groups_processed' => count($groups),
			'files_processed' => $files_processed,
			'conflicts' => $conflicts,
			'inserted' => $inserted,
			'updated' => $updated,
			'imported' => $imported,
			'skipped' => $skipped,
			'deleted' => $deleted,
			'has_errors' => $has_errors,
			'groups' => $groups,
		];
	}

	/**
	 * @return list<array{group_type:string,group_id:string,input_dir:string}>
	 */
	private static function discoverPluginSeedTargets(string $lock_path): array
	{
		if (!file_exists($lock_path)) {
			return [];
		}

		$lock = PluginLockfile::loadFromPath($lock_path);
		$targets = [];
		$base_dir = dirname($lock_path);

		foreach ($lock['plugins'] as $plugin_id => $plugin) {
			$plugin_path = self::resolveLockedPluginPath($plugin, $base_dir);

			if ($plugin_path === null) {
				continue;
			}

			$targets[] = [
				'group_type' => 'plugin',
				'group_id' => (string) $plugin_id,
				'input_dir' => rtrim($plugin_path, '/') . '/i18n/seeds',
			];
		}

		return $targets;
	}

	/**
	 * @param array<string, mixed> $plugin
	 */
	private static function resolveLockedPluginPath(array $plugin, string $base_dir): ?string
	{
		$resolved = $plugin['resolved'] ?? null;

		if (!is_array($resolved)) {
			return null;
		}

		$path = $resolved['path'] ?? null;

		if (!is_string($path) || trim($path) === '') {
			return null;
		}

		if (str_starts_with($path, '/')) {
			return rtrim($path, '/');
		}

		return rtrim($base_dir . '/' . ltrim($path, '/'), '/');
	}

	/**
	 * @param array{group_type:string,group_id:string,input_dir:string} $target
	 * @param list<string> $locales_filter
	 * @return array<string, mixed>
	 */
	private static function syncTarget(array $target, array $locales_filter, string $mode, bool $dry_run): array
	{
		$input_dir = rtrim($target['input_dir'], '/');

		if (!is_dir($input_dir)) {
			return [
				...$target,
				'status' => 'missing',
				'locales' => [],
				'files_processed' => 0,
				'conflicts' => 0,
				'inserted' => 0,
				'updated' => 0,
				'imported' => 0,
				'skipped' => 0,
				'deleted' => 0,
				'has_errors' => false,
				'files' => [],
				'errors' => [],
			];
		}

		$result = I18nSeedSyncService::syncDirectory($input_dir, [
			'locales' => $locales_filter,
			'mode' => $mode,
			'dry_run' => $dry_run,
		]);

		return [
			...$target,
			'status' => $result['has_errors'] ? 'error' : ($result['files_processed'] > 0 ? 'ok' : 'empty'),
			'locales' => $result['locales'],
			'files_processed' => $result['files_processed'],
			'conflicts' => $result['conflicts'],
			'inserted' => $result['inserted'],
			'updated' => $result['updated'],
			'imported' => $result['imported'],
			'skipped' => $result['skipped'],
			'deleted' => $result['deleted'],
			'has_errors' => $result['has_errors'],
			'files' => $result['files'],
			'errors' => [],
		];
	}

	/**
	 * @param mixed $locales
	 * @return list<string>
	 */
	private static function normalizeLocales(mixed $locales): array
	{
		if (!is_array($locales)) {
			return [];
		}

		$normalized = [];

		foreach ($locales as $locale) {
			$locale = trim((string) $locale);

			if ($locale === '') {
				continue;
			}

			if (!LocaleRegistry::isKnownLocale($locale)) {
				throw new RuntimeException("Unknown locale: {$locale}");
			}

			$normalized[$locale] = true;
		}

		return array_keys($normalized);
	}

	/**
	 * @param list<string> $locales
	 * @return list<string>
	 */
	private static function buildLocales(array $locales): array
	{
		sort($locales);
		$built = [];

		foreach ($locales as $locale) {
			$built_locales = I18nCatalogBuilder::build($locale);

			foreach ($built_locales as $built_locale) {
				$built[(string) $built_locale] = true;
			}
		}

		return array_keys($built);
	}
}
