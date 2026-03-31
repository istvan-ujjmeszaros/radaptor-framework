<?php

/**
 * List all usergroups as a tree with assigned roles.
 *
 * Usage: radaptor usergroup:list [--json]
 */
class CLICommandUsergroupList extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'List usergroups';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			List all usergroups as a tree with assigned roles.

			Usage: radaptor usergroup:list [--json]

			Examples:
			  radaptor usergroup:list
			  radaptor usergroup:list --json
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
			"SELECT * FROM usergroups_tree WHERE node_id > 0 ORDER BY lft ASC"
		)?->fetchAll(PDO::FETCH_ASSOC);

		if (empty($nodes)) {
			if (Request::hasArg('json')) {
				echo json_encode(['usergroups' => []], JSON_PRETTY_PRINT) . "\n";
			} else {
				echo "No usergroups found.\n";
			}

			return;
		}

		// Fetch assigned roles for each usergroup
		$rolesMap = $this->_getRolesForUsergroups(array_column($nodes, 'node_id'));

		if (Request::hasArg('json')) {
			// Add roles to each node for JSON output
			foreach ($nodes as &$node) {
				$node['roles'] = $rolesMap[$node['node_id']] ?? [];
			}

			echo json_encode(['usergroups' => $nodes], JSON_PRETTY_PRINT) . "\n";

			return;
		}

		echo "\nUsergroups:\n";

		// Custom formatter to include roles
		$formatter = function ($node) use ($rolesMap) {
			$label = $node['title'] ?? "Node {$node['node_id']}";

			if (!empty($node['is_system_group'])) {
				$label .= CLIOutput::YELLOW . ' (system)' . CLIOutput::RESET;
			}

			$roles = $rolesMap[$node['node_id']] ?? [];

			if (!empty($roles)) {
				$roleNames = array_column($roles, 'role');
				$label .= CLIOutput::GREEN . ' {' . implode(', ', $roleNames) . '}' . CLIOutput::RESET;
			}

			return $label;
		};

		CLIOutput::renderTree($nodes, 'title', $formatter);
		echo "\n";
		echo CLIOutput::YELLOW . "(system)" . CLIOutput::RESET . " " . CLIOutput::GREEN . "{role}" . CLIOutput::RESET . "\n";
		echo "\n";
	}

	/**
	 * Get assigned roles for multiple usergroups.
	 *
	 * @param array<int> $usergroupIds
	 * @return array<int, array<array{role_id: int, role: string, title: string}>>
	 */
	private function _getRolesForUsergroups(array $usergroupIds): array
	{
		return EntityUsergroupsRolesMapping::getRolesForMultipleUsergroups($usergroupIds);
	}
}
