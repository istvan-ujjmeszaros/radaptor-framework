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
		$package_key = Request::getMainArg();

		if (!is_string($package_key) || trim($package_key) === '') {
			Kernel::abort('Usage: radaptor package:publish <package-key> [--registry-root /path/to/radaptor_plugin_registry] [--json]');
		}

		$json = Request::hasArg('json');
		$registry_root = $this->getSingleOption('registry-root');

		try {
			$result = PackagePublishService::publish(
				trim($package_key),
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
