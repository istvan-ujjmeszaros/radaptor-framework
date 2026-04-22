<?php

class CLICommandPackageStatus extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show package status';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Inspect active package mode, source paths, registry copies, and freshness against the workspace registry.

			Usage: radaptor package:status [--json] [--ignore-local-overrides]
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
			['name' => 'ignore-local-overrides', 'label' => 'Ignore local overrides', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$ignore_local_overrides = Request::hasArg('ignore-local-overrides');
		$status = PackageStateInspector::getStatus($ignore_local_overrides);

		if (Request::hasArg('json')) {
			echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		echo "Package Mode: {$status['mode']}\n";
		echo "App root: {$status['app_root']}\n";
		echo "Manifest: {$status['manifest_path']}\n";
		echo "Lockfile: {$status['lock_path']}\n";
		echo "Workspace registry: "
			. (($status['workspace_registry']['available'] ?? false) ? '[OK] ' : '[--] ')
			. (($status['workspace_registry']['path'] ?? '') !== '' ? $status['workspace_registry']['path'] : 'unavailable')
			. "\n";

		if (($status['workspace_registry']['error'] ?? null) !== null) {
			echo "Workspace registry detail: {$status['workspace_registry']['error']}\n";
		}

		if ($status['issues'] !== []) {
			echo "Issues:\n";

			foreach ($status['issues'] as $issue) {
				echo "  - {$issue}\n";
			}
		}

		echo "\n";
		echo str_pad('Package', 20);
		echo str_pad('Source', 10);
		echo str_pad('Version', 16);
		echo str_pad('Freshness', 14);
		echo str_pad('Dirty', 8);
		echo "Active path\n";
		echo str_repeat('-', 96) . "\n";

		foreach ($status['packages'] as $package) {
			echo str_pad((string) $package['package_key'], 20);
			echo str_pad((string) $package['source_type'], 10);
			echo str_pad((string) ($package['version'] ?? '-'), 16);
			echo str_pad((string) ($package['freshness'] ?? 'unknown'), 14);
			echo str_pad(
				$package['source_dirty'] === null ? '-' : (($package['source_dirty'] ?? false) ? 'yes' : 'no'),
				8
			);
			echo (string) ($package['active_path'] ?? '-') . "\n";
		}
	}
}
