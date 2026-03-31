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
		$info = [
			'path' => $plugin_path,
			'git_available' => self::hasGitBinary(),
			'is_repository' => false,
			'branch' => null,
			'commit' => null,
			'dirty' => null,
		];

		if (!is_dir($plugin_path) || !$info['git_available']) {
			return $info;
		}

		if (!is_dir($plugin_path . '/.git') && !is_file($plugin_path . '/.git')) {
			return $info;
		}

		$info['is_repository'] = true;
		$branch = self::runGit($plugin_path, 'rev-parse', '--abbrev-ref', 'HEAD');
		$commit = self::runGit($plugin_path, 'rev-parse', 'HEAD');
		$status = self::runGit($plugin_path, 'status', '--porcelain');

		$info['branch'] = $branch !== null && $branch !== '' ? $branch : null;
		$info['commit'] = $commit !== null && $commit !== '' ? $commit : null;
		$info['dirty'] = $status !== null ? trim($status) !== '' : null;

		return $info;
	}

	public static function hasGitBinary(): bool
	{
		static $has_git = null;

		if ($has_git !== null) {
			return $has_git;
		}

		$output = [];
		$exit_code = 0;
		exec('git --version 2>/dev/null', $output, $exit_code);
		$has_git = $exit_code === 0;

		return $has_git;
	}

	/**
	 * @return list<string>
	 */
	public static function listTrackedFiles(string $plugin_path): array
	{
		$output = self::runGit($plugin_path, 'ls-files', '-z');

		if ($output === null || $output === '') {
			return [];
		}

		$files = array_filter(
			explode("\0", $output),
			static fn (string $entry): bool => $entry !== ''
		);
		sort($files);

		return $files;
	}

	private static function runGit(string $plugin_path, string ...$args): ?string
	{
		$command = ['git', '-c', 'safe.directory=' . $plugin_path, '-C', $plugin_path, ...$args];
		$escaped = array_map('escapeshellarg', $command);
		$output = [];
		$exit_code = 0;
		exec(implode(' ', $escaped) . ' 2>/dev/null', $output, $exit_code);

		if ($exit_code !== 0) {
			return null;
		}

		return implode("\n", $output);
	}
}
