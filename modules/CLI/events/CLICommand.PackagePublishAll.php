<?php

/**
 * Publish all first-party core/theme package checkouts from packages/dev into the local registry.
 *
 * Usage:
 *   radaptor package:publish-all [--registry-root /path/to/radaptor_plugin_registry] [--json]
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
			  radaptor package:publish-all [--registry-root /path/to/radaptor_plugin_registry] [--json]

			This scans the active app's packages/dev checkouts, publishes each tracked package artifact,
			and refreshes registry.json in one pass.
			DOC;
	}

	public function run(): void
	{
		$json = Request::hasArg('json');
		$registry_root = $this->getSingleOption('registry-root');

		try {
			$result = PackagePublishService::publishAll(
				$registry_root !== '' ? $registry_root : null
			);
		} catch (Throwable $e) {
			if ($json) {
				echo json_encode([
					'status' => 'error',
					'message' => $e->getMessage(),
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

				return;
			}

			echo "Package publish failed: {$e->getMessage()}\n";

			return;
		}

		if ($json) {
			echo json_encode([
				'status' => 'success',
				...$result,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		echo "Registry root: {$result['registry_root']}\n";
		echo "Published packages: " . count($result['package_keys']) . "\n";

		foreach ($result['published'] as $package_key => $package_result) {
			echo "- {$package_key} -> {$package_result['dist_path']}\n";
		}
	}

	private function getSingleOption(string $name): string
	{
		global $argv;

		foreach ($argv as $idx => $arg) {
			if ($arg === "--{$name}") {
				$value = $argv[$idx + 1] ?? null;

				return is_string($value) && !str_starts_with($value, '--') ? trim($value) : '';
			}
		}

		$key_value = Request::getArg($name);

		return is_string($key_value) ? trim($key_value) : '';
	}
}
