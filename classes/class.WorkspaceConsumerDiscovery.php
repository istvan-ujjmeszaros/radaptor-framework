<?php

class WorkspaceConsumerDiscovery
{
	/**
	 * @return list<string>
	 */
	public static function discoverCommittedConsumerRoots(): array
	{
		$workspace_root = self::resolveWorkspaceRoot();

		if ($workspace_root === null || !is_dir($workspace_root)) {
			return [];
		}

		$roots = [];
		$candidates = glob(rtrim($workspace_root, '/') . '/*', GLOB_ONLYDIR) ?: [];

		foreach ($candidates as $candidate) {
			$manifest_path = rtrim($candidate, '/') . '/radaptor.json';
			$lock_path = rtrim($candidate, '/') . '/radaptor.lock.json';

			if (!is_file($manifest_path) || !is_file($lock_path)) {
				continue;
			}

			$repository = GitRepositoryInspector::inspect($candidate);

			if ($repository['is_repository'] !== true) {
				continue;
			}

			$tracked_files = GitRepositoryInspector::listTrackedFiles($candidate);

			if (
				!in_array('radaptor.json', $tracked_files, true)
				|| !in_array('radaptor.lock.json', $tracked_files, true)
			) {
				continue;
			}

			$roots[] = rtrim(str_replace('\\', '/', $candidate), '/');
		}

		sort($roots);

		return array_values(array_unique($roots));
	}

	public static function resolveWorkspaceRoot(): ?string
	{
		$candidates = [];
		$configured = trim((string) getenv('RADAPTOR_WORKSPACE_ROOT'));

		if ($configured !== '') {
			$candidates[] = $configured;
		}

		try {
			$registry_root = LocalRegistryRootResolver::resolve();
			$candidates[] = dirname($registry_root);
		} catch (Throwable) {
		}

		$candidates[] = dirname(rtrim(str_replace('\\', '/', DEPLOY_ROOT), '/'));

		foreach ($candidates as $candidate) {
			$normalized = rtrim(str_replace('\\', '/', $candidate), '/');

			if ($normalized === '' || !is_dir($normalized)) {
				continue;
			}

			if (is_dir($normalized . '/packages-dev') && is_dir($normalized . '/radaptor_package_registry')) {
				return $normalized;
			}
		}

		return null;
	}
}
