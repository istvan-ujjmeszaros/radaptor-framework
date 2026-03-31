<?php

/**
 * Assign a role to a usergroup interactively.
 *
 * Usage: radaptor usergroup:addRole
 *
 * Shows usergroup tree, then role tree for selection.
 */
class CLICommandUsergroupAddRole extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Add role to usergroup';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Assign a role to a usergroup interactively.

			Usage: radaptor usergroup:addRole

			Shows usergroup tree, then role tree for selection.
			DOC;
	}

	public function run(): void
	{
		echo "\n=== Assign Role to Usergroup ===\n";

		// Select usergroup
		echo "\nSelect usergroup:\n";
		$usergroupId = CLIOutput::promptTreeSelection('usergroups_tree', 'Usergroup', false);

		if ($usergroupId === null || $usergroupId === 0) {
			CLIOutput::error("Invalid usergroup selection");

			return;
		}

		$usergroupData = Usergroups::getResourceTreeEntryDataById($usergroupId);

		if (!$usergroupData) {
			CLIOutput::error("Usergroup not found");

			return;
		}

		echo "\n" . CLIOutput::CYAN . "Selected usergroup: " . CLIOutput::RESET . "{$usergroupData['title']}\n";

		// Select role
		echo "\nSelect role to assign:\n";
		$roleId = CLIOutput::promptTreeSelection('roles_tree', 'Role', false);

		if ($roleId === null || $roleId === 0) {
			CLIOutput::error("Invalid role selection");

			return;
		}

		$roleData = Roles::getRoleValues($roleId);

		if (!$roleData) {
			CLIOutput::error("Role not found");

			return;
		}

		// Check if already assigned
		if (Roles::checkUsergroupIsAssigned($roleId, $usergroupId)) {
			CLIOutput::info("Role \"{$roleData['title']}\" is already assigned to \"{$usergroupData['title']}\"");

			return;
		}

		// Confirm
		if (!CLIOutput::confirmDatabaseWrite("Assign role \"{$roleData['title']}\" to usergroup \"{$usergroupData['title']}\"")) {
			CLIOutput::info("Cancelled");

			return;
		}

		// Assign
		$result = Roles::assignToUsergroup($roleId, $usergroupId);

		if ($result) {
			CLIOutput::success("Assigned role \"{$roleData['title']}\" to usergroup \"{$usergroupData['title']}\"");
		} else {
			CLIOutput::error("Failed to assign role");
		}
	}
}
