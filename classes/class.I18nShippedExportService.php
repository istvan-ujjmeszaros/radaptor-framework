<?php

class I18nShippedExportService
{
	/**
	 * @param array{
	 *     locales?: list<string>,
	 *     dry_run?: bool,
	 *     clean?: bool
	 * } $options
	 * @return array{
	 *     dry_run: bool,
	 *     clean: bool,
	 *     locales_filter: list<string>,
	 *     groups_processed: int,
	 *     files_written: int,
	 *     rows_exported: int,
	 *     deleted_files: list<string>,
	 *     groups: list<array<string, mixed>>
	 * }
	 */
	public static function export(array $options = []): array
	{
		return self::exportPaths(I18nShippedSeedRegistry::getExportTargets(), $options);
	}

	/**
	 * @param list<array{
	 *     group_type:string,
	 *     group_id:string,
	 *     output_dir:string,
	 *     domains:list<string>,
	 *     key_prefixes:list<string>
	 * }> $targets
	 * @param array{
	 *     locales?: list<string>,
	 *     dry_run?: bool,
	 *     clean?: bool
	 * } $options
	 * @return array{
	 *     dry_run: bool,
	 *     clean: bool,
	 *     locales_filter: list<string>,
	 *     groups_processed: int,
	 *     files_written: int,
	 *     rows_exported: int,
	 *     deleted_files: list<string>,
	 *     groups: list<array<string, mixed>>
	 * }
	 */
	public static function exportPaths(array $targets, array $options = []): array
	{
		$dry_run = (bool) ($options['dry_run'] ?? false);
		$clean = (bool) ($options['clean'] ?? false);
		$locales_filter = self::normalizeLocales($options['locales'] ?? []);
		$groups = [];
		$files_written = 0;
		$rows_exported = 0;
		$deleted_files = [];

		usort($targets, static function (array $left, array $right): int {
			return [$left['group_type'], $left['group_id']] <=> [$right['group_type'], $right['group_id']];
		});

		foreach ($targets as $target) {
			$result = I18nSeedExportService::exportDirectory($target['output_dir'], [
				'locales' => $locales_filter,
				'domains' => $target['domains'],
				'key_prefixes' => $target['key_prefixes'],
				'dry_run' => $dry_run,
				'clean' => $clean,
			]);

			$groups[] = [
				'group_type' => $target['group_type'],
				'group_id' => $target['group_id'],
				'output_dir' => $target['output_dir'],
				'domains' => $target['domains'],
				'key_prefixes' => $target['key_prefixes'],
				'files_written' => $result['files_written'],
				'rows_exported' => $result['rows_exported'],
				'deleted_files' => $result['deleted_files'],
				'files' => $result['files'],
			];
			$files_written += $result['files_written'];
			$rows_exported += $result['rows_exported'];
			$deleted_files = [...$deleted_files, ...$result['deleted_files']];
		}

		return [
			'dry_run' => $dry_run,
			'clean' => $clean,
			'locales_filter' => $locales_filter,
			'groups_processed' => count($groups),
			'files_written' => $files_written,
			'rows_exported' => $rows_exported,
			'deleted_files' => $deleted_files,
			'groups' => $groups,
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
}
