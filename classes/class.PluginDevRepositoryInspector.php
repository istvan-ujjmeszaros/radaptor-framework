<?php

class PluginDevRepositoryInspector
{
	/**
	 * @return array{
	 *     path: string,
	 *     git_available: bool,
	 *     is_repository: bool,
	 *     branch: string|null,
	 *     commit: string|null,
	 *     dirty: bool|null
	 * }
	 */
	public static function inspect(string $plugin_path): array
	{
		return GitRepositoryInspector::inspect($plugin_path);
	}

	public static function hasGitBinary(): bool
	{
		return GitRepositoryInspector::hasGitBinary();
	}

	/**
	 * @return list<string>
	 */
	public static function listTrackedFiles(string $plugin_path): array
	{
		return GitRepositoryInspector::listTrackedFiles($plugin_path);
	}
}
