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

			Usage: radaptor seed:status [--include-demo-seeds] [--json]

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

		try {
			$result = SeedRunner::status($include_demo_seeds);
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

		foreach ($result['seeds'] as $seed) {
			echo "{$seed['module']} / {$seed['class']} ({$seed['kind']}): {$seed['status']}\n";
		}
	}
}
