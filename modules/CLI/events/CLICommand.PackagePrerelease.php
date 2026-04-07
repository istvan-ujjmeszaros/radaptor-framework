<?php

/**
 * Release a first-party core/theme package as a new immutable prerelease version.
 *
 * Usage:
 *   radaptor package:prerelease <package-key> [--channel alpha|beta|rc] [--registry-root /path/to/radaptor_plugin_registry] [--dry-run] [--json]
 */
class CLICommandPackagePrerelease extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Prerelease first-party package';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Release a first-party core/theme package as a new immutable prerelease version.

			Usage:
			  radaptor package:prerelease <package-key> [--channel alpha|beta|rc] [--registry-root /path/to/radaptor_plugin_registry] [--dry-run] [--json]

			Examples:
			  radaptor package:prerelease core:framework --channel alpha
			  radaptor package:prerelease theme:so-admin --json

			Notes:
			  - Stable versions require an explicit --channel.
			  - Existing prerelease versions continue on the same channel when --channel is omitted.
			DOC;
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor package:prerelease <package-key> [--channel alpha|beta|rc] [--registry-root /path/to/radaptor_plugin_registry] [--dry-run] [--json]';
		$package_key = CLIOptionHelper::getMainArgOrAbort($usage);
		$json = CLIOptionHelper::isJson();
		$dry_run = Request::hasArg('dry-run');
		$registry_root = CLIOptionHelper::getOption('registry-root');
		$channel = CLIOptionHelper::getOption('channel');

		try {
			$result = PackageReleaseService::prerelease(
				$package_key,
				$channel !== '' ? $channel : null,
				$registry_root !== '' ? $registry_root : null,
				dry_run: $dry_run
			);
		} catch (Throwable $e) {
			if ($json) {
				CLIOptionHelper::writeJson([
					'status' => 'error',
					'message' => $e->getMessage(),
				]);

				return;
			}

			echo "Package prerelease failed: {$e->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson([
				'status' => 'success',
				...$result,
			]);

			return;
		}

		echo "Package key: {$result['package_key']}\n";
		echo "Package: {$result['package']}\n";
		echo "Previous version: {$result['previous_version']}\n";
		echo "New version: {$result['new_version']}\n";
		echo "Channel: {$result['channel']}\n";
		echo "Source path: {$result['source_path']}\n";
		echo "Registry root: {$result['registry_root']}\n";
		echo "Source commit: {$result['source_commit']}\n";
		echo "Released at: {$result['released_at']}\n";
		echo "Dry run: " . ($result['dry_run'] ? 'yes' : 'no') . "\n";

		if (is_array($result['build'] ?? null)) {
			echo "Artifact: {$result['build']['dist_path']}\n";
			echo "Dist URL: {$result['build']['dist_url']}\n";
			echo "SHA256: {$result['build']['sha256']}\n";
		}
	}
}
