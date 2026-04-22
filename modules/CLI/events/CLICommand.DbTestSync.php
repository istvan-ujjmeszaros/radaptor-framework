<?php

class CLICommandDbTestSync extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Sync test database schema';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Rebuild the _test and _test_audit schemas from their dev counterparts when drift is detected.

			Usage: radaptor db:test-sync [--dry-run] [--json]
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'dry-run', 'label' => 'Dry run', 'type' => 'flag'],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$dry_run = Request::hasArg('dry-run');
		$json = Request::hasArg('json');

		try {
			$result = TestDatabaseSchemaSyncService::sync($dry_run);
		} catch (Throwable $e) {
			if ($json) {
				CLIOptionHelper::writeJson([
					'status' => 'error',
					'message' => $e->getMessage(),
				]);

				return;
			}

			echo "Test schema sync failed: {$e->getMessage()}\n";

			return;
		}

		$status = $result['drift_detected'] || $result['fixtures_missing'] ? 'changed' : 'ok';

		if ($json) {
			CLIOptionHelper::writeJson([
				'status' => $status,
				'dry_run' => $dry_run,
				...$result,
			]);

			return;
		}

		$prefix = $dry_run ? '[dry-run] ' : '';
		echo "{$prefix}Schema drift: " . ($result['drift_detected'] ? 'yes' : 'no') . "\n";
		echo "{$prefix}Schema rebuilt: " . ($result['schema_rebuilt'] ? 'yes' : 'no') . "\n";
		echo "{$prefix}Fixtures missing: " . ($result['fixtures_missing'] ? 'yes' : 'no') . "\n";
		echo "{$prefix}Fixtures loaded: " . ($result['fixtures_loaded'] ? 'yes' : 'no') . "\n";

		if ($result['schema_errors'] !== []) {
			echo "{$prefix}Schema errors:\n";

			foreach ($result['schema_errors'] as $kind => $items) {
				echo "{$prefix}  {$kind}: " . implode(', ', $items) . "\n";
			}
		}
	}
}
