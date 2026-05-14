<?php

declare(strict_types=1);

/**
 * Applies layout renames declared by packages (via `deprecated_layouts` in `.registry-package.json`)
 * to the live CMS content. Used by the install/update preflight gate.
 *
 * Two attribute scopes are reconciled:
 *
 *   1. `resource_data.layout` — per-webpage layout assignment (the user-visible binding).
 *   2. `_theme_settings.<layout_name>` — layout->theme mapping read by `Themes::getThemeNameForLayout()`.
 *
 * `config_user.themeoverride:*` is theme-keyed (not layout-keyed) and is NOT touched.
 */
final class LayoutReconciliationService
{
	/**
	 * Detects everything that would change if the given renames were applied.
	 *
	 * @param array<string, string> $renames old layout name => new layout name
	 * @return array{
	 *     renames: array<string, string>,
	 *     webpages: list<array{resource_id: int, path: string, resource_name: string, old_layout: string, new_layout: string}>,
	 *     theme_settings: list<array{old_layout: string, new_layout: string, theme: string, conflict: bool, conflict_theme: ?string}>,
	 *     has_changes: bool
	 * }
	 */
	public static function collectPending(array $renames): array
	{
		$webpages = [];
		$theme_settings = [];

		if (empty($renames)) {
			return [
				'renames' => [],
				'webpages' => [],
				'theme_settings' => [],
				'has_changes' => false,
			];
		}

		$pdo = Db::instance();

		foreach ($renames as $old_layout => $new_layout) {
			$stmt = $pdo->prepare(
				"SELECT a.resource_id, rt.path, rt.resource_name
				FROM `attributes` a
				JOIN `resource_tree` rt ON rt.node_id = a.resource_id
				WHERE a.resource_name = 'resource_data'
				  AND a.param_name = 'layout'
				  AND a.param_value = ?
				ORDER BY rt.path, rt.resource_name"
			);
			$stmt->execute([$old_layout]);

			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
				$webpages[] = [
					'resource_id' => (int) $row['resource_id'],
					'path' => (string) $row['path'],
					'resource_name' => (string) $row['resource_name'],
					'old_layout' => $old_layout,
					'new_layout' => $new_layout,
				];
			}

			$ts_old = $pdo->prepare(
				"SELECT `param_value` FROM `attributes`
				WHERE `resource_name` = '_theme_settings' AND `resource_id` = 0 AND `param_name` = ?
				LIMIT 1"
			);
			$ts_old->execute([$old_layout]);
			$ts_old_value = $ts_old->fetchColumn();

			if ($ts_old_value === false) {
				continue;
			}

			$ts_new = $pdo->prepare(
				"SELECT `param_value` FROM `attributes`
				WHERE `resource_name` = '_theme_settings' AND `resource_id` = 0 AND `param_name` = ?
				LIMIT 1"
			);
			$ts_new->execute([$new_layout]);
			$ts_new_value = $ts_new->fetchColumn();

			$theme_settings[] = [
				'old_layout' => $old_layout,
				'new_layout' => $new_layout,
				'theme' => (string) $ts_old_value,
				'conflict' => $ts_new_value !== false,
				'conflict_theme' => $ts_new_value === false ? null : (string) $ts_new_value,
			];
		}

