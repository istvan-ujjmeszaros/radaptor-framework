<?php

/**
 * Add a role directly to a user interactively.
 *
 * Usage: radaptor user:addRole
 *
 * Shows user list, then role tree for selection.
 * Note: Roles can also be assigned via usergroups (usergroup:addRole).
 */
class CLICommandUserAddRole extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Add role to user';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Add a role directly to a user interactively.

			Usage: radaptor user:addRole

			Shows user list, then role tree for selection. Not available via web runner.
			DOC;
	}

	public function run(): void
	{
		echo "\n=== Add Role to User ===\n";

		// Select user
		$userId = $this->_promptUserSelection();

		if ($userId === null) {
			return;
		}

		$userData = User::getUserFromId($userId);
		echo "\n" . CLIOutput::CYAN . "Selected user: " . CLIOutput::RESET . "{$userData['username']}\n";

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
		if (Roles::checkUserIsAssigned($roleId, $userId)) {
			CLIOutput::info("User \"{$userData['username']}\" already has role \"{$roleData['title']}\"");

			return;
		}

		// Confirm
		if (!CLIOutput::confirmDatabaseWrite("Assign role \"{$roleData['title']}\" to user \"{$userData['username']}\"")) {
			CLIOutput::info("Cancelled");

			return;
		}

		// Assign
		$result = Roles::assignToUser($roleId, $userId);

		if ($result) {
			CLIOutput::success("Assigned role \"{$roleData['title']}\" to user \"{$userData['username']}\"");
		} else {
			CLIOutput::error("Failed to assign role");
		}
	}

	/**
	 * Prompt user selection from list.
	 *
	 * @return int|null Selected user_id or null if cancelled
	 */
	private function _promptUserSelection(): ?int
	{
		$users = User::getUserList();

		if (empty($users)) {
			CLIOutput::error("No users found");

			return null;
		}

		echo "\nUsers:\n";
		$userMap = [];

		foreach ($users as $index => $user) {
			$num = $index + 1;
			$userMap[$num] = $user['user_id'];
			echo "  " . CLIOutput::CYAN . "[{$num}]" . CLIOutput::RESET . " {$user['username']}\n";
		}

		echo "\n";
		$selection = CLIOutput::promptInt("Select user");

		if ($selection === null || !isset($userMap[$selection])) {
			CLIOutput::error("Invalid selection");

			return null;
		}

		return $userMap[$selection];
	}
}
