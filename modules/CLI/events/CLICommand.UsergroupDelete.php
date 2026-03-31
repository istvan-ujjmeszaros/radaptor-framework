<?php

/**
 * Delete a usergroup interactively.
 *
 * Usage: radaptor usergroup:delete
 *
 * Shows tree and prompts for selection. Warns about child groups.
 * System usergroups are not shown (cannot be deleted via CLI).
 */
class CLICommandUsergroupDelete extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Delete usergroup';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Delete a usergroup interactively.

			Usage: radaptor usergroup:delete

			Shows tree for selection. Warns about child groups.
			System usergroups cannot be deleted via this command.
			DOC;
	}

	public function run(): void
	{
		echo "\n=== Delete Usergroup ===\n";

		// Fetch non-system usergroups only
		$nodes = DbHelper::prexecute(
			"SELECT * FROM usergroups_tree WHERE node_id > 0 AND (is_system_group = 0 OR is_system_group IS NULL) ORDER BY lft ASC"
		)?->fetchAll(PDO::FETCH_ASSOC);

		if (empty($nodes)) {
			CLIOutput::info("No deletable usergroups found (system usergroups cannot be deleted).");

			return;
		}

		echo "\nSelect usergroup to delete:\n\n";
		$nodeMap = CLIOutput::renderTree($nodes);

		$input = CLIOutput::prompt('Usergroup to delete');
		$nodeId = $nodeMap[(int) $input] ?? null;

		if ($nodeId === null) {
			CLIOutput::error("Invalid usergroup selection");

			return;
		}

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
		if (!CLIOutput::confirmDatabaseWrite("Delete usergroup \"{$data['title']}\"{$warning}")) {
			CLIOutput::info("Cancelled");

			return;
		}

		// Delete
		$result = Usergroups::deleteUsergroupRecursive($nodeId);

		if ($result['success']) {
			CLIOutput::success("Deleted {$result['usergroup']} usergroup(s)");
		} else {
			CLIOutput::error("Failed to delete usergroup");
		}
	}
}
