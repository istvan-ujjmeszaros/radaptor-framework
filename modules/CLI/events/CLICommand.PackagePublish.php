<?php

/**
 * Publish a first-party core/theme package checkout directly into the local package registry.
 *
 * Usage:
 *   radaptor package:publish <package-key> [--registry-root /path/to/radaptor_plugin_registry] [--json]
 */
class CLICommandPackagePublish extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Publish first-party package to registry';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Publish a first-party core/theme package checkout directly into the local package registry.

			Usage:
			  radaptor package:publish <package-key> [--registry-root /path/to/radaptor_plugin_registry] [--json]

			Examples:
			  radaptor package:publish core:framework
			  radaptor package:publish theme:portal-admin --json
			DOC;
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor package:publish <package-key> [--registry-root /path/to/radaptor_plugin_registry] [--json]';
		$package_key = CLIOptionHelper::getMainArgOrAbort($usage);
		$json = CLIOptionHelper::isJson();
		$registry_root = CLIOptionHelper::getOption('registry-root');

		try {
			$result = PackagePublishService::publish(
				$package_key,
				$registry_root !== '' ? $registry_root : null
			);
		} catch (Throwable $e) {
			if ($json) {
				CLIOptionHelper::writeJson([
					'status' => 'error',
					'message' => $e->getMessage(),
				]);

				return;
			}

			echo "Package publish failed: {$e->getMessage()}\n";

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
		echo "Version: {$result['version']}\n";
		echo "Source path: {$result['source_path']}\n";
		echo "Registry root: {$result['registry_root']}\n";
		echo "Artifact: {$result['dist_path']}\n";
		echo "Packaged files: {$result['packaged_files']}\n";
		echo "Registry rebuilt: " . ($result['registry_rebuilt'] ? 'yes' : 'no') . "\n";

		if (is_array($result['build'] ?? null)) {
			echo "Registry catalog: {$result['build']['registry_path']}\n";
			echo "Dist URL: {$result['build']['dist_url']}\n";
		}
	}
}
