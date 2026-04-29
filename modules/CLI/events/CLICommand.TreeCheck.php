<?php

class CLICommandTreeCheck extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Check nested tree consistency';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Check nested-set consistency across one or more known tree tables.

			Usage: radaptor tree:check [--tree all|<tree-key>|<table-name>] [--json]

			Examples:
			  radaptor tree:check
			  radaptor tree:check --tree resource --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		$choices = ['all' => 'all'];

		foreach (array_keys(NestedSet::getTreeTableChoices()) as $choice) {
			$choices[$choice] = $choice;
		}

		return [
			['name' => 'tree', 'label' => 'Tree', 'type' => 'option', 'required' => false, 'choices' => $choices],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$tree = CLIOptionHelper::getOption('tree', 'all');
		$json = CLIOptionHelper::isJson();

		try {
			$reports = [];
			$tables = NestedSet::getTreeTableChoices();
			$selected = $tree === 'all' ? array_keys($tables) : [$tree];

			foreach ($selected as $tree_key) {
				$table = NestedSet::resolveTreeTable($tree_key);

				if (is_null($table)) {
					throw new InvalidArgumentException("Unknown tree: {$tree_key}");
				}

				$reports[$tree_key] = NestedSet::analyzeConsistency($table);
			}

			$ok = array_reduce(
				$reports,
				static fn (bool $carry, array $report): bool => $carry && (bool) $report['ok'],
				true
			);
			$result = [
				'status' => $ok ? 'success' : 'error',
				'ok' => $ok,
				'trees' => $reports,
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Tree check failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		foreach ($result['trees'] as $tree_key => $report) {
			echo strtoupper($tree_key) . ': ' . ($report['ok'] ? 'OK' : 'ERROR') . " ({$report['node_count']} nodes)\n";

			foreach ($report['issues'] as $issue) {
				echo "  - {$issue['code']}: {$issue['message']}\n";
			}
		}
	}
}
