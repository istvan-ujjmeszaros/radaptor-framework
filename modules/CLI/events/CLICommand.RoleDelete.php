<?php

/**
 * Delete a role interactively.
 *
 * Usage: radaptor role:delete
 *
 * Shows role tree for selection. Deletes recursively (including children).
 */
class CLICommandRoleDelete extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Delete role';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Delete a role interactively.

			Usage: radaptor role:delete

			Shows role tree for selection. Deletes recursively (including children).
			DOC;
	}

	public function run(): void
	{
		echo "\n=== Delete Role ===\n";

		// Select role
		echo "\nSelect role to delete:\n";
		$nodeId = CLIOutput::promptTreeSelection('roles_tree', 'Role', false);

		if ($nodeId === null || $nodeId === 0) {
			CLIOutput::error("Invalid selection");

			return;
		}

		// Get role info
		$data = Roles::getRoleValues($nodeId);

		if (!$data) {
			CLIOutput::error("Role not found");

			return;
		}

		// Check if it has children
		$hasChildren = ($data['rgt'] - $data['lft']) > 1;
		$warning = $hasChildren ? " (and all child roles)" : "";

		// Confirm
		if (!CLIOutput::confirmDatabaseWrite("Delete role \"{$data['title']}\" ({$data['role']}){$warning}")) {
			CLIOutput::info("Cancelled");

			return;
		}

		// Delete
		$result = Roles::deleteRoleRecursive($nodeId);

		if ($result['success']) {
			$count = $result['role'];
			CLIOutput::success("Deleted {$count} role(s)");
			echo "\nRebuilding roles enum...\n";
			CLICommandBuildRoles::create();
		} else {
			CLIOutput::error("Failed to delete role");
		}
	}
}
