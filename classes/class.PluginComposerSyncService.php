<?php

class PluginComposerSyncService
{
	/**
	 * @return array{
	 *     dry_run: bool,
	 *     changed: bool,
	 *     composer_json_changed: bool,
	 *     composer_lockfile_changed: bool,
	 *     composer_json_written: bool,
	 *     composer_lockfile_written: bool,
	 *     added_packages: array<string, string>,
	 *     updated_packages: array<string, array{from: string, to: string}>,
	 *     removed_packages: array<string, string>,
	 *     root_owned_warnings: array<string, array{current_constraint: string, requested_constraint: string, owners: array<string, string>}>,
	 *     state: array{
	 *         lockfile_version: int,
	 *         packages: array<string, array<string, mixed>>
	 *     }
	 * }
	 */
	public static function sync(bool $dry_run = false): array
	{
		return self::syncPaths(
			ComposerJsonHelper::getPath(),
			PluginComposerLockfile::getPath(),
			PluginLockfile::getPath(),
			$dry_run
		);
	}

	/**
	 * @return array{
	 *     dry_run: bool,
	 *     changed: bool,
	 *     composer_json_changed: bool,
	 *     composer_lockfile_changed: bool,
	 *     composer_json_written: bool,
	 *     composer_lockfile_written: bool,
	 *     added_packages: array<string, string>,
	 *     updated_packages: array<string, array{from: string, to: string}>,
	 *     removed_packages: array<string, string>,
	 *     root_owned_warnings: array<string, array{current_constraint: string, requested_constraint: string, owners: array<string, string>}>,
	 *     state: array{
	 *         lockfile_version: int,
	 *         packages: array<string, array<string, mixed>>
	 *     }
	 * }
	 */
	public static function syncPaths(
		string $composer_json_path,
		string $plugin_composer_lock_path,
		string $plugin_lock_path,
		bool $dry_run = false
	): array {
		$composer = ComposerJsonHelper::loadFromPath($composer_json_path);
		$plugin_lock = PluginLockfile::loadFromPath($plugin_lock_path);
		$composer_lock = PluginComposerLockfile::loadFromPath($plugin_composer_lock_path);
		$desired = self::collectDesiredComposerPackages($plugin_lock['plugins']);
		$merge = self::mergeState($composer['require'], $composer_lock['packages'], $desired);
		$next_composer_data = ComposerJsonHelper::withRequire($composer['data'], $merge['require']);
		$next_composer_lock = [
			'lockfile_version' => max(1, (int) $composer_lock['lockfile_version']),
			'packages' => $merge['packages'],
		];
		$composer_json_changed = $composer['require'] !== $merge['require'];
		$composer_lock_changed = PluginComposerLockfile::exportDocument($composer_lock) !== PluginComposerLockfile::exportDocument($next_composer_lock);
		$composer_json_written = false;
		$composer_lock_written = false;

		if (!$dry_run && $composer_json_changed) {
			ComposerJsonHelper::write($next_composer_data, $composer_json_path);
			$composer_json_written = true;
		}

		if (!$dry_run && $composer_lock_changed) {
			PluginComposerLockfile::write($next_composer_lock, $plugin_composer_lock_path);
			$composer_lock_written = true;
		}

		return [
			'dry_run' => $dry_run,
			'changed' => $composer_json_changed || $composer_lock_changed,
			'composer_json_changed' => $composer_json_changed,
			'composer_lockfile_changed' => $composer_lock_changed,
			'composer_json_written' => $composer_json_written,
			'composer_lockfile_written' => $composer_lock_written,
			'added_packages' => $merge['added_packages'],
			'updated_packages' => $merge['updated_packages'],
			'removed_packages' => $merge['removed_packages'],
			'root_owned_warnings' => $merge['root_owned_warnings'],
			'state' => PluginComposerLockfile::exportDocument($next_composer_lock),
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $plugins
	 * @return array<string, array{
	 *     constraint: string,
	 *     owners: array<string, string>
	 * }>
	 */
	private static function collectDesiredComposerPackages(array $plugins): array
	{
		$packages = [];

		foreach ($plugins as $plugin_id => $plugin) {
			$composer = is_array($plugin['composer'] ?? null) ? $plugin['composer'] : [];
			$require = PluginDependencyHelper::normalizeDependencies(
				$composer['require'] ?? [],
				"Locked plugin '{$plugin_id}' composer.require"
			);

			foreach ($require as $package => $constraint) {
				$packages[$package]['owners'][$plugin_id] = $constraint;
			}
		}

		foreach ($packages as $package => $entry) {
			ksort($entry['owners']);
			$packages[$package] = [
				'constraint' => self::combineConstraints(array_values($entry['owners'])),
				'owners' => $entry['owners'],
			];
		}

		ksort($packages);

		return $packages;
	}

	/**
	 * @param array<string, string> $current_require
	 * @param array<string, array<string, mixed>> $current_packages
	 * @param array<string, array{constraint: string, owners: array<string, string>}> $desired
	 * @return array{
	 *     require: array<string, string>,
	 *     packages: array<string, array<string, mixed>>,
	 *     added_packages: array<string, string>,
	 *     updated_packages: array<string, array{from: string, to: string}>,
	 *     removed_packages: array<string, string>,
	 *     root_owned_warnings: array<string, array{current_constraint: string, requested_constraint: string, owners: array<string, string>}>
	 * }
	 */
	private static function mergeState(array $current_require, array $current_packages, array $desired): array
	{
		$next_require = $current_require;
		$next_packages = [];
		$added_packages = [];
		$updated_packages = [];
		$removed_packages = [];
		$root_owned_warnings = [];
		$all_packages = array_unique([
			...array_keys($current_require),
			...array_keys($current_packages),
			...array_keys($desired),
		]);
		sort($all_packages);

		foreach ($all_packages as $package) {
			$current_constraint = $current_require[$package] ?? null;
			$current_entry = $current_packages[$package] ?? null;
			$current_managed = is_array($current_entry) && (($current_entry['managed'] ?? false) === true);
			$desired_entry = $desired[$package] ?? null;

			if ($desired_entry !== null) {
				$requested_constraint = $desired_entry['constraint'];
				$owners = $desired_entry['owners'];

				if ($current_constraint === null) {
					$next_require[$package] = $requested_constraint;
					$added_packages[$package] = $requested_constraint;
					$next_packages[$package] = self::buildStatePackageEntry(
						$requested_constraint,
						true,
						false,
						$owners
					);

					continue;
				}

				if ($current_managed) {
					if ($current_constraint !== $requested_constraint) {
						$next_require[$package] = $requested_constraint;
						$updated_packages[$package] = [
							'from' => $current_constraint,
							'to' => $requested_constraint,
						];
					}

					$next_packages[$package] = self::buildStatePackageEntry(
						$requested_constraint,
						true,
						false,
						$owners
					);

					continue;
				}

				$next_packages[$package] = self::buildStatePackageEntry(
					$current_constraint,
					false,
					true,
					$owners
				);

				if ($current_constraint !== $requested_constraint) {
					$root_owned_warnings[$package] = [
						'current_constraint' => $current_constraint,
						'requested_constraint' => $requested_constraint,
						'owners' => $owners,
					];
				}

				continue;
			}

			if ($current_constraint === null) {
				continue;
			}

			if ($current_managed) {
				unset($next_require[$package]);
				$removed_packages[$package] = $current_constraint;

				continue;
			}

			$next_packages[$package] = self::buildStatePackageEntry(
				$current_constraint,
				false,
				true,
				[]
			);
		}

		ksort($next_require);
		ksort($next_packages);
		ksort($added_packages);
		ksort($updated_packages);
		ksort($removed_packages);
		ksort($root_owned_warnings);

		return [
			'require' => $next_require,
			'packages' => $next_packages,
			'added_packages' => $added_packages,
			'updated_packages' => $updated_packages,
			'removed_packages' => $removed_packages,
			'root_owned_warnings' => $root_owned_warnings,
		];
	}

	/**
	 * @param array<string, string> $owners
	 * @return array{
	 *     constraint: string,
	 *     managed: bool,
	 *     root_owned: bool,
	 *     owners: array<string, string>
	 * }
	 */
	private static function buildStatePackageEntry(
		string $constraint,
		bool $managed,
		bool $root_owned,
		array $owners
	): array {
		$owners = PluginDependencyHelper::normalizeDependencies(
			$owners,
			'Plugin composer ownership state owners'
		);

		return [
			'constraint' => trim($constraint),
			'managed' => $managed,
			'root_owned' => $root_owned,
			'owners' => $owners,
		];
	}

	/**
	 * @param list<string> $constraints
	 */
	private static function combineConstraints(array $constraints): string
	{
		$constraints = array_values(array_unique(array_filter(
			array_map(static fn (string $constraint): string => trim($constraint), $constraints),
			static fn (string $constraint): bool => $constraint !== ''
		)));
		sort($constraints);

		return implode(' ', $constraints);
	}
}