		return [
			'renames' => $renames,
			'webpages' => $webpages,
			'theme_settings' => $theme_settings,
			'has_changes' => $webpages !== [] || $theme_settings !== [],
		];
	}

	/**
	 * Applies the pending changes inside a single transaction with audit-log entries.
	 *
	 * Conflict policy for `_theme_settings`: when the new key already exists, keep the new mapping
	 * and drop the old one (per the approved plan).
	 *
	 * @param array{
	 *     renames: array<string, string>,
	 *     webpages: list<array{resource_id: int, path: string, resource_name: string, old_layout: string, new_layout: string}>,
	 *     theme_settings: list<array{old_layout: string, new_layout: string, theme: string, conflict: bool, conflict_theme: ?string}>,
	 *     has_changes: bool
	 * } $pending
	 * @param array<string, array{new_layout: string, package: string, version: string}> $rename_metadata
	 *
	 * @return array{
	 *     webpages_updated: int,
	 *     theme_settings_renamed: int,
	 *     theme_settings_dropped: int
	 * }
	 */
	public static function apply(array $pending, array $rename_metadata = []): array
	{
		if (!$pending['has_changes']) {
			return ['webpages_updated' => 0, 'theme_settings_renamed' => 0, 'theme_settings_dropped' => 0];
		}

		$pdo = Db::instance();
		$started_transaction = false;

		if (!$pdo->inTransaction()) {
			$pdo->beginTransaction();
			$started_transaction = true;
		}

		try {
			$result = CmsMutationAuditService::withContext(
				'layout:rename',
				['renames' => $pending['renames']],
				static function () use ($pdo, $pending, $rename_metadata): array {
					$webpages_updated = self::applyWebpageRenames($pdo, $pending['webpages'], $rename_metadata);
					$ts_stats = self::applyThemeSettingsRenames($pdo, $pending['theme_settings'], $rename_metadata);

					return [
						'webpages_updated' => $webpages_updated,
						'theme_settings_renamed' => $ts_stats['renamed'],
						'theme_settings_dropped' => $ts_stats['dropped'],
					];
				},
				['actor_type' => 'cli']
			);

			if ($started_transaction) {
				$pdo->commit();
			}

			return $result;
		} catch (Throwable $exception) {
			if ($started_transaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}

			throw $exception;
		}
	}

	/**
	 * @param list<array{resource_id: int, path: string, resource_name: string, old_layout: string, new_layout: string}> $webpages
	 * @param array<string, array{new_layout: string, package: string, version: string}> $rename_metadata
	 */
	private static function applyWebpageRenames(PDO $pdo, array $webpages, array $rename_metadata): int
	{
		if ($webpages === []) {
			return 0;
		}

		$update = $pdo->prepare(
			"UPDATE `attributes`
			SET `param_value` = ?
			WHERE `resource_name` = 'resource_data'
			  AND `resource_id` = ?
			  AND `param_name` = 'layout'
			  AND `param_value` = ?"
		);

		$updated = 0;

		foreach ($webpages as $row) {
			$update->execute([$row['new_layout'], $row['resource_id'], $row['old_layout']]);
			$updated += $update->rowCount();

			$metadata = $rename_metadata[$row['old_layout']] ?? null;

			CmsMutationAuditService::recordLeaf('layout:rename:webpage', [
				'resource_id' => $row['resource_id'],
				'resource_path' => $row['path'] . $row['resource_name'],
				'before' => ['layout' => $row['old_layout']],
				'after' => ['layout' => $row['new_layout']],
				'metadata' => $metadata === null ? [] : [
					'declared_by_package' => $metadata['package'],
					'declared_by_version' => $metadata['version'],
				],
			]);
		}

		return $updated;
	}

	/**
	 * @param list<array{old_layout: string, new_layout: string, theme: string, conflict: bool, conflict_theme: ?string}> $entries
	 * @param array<string, array{new_layout: string, package: string, version: string}> $rename_metadata
	 * @return array{renamed: int, dropped: int}
	 */
	private static function applyThemeSettingsRenames(PDO $pdo, array $entries, array $rename_metadata): array
	{
		if ($entries === []) {
			return ['renamed' => 0, 'dropped' => 0];
		}

		$rename = $pdo->prepare(
			"UPDATE `attributes`
			SET `param_name` = ?
			WHERE `resource_name` = '_theme_settings' AND `resource_id` = 0 AND `param_name` = ?"
		);
		$drop = $pdo->prepare(
			"DELETE FROM `attributes`
			WHERE `resource_name` = '_theme_settings' AND `resource_id` = 0 AND `param_name` = ?"
		);

		$renamed = 0;
		$dropped = 0;

		foreach ($entries as $entry) {
			$metadata = $rename_metadata[$entry['old_layout']] ?? null;

			if ($entry['conflict']) {
				$drop->execute([$entry['old_layout']]);
				$dropped += $drop->rowCount();

				CmsMutationAuditService::recordLeaf('layout:rename:theme_settings_conflict', [
					'before' => [$entry['old_layout'] => $entry['theme']],
					'after' => [$entry['new_layout'] => $entry['conflict_theme']],
					'metadata' => [
						'resolution' => 'dropped_old_kept_new',
						'declared_by_package' => $metadata['package'] ?? null,
						'declared_by_version' => $metadata['version'] ?? null,
					],
				]);

				continue;
			}

			$rename->execute([$entry['new_layout'], $entry['old_layout']]);
			$renamed += $rename->rowCount();

			CmsMutationAuditService::recordLeaf('layout:rename:theme_settings', [
				'before' => [$entry['old_layout'] => $entry['theme']],
				'after' => [$entry['new_layout'] => $entry['theme']],
				'metadata' => $metadata === null ? [] : [
					'declared_by_package' => $metadata['package'],
					'declared_by_version' => $metadata['version'],
				],
			]);
		}

		return ['renamed' => $renamed, 'dropped' => $dropped];
	}
}
