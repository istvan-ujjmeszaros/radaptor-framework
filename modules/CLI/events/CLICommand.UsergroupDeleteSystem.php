<?php

/**
 * Delete a system usergroup interactively.
 *
 * Usage: radaptor usergroup:deleteSystem
 *
 * Shows only system usergroups (is_system_group = 1) as a flat list.
 * Allows deletion of system usergroups that cannot be deleted via usergroup:delete.
 */
class CLICommandUsergroupDeleteSystem extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Delete system usergroup';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Delete a system usergroup interactively.

			Usage: radaptor usergroup:deleteSystem

			Shows only system usergroups (is_system_group = 1) for selection.
			Allows deletion of system usergroups that cannot be deleted via usergroup:delete.
			DOC;
	}

	public function run(): void
	{
		echo "\n=== Delete System Usergroup ===\n";

		// Fetch system usergroups only
		$nodes = DbHelper::prexecute(
			"SELECT node_id, title, lft, rgt FROM usergroups_tree WHERE node_id > 0 AND is_system_group = 1 ORDER BY title ASC"
		)?->fetchAll(PDO::FETCH_ASSOC);

		if (empty($nodes)) {
			CLIOutput::info("No system usergroups found.");

			return;
		}

		echo "\nSystem usergroups:\n";
		$nodeMap = [];
		$index = 1;

		foreach ($nodes as $node) {
			$nodeMap[$index] = $node['node_id'];
			echo "  " . CLIOutput::CYAN . "[{$index}]" . CLIOutput::RESET . " {$node['title']} (ID: {$node['node_id']})\n";
			$index++;
		}

		echo "\n";
		$selection = CLIOutput::promptInt("Select system usergroup to delete");

		if ($selection === null || !isset($nodeMap[$selection])) {
			CLIOutput::error("Invalid selection");

			return;
		}

		$nodeId = $nodeMap[$selection];

		// Get usergroup info
		$data = Usergroups::getResourceTreeEntryDataById($nodeId);

		if (!$data) {
			CLIOutput::error("Usergroup not found");

			return;
		}

		// Check for children
		$hasChildren = ($data['rgt'] - $data['lft']) > 1;
		$warning = '';

		if ($hasChildren) {
			$warning = " (WARNING: This will also delete all child groups!)";
		}

		// Confirm
		if (!CLIOutput::confirmDatabaseWrite("Delete system usergroup \"{$data['title']}\"{$warning}")) {
			CLIOutput::info("Cancelled");

			return;
		}

		// Delete
		$result = Usergroups::deleteUsergroupRecursive($nodeId);

		if ($result['success']) {
			CLIOutput::success("Deleted {$result['usergroup']} usergroup(s)");
		} else {
			CLIOutput::error("Failed to delete system usergroup");
		}
	}
}
