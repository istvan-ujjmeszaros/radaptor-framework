<?php

class CLICommandSeedStatus extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show seed status';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Show data seed status for the current app and installed packages.

			Usage: radaptor seed:status [--include-demo-seeds] [--module <module>] [--seed-class <SeedClass>] [--json]

			Examples:
			  radaptor seed:status
			  radaptor seed:status --include-demo-seeds
			  radaptor seed:status --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'safe';
	}

	public function run(): void
	{
		$include_demo_seeds = Request::hasArg('include-demo-seeds');
		$json = Request::hasArg('json');
		$module_filter = CLIOptionHelper::getOption('module');
		$seed_class_filter = CLIOptionHelper::getOption('seed-class');

		try {
			$result = SeedRunner::status(
				$include_demo_seeds,
				$module_filter !== '' ? $module_filter : null,
				$seed_class_filter !== '' ? $seed_class_filter : null
			);
		} catch (Throwable $exception) {
			if ($json) {
				echo json_encode([
					'status' => 'error',
					'message' => $exception->getMessage(),
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

				return;
			}

			echo "Seed status failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			echo json_encode([
				'status' => 'success',
				...$result,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		echo "Seeds total: {$result['seeds_total']}\n";
		echo "Mandatory: {$result['mandatory_total']}\n";
		echo "Demo: {$result['demo_total']}\n";
		echo "Pending: {$result['status_counts']['pending']}\n";
		echo "Applied: {$result['status_counts']['applied']}\n";
		echo "Changed: {$result['status_counts']['changed']}\n";
		echo "Bootstrap auto-skipped: {$result['status_counts']['bootstrap_auto_skipped']}\n";

		foreach ($result['seeds'] as $seed) {
			$version_note = '';

			if (($seed['status'] ?? null) === 'bootstrap_auto_skipped') {
				$version_note = " (applied {$seed['applied_version']}, current {$seed['current_version']})";
			}

			echo "{$seed['module']} / {$seed['class']} ({$seed['kind']}): {$seed['status']}{$version_note}\n";

			if (!empty($seed['message'])) {
				echo "  {$seed['message']}\n";
			}
		}
	}
}
