<?php

class PackageReleaseService
{
	/**
	 * @return array{
	 *     package_key: string,
	 *     package: string,
	 *     previous_version: string,
	 *     new_version: string,
	 *     channel: string|null,
	 *     source_path: string,
	 *     registry_root: string,
	 *     source_commit: string,
	 *     released_at: string,
	 *     dry_run: bool,
	 *     build: array<string, mixed>|null
	 * }
	 */
	public static function release(
		string $package_key,
		?string $registry_root = null,
		?string $manifest_path = null,
		?string $lock_path = null,
		bool $dry_run = false
	): array {
		$source_path = PackagePublishService::resolveSourcePathForPackageKey($package_key, $manifest_path, $lock_path);

		return self::run('release', $package_key, $source_path, null, $registry_root, $dry_run);
	}

	/**
	 * @return array{
	 *     package_key: string,
	 *     package: string,
	 *     previous_version: string,
	 *     new_version: string,
	 *     channel: string|null,
	 *     source_path: string,
	 *     registry_root: string,
	 *     source_commit: string,
	 *     released_at: string,
	 *     dry_run: bool,
	 *     build: array<string, mixed>|null
	 * }
	 */
	public static function prerelease(
		string $package_key,
		?string $channel = null,
		?string $registry_root = null,
		?string $manifest_path = null,
		?string $lock_path = null,
		bool $dry_run = false
	): array {
		$source_path = PackagePublishService::resolveSourcePathForPackageKey($package_key, $manifest_path, $lock_path);

		return self::run('prerelease', $package_key, $source_path, $channel, $registry_root, $dry_run);
	}

	/**
	 * @return array{
	 *     package_key: string,
	 *     package: string,
	 *     previous_version: string,
	 *     new_version: string,
	 *     channel: string|null,
	 *     source_path: string,
	 *     registry_root: string,
	 *     source_commit: string,
	 *     released_at: string,
	 *     dry_run: bool,
	 *     build: array<string, mixed>|null
	 * }
	 */
	public static function releaseFromSourcePath(
		string $source_path,
		?string $registry_root = null,
		bool $dry_run = false
	): array {
		$metadata = PackageMetadataHelper::loadFromSourcePath($source_path);
		$package_key = PackageTypeHelper::getKey($metadata['type'], $metadata['id']);

		return self::run('release', $package_key, $source_path, null, $registry_root, $dry_run);
	}

	/**
	 * @return array{
	 *     package_key: string,
	 *     package: string,
	 *     previous_version: string,
	 *     new_version: string,
	 *     channel: string|null,
	 *     source_path: string,
	 *     registry_root: string,
	 *     source_commit: string,
	 *     released_at: string,
	 *     dry_run: bool,
	 *     build: array<string, mixed>|null
	 * }
	 */
	public static function prereleaseFromSourcePath(
		string $source_path,
		?string $channel = null,
		?string $registry_root = null,
		bool $dry_run = false
	): array {
		$metadata = PackageMetadataHelper::loadFromSourcePath($source_path);
		$package_key = PackageTypeHelper::getKey($metadata['type'], $metadata['id']);

		return self::run('prerelease', $package_key, $source_path, $channel, $registry_root, $dry_run);
	}

	/**
	 * @return array{
	 *     package_key: string,
	 *     package: string,
	 *     previous_version: string,
	 *     new_version: string,
	 *     channel: string|null,
	 *     source_path: string,
	 *     registry_root: string,
	 *     source_commit: string,
	 *     released_at: string,
	 *     dry_run: bool,
	 *     build: array<string, mixed>|null
	 * }
	 */
	private static function run(
		string $mode,
		string $package_key,
		string $source_path,
		?string $channel,
		?string $registry_root,
		bool $dry_run
	): array {
		$normalized_package_key = trim($package_key);
		$repository = GitRepositoryInspector::inspect($source_path);

		if ($repository['git_available'] !== true || $repository['is_repository'] !== true) {
			throw new RuntimeException("First-party package source must live in a Git repository before release: {$source_path}");
		}

		$tracked_files = GitRepositoryInspector::listTrackedFiles($source_path);
		$metadata_relative_path = '.registry-package.json';

		if (!in_array($metadata_relative_path, $tracked_files, true)) {
			throw new RuntimeException("Package metadata file must be tracked before release: {$source_path}/{$metadata_relative_path}");
		}

		$tracked_changes = GitRepositoryInspector::listTrackedChanges($source_path);

		if ($tracked_changes !== []) {
			throw new RuntimeException(
				"Package repository has tracked changes and cannot be released: {$source_path}\n" . implode("\n", $tracked_changes)
			);
		}

		$metadata = PackageMetadataHelper::loadFromSourcePath($source_path);
		$source_commit = trim((string) ($repository['commit'] ?? ''));

		if ($source_commit === '') {
			throw new RuntimeException("Unable to determine release source commit for {$source_path}.");
		}

		$version_plan = $mode === 'prerelease'
			? PackageReleaseVersionHelper::planPrerelease($metadata['version'], $channel)
			: PackageReleaseVersionHelper::planStableRelease($metadata['version']);
		$registry_root = LocalRegistryRootResolver::resolve($registry_root);
		$released_at = gmdate('c');

		$result = [
			'package_key' => $normalized_package_key,
			'package' => $metadata['package'],
			'previous_version' => $version_plan['previous_version'],
			'new_version' => $version_plan['new_version'],
			'channel' => $version_plan['channel'],
			'source_path' => $source_path,
			'registry_root' => $registry_root,
			'source_commit' => $source_commit,
			'released_at' => $released_at,
			'dry_run' => $dry_run,
			'build' => null,
		];

		if ($dry_run) {
			return $result;
		}

		$metadata_path = $source_path . '/' . $metadata_relative_path;
		$original_metadata = file_get_contents($metadata_path);

		if ($original_metadata === false) {
			throw new RuntimeException("Unable to read package metadata: {$metadata_path}");
		}

		$build = null;

		try {
			PackageMetadataHelper::updateVersionAtSourcePath($source_path, $version_plan['new_version']);
			$build = PackagePublishService::publishFromSourcePath(
				$source_path,
				$registry_root,
				$normalized_package_key,
				[
					'source_commit' => $source_commit,
					'released_at' => $released_at,
				]
			);
		} catch (Throwable $exception) {
			file_put_contents($metadata_path, $original_metadata, LOCK_EX);

			throw $exception;
		}

		$result['build'] = $build;

		return $result;
	}
}
