<?php

/**
 * List all roles as a tree.
 *
 * Usage: radaptor role:list [--json]
 */
class CLICommandRoleList extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'List roles';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			List all roles as a tree.

			Usage: radaptor role:list [--json]

			Examples:
			  radaptor role:list
			  radaptor role:list --json
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
		$nodes = DbHelper::prexecute(
			"SELECT * FROM roles_tree WHERE node_id > 0 ORDER BY lft ASC"
		)?->fetchAll(PDO::FETCH_ASSOC);

		if (empty($nodes)) {
			if (Request::hasArg('json')) {
				echo json_encode(['roles' => []], JSON_PRETTY_PRINT) . "\n";
			} else {
				echo "No roles found.\n";
			}

			return;
		}

		if (Request::hasArg('json')) {
			echo json_encode(['roles' => $nodes], JSON_PRETTY_PRINT) . "\n";

			return;
		}

		echo "\nRoles:\n";

		// Custom formatter to show role identifier
		$formatter = function ($node) {
			$label = $node['title'] ?? "Node {$node['node_id']}";
			$label .= CLIOutput::CYAN . " ({$node['role']})" . CLIOutput::RESET;

			return $label;
		};

		CLIOutput::renderTree($nodes, 'title', $formatter);
		echo "\n";
	}
}
