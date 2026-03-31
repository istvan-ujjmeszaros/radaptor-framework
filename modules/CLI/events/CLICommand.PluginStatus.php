<?php

/**
 * Inspect plugin manifest, lockfile, filesystem, and generated runtime registry state.
 *
 * Usage: radaptor plugin:status [--json]
 */
class CLICommandPluginStatus extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show plugin status';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Inspect plugin manifest, lockfile, filesystem, and generated runtime registry state.

			Usage: radaptor plugin:status [--json]

			Examples:
			  radaptor plugin:status
			  radaptor plugin:status --json
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
		];
	}

	public function run(): void
	{
		$status = PluginStateInspector::getStatus();

		if (Request::hasArg('json')) {
			echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		echo "Plugin Control Plane Status\n";
		echo str_repeat("=", 80) . "\n\n";

		echo "Manifest: " . ($status['manifest']['exists'] ? '[OK] ' : '[--] ') . $status['manifest']['path'] . "\n";
		echo "Lockfile: " . ($status['lockfile']['exists'] ? '[OK] ' : '[--] ') . $status['lockfile']['path'] . "\n";
		echo "Runtime registry: " . ($status['runtime_registry']['exists'] ? '[OK] ' : '[--] ') . $status['runtime_registry']['path'] . "\n";
		echo "\nRegistry Sources\n";
		echo str_repeat("-", 80) . "\n";

		if (empty($status['registries'])) {
			echo "(no registry-backed plugins declared)\n";
		} else {
			foreach ($status['registries'] as $registry) {
				$indicator = $registry['status'] === 'ok' ? '[OK]' : '[!!]';
				$location = $registry['resolved_url'] ?? ($registry['resolved_path'] ?? '-');
				$issues = empty($registry['issues']) ? '-' : implode(', ', $registry['issues']);
				echo "{$indicator} {$location} ({$registry['type']}) | {$issues}\n";
			}
		}

		echo "\nPlugins\n";
		echo str_repeat("-", 80) . "\n";

		if (empty($status['plugins'])) {
			echo "(no plugins declared or discovered)\n";
		} else {
			echo str_pad('Plugin', 18);
			echo str_pad('Status', 10);
			echo str_pad('Filesystem', 12);
			echo str_pad('Runtime', 10);
			echo "Issues\n";
			echo str_repeat("-", 80) . "\n";

			foreach ($status['plugins'] as $plugin) {
				echo str_pad((string) $plugin['plugin_id'], 18);
				echo str_pad((string) strtoupper((string) $plugin['status']), 10);
				echo str_pad($plugin['filesystem_present'] ? 'yes' : 'no', 12);
				echo str_pad($plugin['runtime_registered'] ? 'yes' : 'no', 10);
				echo (empty($plugin['issues']) ? '-' : implode(', ', $plugin['issues'])) . "\n";
			}
		}

		echo str_repeat("-", 80) . "\n";
		echo "Plugins: {$status['summary']['total_plugins']} | OK: {$status['summary']['ok_plugins']} | Plugin issues: {$status['summary']['plugin_issues']} | Registry issues: {$status['summary']['registry_issues']}\n";
		echo "In sync: " . ($status['summary']['in_sync'] ? 'yes' : 'no') . "\n";
	}
}
