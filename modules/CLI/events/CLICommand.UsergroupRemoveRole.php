<?php

/**
 * Remove a role from a usergroup interactively.
 *
 * Usage: radaptor usergroup:removeRole
 *
 * Shows usergroup tree, then only the assigned roles for selection.
 */
class CLICommandUsergroupRemoveRole extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Remove role from usergroup';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Remove a role from a usergroup interactively.

			Usage: radaptor usergroup:removeRole

			Shows usergroup tree, then only the assigned roles for selection.
			DOC;
	}

	public function run(): void
	{
		echo "\n=== Remove Role from Usergroup ===\n";

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

		// Get assigned roles for this usergroup
		$assignedRoles = $this->_getAssignedRoles($usergroupId);

		if (empty($assignedRoles)) {
			CLIOutput::info("No roles assigned to \"{$usergroupData['title']}\"");

			return;
		}

		// Display assigned roles
		echo "\nAssigned roles:\n";
		$roleMap = [];

		foreach ($assignedRoles as $index => $role) {
			$num = $index + 1;
			$roleMap[$num] = $role;
			echo "  " . CLIOutput::CYAN . "[{$num}]" . CLIOutput::RESET . " {$role['title']} ({$role['role']})\n";
		}

		echo "\n";
		$selection = CLIOutput::promptInt("Select role to remove");

		if ($selection === null || !isset($roleMap[$selection])) {
			CLIOutput::error("Invalid selection");

			return;
		}

		$roleData = $roleMap[$selection];

		// Confirm
		if (!CLIOutput::confirmDatabaseWrite("Remove role \"{$roleData['title']}\" from usergroup \"{$usergroupData['title']}\"")) {
			CLIOutput::info("Cancelled");

			return;
		}

		// Remove
		$result = Roles::removeFromUsergroup($roleData['role_id'], $usergroupId);

		if ($result) {
			CLIOutput::success("Removed role \"{$roleData['title']}\" from usergroup \"{$usergroupData['title']}\"");
		} else {
			CLIOutput::error("Failed to remove role");
		}
	}

	/**
	 * Get roles assigned to a usergroup.
	 *
	 * @return array<array{role_id: int, role: string, title: string}>
	 */
	private function _getAssignedRoles(int $usergroupId): array
	{
		return EntityUsergroupsRolesMapping::getRolesWithDetailsForUsergroup($usergroupId);
	}
}
