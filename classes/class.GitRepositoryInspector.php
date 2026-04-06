<?php

class GitRepositoryInspector
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
	public static function inspect(string $path): array
	{
		$info = [
			'path' => $path,
			'git_available' => self::hasGitBinary(),
			'is_repository' => false,
			'branch' => null,
			'commit' => null,
			'dirty' => null,
		];

		if (!is_dir($path) || !$info['git_available']) {
			return $info;
		}

		if (!is_dir($path . '/.git') && !is_file($path . '/.git')) {
			return $info;
		}

		$info['is_repository'] = true;
		$branch = self::runGit($path, 'rev-parse', '--abbrev-ref', 'HEAD');
		$commit = self::runGit($path, 'rev-parse', 'HEAD');
		$status = self::runGit($path, 'status', '--porcelain');

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
	public static function listTrackedFiles(string $path): array
	{
		$output = self::runGit($path, 'ls-files', '-z');

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

	private static function runGit(string $path, string ...$args): ?string
	{
		$command = ['git', '-c', 'safe.directory=' . $path, '-C', $path, ...$args];
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
