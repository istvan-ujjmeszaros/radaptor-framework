<?php

/**
 * Publish a dev plugin repository directly into the local plugin registry artifact store.
 *
 * Usage:
 *   radaptor plugin:publish <plugin-id> [--registry-root /path/to/radaptor_plugin_registry] [--json]
 */
class CLICommandPluginPublish extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Publish plugin to registry';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Publish a dev plugin repository directly into the local plugin registry artifact store.

			Usage:
			  radaptor plugin:publish <plugin-id> [--registry-root /path/to/registry] [--json]

			Examples:
			  radaptor plugin:publish hello-world
			  radaptor plugin:publish hello-world --registry-root /path/to/radaptor_plugin_registry
			  radaptor plugin:publish hello-world --json
			DOC;
	}

	public function run(): void
	{
		$plugin_id = Request::getMainArg();

		if (!is_string($plugin_id) || trim($plugin_id) === '') {
			Kernel::abort('Usage: radaptor plugin:publish <plugin-id> [--registry-root /path/to/radaptor_plugin_registry] [--json]');
		}

		$json = Request::hasArg('json');
		$registry_root = $this->getSingleOption('registry-root');

		try {
			$result = PluginPublishService::publish(
				$plugin_id,
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

			echo "Plugin publish failed: {$e->getMessage()}\n";

			return;
		}

		if ($json) {
			echo json_encode([
				'status' => 'success',
				...$result,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		echo "Plugin: {$result['plugin_id']}\n";
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
