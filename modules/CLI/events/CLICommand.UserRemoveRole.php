<?php

/**
 * Remove a role from a user interactively.
 *
 * Usage: radaptor user:removeRole
 *
 * Shows user list, then only the directly assigned roles for selection.
 */
class CLICommandUserRemoveRole extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Remove role from user';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Remove a role from a user interactively.

			Usage: radaptor user:removeRole

			Shows user list, then directly assigned roles for selection. Not available via web runner.
			DOC;
	}

	public function run(): void
	{
		echo "\n=== Remove Role from User ===\n";

		// Select user
		$userId = $this->_promptUserSelection();

		if ($userId === null) {
			return;
		}

		$userData = User::getUserFromId($userId);
		echo "\n" . CLIOutput::CYAN . "Selected user: " . CLIOutput::RESET . "{$userData['username']}\n";

		// Get directly assigned roles for this user
		$assignedRoles = $this->_getAssignedRoles($userId);

		if (empty($assignedRoles)) {
			CLIOutput::info("No roles directly assigned to \"{$userData['username']}\"");

			return;
		}

		// Display assigned roles
		echo "\nDirectly assigned roles:\n";
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
		if (!CLIOutput::confirmDatabaseWrite("Remove role \"{$roleData['title']}\" from user \"{$userData['username']}\"")) {
			CLIOutput::info("Cancelled");

			return;
		}

		// Remove
		$result = Roles::removeFromUser($roleData['role_id'], $userId);

		if ($result) {
			CLIOutput::success("Removed role \"{$roleData['title']}\" from user \"{$userData['username']}\"");
		} else {
			CLIOutput::error("Failed to remove role");
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

	/**
	 * Get roles directly assigned to a user.
	 *
	 * @return array<array{role_id: int, role: string, title: string}>
	 */
	private function _getAssignedRoles(int $userId): array
	{
		return EntityUsersRolesMapping::getRolesWithDetailsForUser($userId);
	}
}
