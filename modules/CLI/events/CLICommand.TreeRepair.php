<?php

class CLICommandTreeRepair extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Repair nested tree consistency';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Repair nested-set lft/rgt values from parent_id links.

			The command defaults to dry-run. Pass --apply to write the planned repair.

			Usage: radaptor tree:repair --tree <tree-key>|<table-name> [--apply] [--json]

			Examples:
			  radaptor tree:repair --tree resource --json
			  radaptor tree:repair --tree resource --apply --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor tree:repair --tree <tree-key>|<table-name> [--apply] [--json]';
		$tree = CLIOptionHelper::getRequiredOption('tree', $usage);
		$json = CLIOptionHelper::isJson();
		$dry_run = !Request::hasArg('apply');

		try {
			$table = NestedSet::resolveTreeTable($tree);

			if (is_null($table)) {
				throw new InvalidArgumentException("Unknown tree: {$tree}");
			}

			$repair = NestedSet::repairConsistencyFromParentLinks($table, $dry_run);
			$result = [
				'status' => $repair['ok'] ? 'success' : 'error',
				'tree' => $tree,
			] + $repair;
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Tree repair failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo strtoupper($tree) . ': ' . ($result['ok'] ? 'OK' : 'ERROR') . " ({$result['node_count']} nodes)\n";
		echo ($result['dry_run'] ? '[dry-run] ' : '') . "{$result['planned_updates']} node boundary update(s) planned.\n";

		if (!$result['dry_run'] && $result['applied']) {
			echo "Repair applied.\n";
		}

		foreach ($result['issues'] as $issue) {
			echo "  - {$issue['code']}: {$issue['message']}\n";
		}
	}
}
