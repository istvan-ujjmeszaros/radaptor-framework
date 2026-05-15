<?php

class PluginStateInspector
{
	/**
	 * @return array{
	 *     manifest: array<string, mixed>,
	 *     lockfile: array<string, mixed>,
	 *     runtime_registry: array<string, mixed>,
	 *     registries: array<string, array<string, mixed>>,
	 *     plugins: array<int, array<string, mixed>>,
	 *     summary: array<string, mixed>
	 * }
	 */
	public static function getStatus(): array
	{
		$manifest_exists = file_exists(PluginManifest::getPath());
		$lock_exists = file_exists(PluginLockfile::getPath());
		$generated_exists = PluginRegistry::hasGeneratedRegistry();

		$manifest = $manifest_exists
			? PluginManifest::load()
			: ['manifest_version' => 0, 'plugins' => [], 'path' => PluginManifest::getPath(), 'base_dir' => DEPLOY_ROOT];

		$lock = $lock_exists
			? PluginLockfile::load()
			: ['lockfile_version' => 0, 'plugins' => [], 'path' => PluginLockfile::getPath(), 'base_dir' => DEPLOY_ROOT];

		$generated_plugins = PluginRegistry::getGeneratedPlugins();

		$registries = self::buildRegistryStatus($manifest['plugins']);
		$plugins = self::buildPluginStatus($manifest['plugins'], $lock['plugins'], $generated_plugins, $registries, $generated_exists);

		$plugin_issue_count = count(array_filter($plugins, static fn (array $plugin): bool => ($plugin['status'] ?? '') !== 'ok'));
		$registry_issue_count = count(array_filter($registries, static fn (array $registry): bool => ($registry['status'] ?? '') !== 'ok'));

		return [
			'manifest' => [
				'path' => $manifest['path'],
				'exists' => $manifest_exists,
				'version' => $manifest['manifest_version'],
			],
			'lockfile' => [
				'path' => $lock['path'],
				'exists' => $lock_exists,
				'version' => $lock['lockfile_version'],
			],
			'runtime_registry' => [
				'path' => DEPLOY_ROOT . ApplicationConfig::GENERATED_PLUGINS_FILE,
				'exists' => $generated_exists,
				'plugin_count' => count($generated_plugins),
			],
			'registries' => $registries,
			'plugins' => array_values($plugins),
			'summary' => [
				'total_plugins' => count($plugins),
				'ok_plugins' => count($plugins) - $plugin_issue_count,
				'plugin_issues' => $plugin_issue_count,
				'registry_issues' => $registry_issue_count,
				'in_sync' => $manifest_exists && $lock_exists && $plugin_issue_count === 0 && $registry_issue_count === 0,
			],
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $manifest_plugins
	 * @return array<string, array<string, mixed>>
	 */
	private static function buildRegistryStatus(array $manifest_plugins): array
	{
		$status = [];

		foreach ($manifest_plugins as $plugin_id => $plugin) {
			$source = is_array($plugin['source'] ?? null) ? $plugin['source'] : [];
			$source_type = (string) ($source['type'] ?? '');

			if ($source_type !== 'registry') {
				continue;
			}

			$registry_url = null;

			if (isset($source['resolved_registry_url']) && is_string($source['resolved_registry_url']) && $source['resolved_registry_url'] !== '') {
				$registry_url = $source['resolved_registry_url'];
			} elseif (isset($source['registry']) && is_string($source['registry']) && $source['registry'] !== '') {
				$registry_url = $source['registry'];
			}

			if ($registry_url === null) {
				$registry_key = 'plugin:' . $plugin_id . ':missing';
				$status[$registry_key] = [
					'name' => $plugin_id,
					'type' => 'url',
					'resolved_url' => null,
					'plugins' => [$plugin_id],
					'status' => 'issues',
					'issues' => ['invalid_url'],
				];

				continue;
			}

			$registry_key = $registry_url;

			if (!isset($status[$registry_key])) {
				$status[$registry_key] = [
					'name' => $registry_url,
					'type' => 'url',
					'resolved_url' => $registry_url,
					'plugins' => [],
				];
			}

			$status[$registry_key]['plugins'][] = $plugin_id;
		}

		foreach ($status as $name => $registry) {
			$issues = [];
			$resolved_url = $registry['resolved_url'] ?? null;

			if (!is_string($resolved_url) || !PluginRegistryClient::isSupportedRegistryUrl($resolved_url)) {
				$issues[] = 'invalid_url';
			}

			$status[$name] = [
				...$registry,
				'status' => empty($issues) ? 'ok' : 'issues',
				'issues' => $issues,
			];

			sort($status[$name]['plugins']);
		}

		return $status;
	}

	/**
	 * @param array<string, array<string, mixed>> $manifest_plugins
	 * @param array<string, array<string, mixed>> $lock_plugins
	 * @param array<string, array<string, mixed>> $generated_plugins
	 * @param array<string, array<string, mixed>> $registries
	 * @return array<string, array<string, mixed>>
	 */
	private static function buildPluginStatus(
		array $manifest_plugins,
		array $lock_plugins,
		array $generated_plugins,
		array $registries,
		bool $generated_exists
	): array {
		$plugin_ids = array_unique([
			...array_keys($manifest_plugins),
			...array_keys($lock_plugins),
			...array_keys($generated_plugins),
		]);
		sort($plugin_ids);

		$status = [];

		foreach ($plugin_ids as $plugin_id) {
			$manifest_plugin = $manifest_plugins[$plugin_id] ?? null;
			$lock_plugin = $lock_plugins[$plugin_id] ?? null;
			$generated_plugin = $generated_plugins[$plugin_id] ?? null;
			$manifest_source = is_array($manifest_plugin['source'] ?? null) ? $manifest_plugin['source'] : [];
			$lock_source = is_array($lock_plugin['source'] ?? null) ? $lock_plugin['source'] : [];
			$lock_resolved = is_array($lock_plugin['resolved'] ?? null) ? $lock_plugin['resolved'] : [];
			$resolved_source = $lock_resolved !== []
				? $lock_resolved
				: ($lock_source !== [] ? $lock_source : $manifest_source);
			$issues = [];
			$warnings = [];
			$auto_installed = (bool) ($lock_plugin['auto_installed'] ?? false);

			if ($manifest_plugin === null && !$auto_installed) {
				$issues[] = 'not_in_manifest';
			}

			if ($lock_plugin === null) {
				$issues[] = 'not_in_lockfile';
			}

			if ($manifest_plugin !== null && $lock_plugin !== null) {
				if (($manifest_plugin['package'] ?? null) !== ($lock_plugin['package'] ?? null)) {
					$issues[] = 'package_mismatch';
				}

				if (!self::sourcesMatch($manifest_source, $lock_source)) {
					$issues[] = 'source_mismatch';
				}
			}

			$resolved_path = $resolved_source['resolved_path'] ?? null;
			$filesystem_present = is_string($resolved_path) && is_dir($resolved_path);

			if (is_string($resolved_path) && !$filesystem_present) {
				$issues[] = 'missing_filesystem_path';
			}

			$descriptor_relative_path = $generated_plugin['descriptor_file']
				?? $lock_plugin['descriptor_file']
				?? null;
			$descriptor_absolute_path = is_string($descriptor_relative_path)
				? DEPLOY_ROOT . ltrim($descriptor_relative_path, '/')
				: null;
			$descriptor_present = is_string($descriptor_absolute_path) && file_exists($descriptor_absolute_path);

			if ($descriptor_absolute_path !== null && !$descriptor_present) {
				$issues[] = 'missing_descriptor';
			}

			if ($generated_exists && $filesystem_present && $generated_plugin === null) {
				$issues[] = 'missing_generated_registry_entry';
			}

			if ($generated_exists && !$filesystem_present && $generated_plugin !== null) {
				$issues[] = 'stale_generated_registry_entry';
			}

			$source_registry = $manifest_source['resolved_registry_url'] ?? $manifest_source['registry'] ?? null;

			if (($manifest_source['type'] ?? null) === 'registry' && (!is_string($source_registry) || $source_registry === '')) {
				$issues[] = 'invalid_registry_url';
			}

			if (is_string($source_registry) && isset($registries[$source_registry]) && ($registries[$source_registry]['status'] ?? '') !== 'ok') {
				$issues[] = 'registry_unavailable';
			}

			$normalized_plugin_id = PluginIdHelper::normalize($plugin_id, 'Plugin status');
			$dev_path = DEPLOY_ROOT . 'plugins/dev/' . $normalized_plugin_id;
			$registry_path = DEPLOY_ROOT . 'plugins/registry/' . $normalized_plugin_id;

			if (is_dir($dev_path) && is_dir($registry_path)) {
				$warnings[] = 'dev_overrides_registry';
			}

			$dev_repository = null;

			if (($resolved_source['type'] ?? $manifest_source['type'] ?? null) === 'dev' && is_string($resolved_path) && $resolved_path !== '') {
				$dev_repository = PluginDevRepositoryInspector::inspect($resolved_path);

				if ($dev_repository['git_available'] && !$dev_repository['is_repository']) {
					$issues[] = 'missing_dev_git_repository';
				}

				if (($dev_repository['dirty'] ?? false) === true) {
					$warnings[] = 'dev_repo_dirty';
				}
			}

			$status[$plugin_id] = [
				'plugin_id' => $plugin_id,
				'package' => $manifest_plugin['package'] ?? $lock_plugin['package'] ?? null,
				'status' => empty($issues) ? 'ok' : 'issues',
				'issues' => $issues,
				'warnings' => $warnings,
				'auto_installed' => $auto_installed,
				'required_by' => $lock_plugin['required_by'] ?? [],
				'desired_source' => $manifest_source === [] ? null : $manifest_source,
				'locked_source' => $lock_source === [] ? null : $lock_source,
				'resolved_source' => $resolved_source,
				'resolved_version' => $lock_resolved['version'] ?? null,
				'filesystem_present' => $filesystem_present,
				'filesystem_path' => $resolved_path,
				'descriptor_file' => $descriptor_relative_path,
				'descriptor_present' => $descriptor_present,
				'dev_repository' => $dev_repository,
				'runtime_registered' => $generated_plugin !== null,
				'runtime' => $generated_plugin,
				'dependencies' => PluginDependencyHelper::normalizeDependencies(
					$generated_plugin['dependencies']
						?? $lock_plugin['dependencies']
						?? $manifest_plugin['dependencies']
						?? [],
					"Plugin '{$plugin_id}'"
				),
				'missing_dependencies' => [],
				'dependency_version_mismatches' => [],
			];
		}

		self::applyDependencyStatus($status);

		return $status;
	}

	/**
	 * @param array<string, array<string, mixed>> $status
	 */
	private static function applyDependencyStatus(array &$status): void
	{
		$plugins = [];

		foreach ($status as $plugin_id => $plugin_status) {
			$plugins[$plugin_id] = [
				'package' => $plugin_status['package'] ?? null,
				'dependencies' => $plugin_status['dependencies'] ?? [],
				'resolved' => [
					'version' => $plugin_status['resolved_version'] ?? null,
				],
			];
		}

		$missing = PluginDependencyHelper::findMissingDependencies($plugins);
		$mismatches = PluginDependencyHelper::findDependencyVersionMismatches($plugins);
		$cycle_plugins = [];

		try {
			PluginDependencyHelper::sortPluginIdsByDependencies($plugins);
		} catch (RuntimeException $e) {
			if (str_starts_with($e->getMessage(), 'Plugin dependency cycle detected: ')) {
				$cycle_plugins = array_values(array_filter(array_map(
					'trim',
					explode(',', substr($e->getMessage(), strlen('Plugin dependency cycle detected: ')))
				)));
			} else {
				throw $e;
			}
		}

		foreach ($status as $plugin_id => &$plugin_status) {
			$plugin_missing = $missing[$plugin_id] ?? [];
			$plugin_status['missing_dependencies'] = $plugin_missing;

			if ($plugin_missing !== [] && !in_array('missing_dependency', $plugin_status['issues'], true)) {
				$plugin_status['issues'][] = 'missing_dependency';
			}

			$plugin_mismatches = $mismatches[$plugin_id] ?? [];
			$plugin_status['dependency_version_mismatches'] = $plugin_mismatches;

			if ($plugin_mismatches !== [] && !in_array('dependency_version_mismatch', $plugin_status['issues'], true)) {
				$plugin_status['issues'][] = 'dependency_version_mismatch';
			}

			if (in_array($plugin_id, $cycle_plugins, true) && !in_array('dependency_cycle', $plugin_status['issues'], true)) {
				$plugin_status['issues'][] = 'dependency_cycle';
			}

			if ($plugin_status['status'] === 'ok' && $plugin_status['issues'] !== []) {
				$plugin_status['status'] = 'issues';
			}
		}
		unset($plugin_status);
	}

	/**
	 * @param array<string, mixed> $manifest_source
	 * @param array<string, mixed> $lock_source
	 */
	private static function sourcesMatch(array $manifest_source, array $lock_source): bool
	{
		if (($manifest_source['type'] ?? null) !== ($lock_source['type'] ?? null)) {
			return false;
		}

		if (($manifest_source['type'] ?? null) === 'dev') {
			return ($manifest_source['path'] ?? null) === ($lock_source['path'] ?? null);
		}

		if (($manifest_source['type'] ?? null) === 'registry') {
			return ($manifest_source['registry'] ?? null) === ($lock_source['registry'] ?? null);
		}

		return $manifest_source === $lock_source;
	}
}
