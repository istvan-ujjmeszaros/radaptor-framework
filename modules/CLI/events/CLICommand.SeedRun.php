<?php

class CLICommandSeedRun extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Run seeds';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Run package/app data seeds.

			Usage: radaptor seed:run [--include-demo-seeds] [--rerun-demo-seeds] [--rerun-bootstrap-seeds] [--skip-seeds] [--module <module>] [--seed-class <SeedClass>] [--dry-run] [--json]

			Examples:
			  radaptor seed:run
			  radaptor seed:run --include-demo-seeds
			  radaptor seed:run --include-demo-seeds --rerun-demo-seeds
			  radaptor seed:run --module app --seed-class SeedSkeletonBootstrap --rerun-bootstrap-seeds
			  radaptor seed:run --skip-seeds
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$include_demo_seeds = Request::hasArg('include-demo-seeds');
		$rerun_demo_seeds = Request::hasArg('rerun-demo-seeds');
		$rerun_bootstrap_seeds = Request::hasArg('rerun-bootstrap-seeds');
		$skip_seeds = Request::hasArg('skip-seeds');
		$dry_run = Request::hasArg('dry-run');
		$json = Request::hasArg('json');
		$module_filter = CLIOptionHelper::getOption('module');
		$seed_class_filter = CLIOptionHelper::getOption('seed-class');
		$prompt = (!$json && SeedCliPromptHelper::isInteractive())
			? static fn (array $demo_seeds): bool => SeedCliPromptHelper::confirmDemoSeedRerun($demo_seeds)
			: null;

		try {
			$result = SeedRunner::run(
				$include_demo_seeds,
				$rerun_demo_seeds,
				$skip_seeds,
				$dry_run,
				$prompt,
				$module_filter !== '' ? $module_filter : null,
				$seed_class_filter !== '' ? $seed_class_filter : null,
				$rerun_bootstrap_seeds
			);
		} catch (Throwable $exception) {
			if ($json) {
				echo json_encode([
					'status' => 'error',
					'message' => $exception->getMessage(),
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

				return;
			}

			echo "Seed run failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		$prefix = $dry_run ? '[dry-run] ' : '';
		echo "{$prefix}Status: {$result['status']}\n";
		echo "{$prefix}Seeds processed: {$result['seeds_processed']}\n";
		echo "{$prefix}Seeds executed: {$result['seeds_executed']}\n";
		echo "{$prefix}Seeds skipped: {$result['seeds_skipped']}\n";

		if (!empty($result['message'])) {
			echo "{$prefix}{$result['message']}\n";
		}

		foreach ($result['seeds'] as $seed) {
			$run_status = $seed['run_status'] ?? $seed['status'] ?? 'unknown';
			echo "{$prefix}{$seed['module']} / {$seed['class']} ({$seed['kind']}): {$run_status}\n";

			if (!empty($seed['message'])) {
				echo "{$prefix}  {$seed['message']}\n";
			}
		}
	}
}
