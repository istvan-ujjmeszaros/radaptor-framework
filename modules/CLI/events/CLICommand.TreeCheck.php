<?php

class CLICommandTreeCheck extends AbstractCLICommand
{
	private const array TREE_TABLES = [
		'resource' => 'resource_tree',
		'roles' => 'roles_tree',
		'usergroups' => 'usergroups_tree',
		'mainmenu' => 'mainmenu_tree',
		'adminmenu' => 'adminmenu_tree',
	];

	public function getName(): string
	{
		return 'Check nested tree consistency';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Check nested-set consistency across one or more known tree tables.

			Usage: radaptor tree:check [--tree all|resource|roles|usergroups|mainmenu|adminmenu] [--json]

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
		return [
			['name' => 'tree', 'label' => 'Tree', 'type' => 'option', 'required' => false, 'choices' => ['all' => 'all', 'resource' => 'resource', 'roles' => 'roles', 'usergroups' => 'usergroups', 'mainmenu' => 'mainmenu', 'adminmenu' => 'adminmenu']],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$tree = CLIOptionHelper::getOption('tree', 'all');
		$json = CLIOptionHelper::isJson();

		try {
			$reports = [];
			$selected = $tree === 'all' ? array_keys(self::TREE_TABLES) : [$tree];

			foreach ($selected as $tree_key) {
				if (!isset(self::TREE_TABLES[$tree_key])) {
					throw new InvalidArgumentException("Unknown tree: {$tree_key}");
				}

				$reports[$tree_key] = NestedSet::analyzeConsistency(self::TREE_TABLES[$tree_key]);
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
