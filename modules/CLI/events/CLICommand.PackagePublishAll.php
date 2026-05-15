<?php

/**
 * Publish all first-party core/theme package checkouts from packages/dev into the local registry.
 *
 * Usage:
 *   radaptor package:publish-all [--registry-root /path/to/radaptor_package_registry] [--json]
 */
class CLICommandPackagePublishAll extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Publish all first-party packages to registry';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Publish all first-party core/theme package checkouts from packages/dev into the local registry.

			Usage:
			  radaptor package:publish-all [--registry-root /path/to/radaptor_package_registry] [--json]

			This scans the active app's packages/dev checkouts, publishes each tracked package artifact,
			and refreshes registry.json in one pass.
			DOC;
	}

	public function run(): void
	{
		$json = CLIOptionHelper::isJson();
		$registry_root = CLIOptionHelper::getOption('registry-root');

		try {
			$result = PackagePublishService::publishAll(
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

		echo "Registry root: {$result['registry_root']}\n";
		echo "Published packages: " . count($result['package_keys']) . "\n";

		foreach ($result['published'] as $package_key => $package_result) {
			echo "- {$package_key} -> {$package_result['dist_path']}\n";
		}
	}
}
