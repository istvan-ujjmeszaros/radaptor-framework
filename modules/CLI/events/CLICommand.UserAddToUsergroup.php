<?php

/**
 * Add a user to a usergroup interactively.
 *
 * Usage: radaptor user:addToUsergroup
 *
 * Shows user list, then usergroup tree for selection.
 */
class CLICommandUserAddToUsergroup extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Add user to usergroup';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Add a user to a usergroup interactively.

			Usage: radaptor user:addToUsergroup

			Shows user list, then usergroup tree for selection. Not available via web runner.
			DOC;
	}

	public function run(): void
	{
		echo "\n=== Add User to Usergroup ===\n";

		// Select user
		$userId = $this->_promptUserSelection();

		if ($userId === null) {
			return;
		}

		$userData = User::getUserFromId($userId);
		echo "\n" . CLIOutput::CYAN . "Selected user: " . CLIOutput::RESET . "{$userData['username']}\n";

		// Select usergroup
		echo "\nSelect usergroup to add user to:\n";
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

		// Check if already assigned
		if (Usergroups::checkUserIsAssigned($usergroupId, $userId)) {
			CLIOutput::info("User \"{$userData['username']}\" is already in \"{$usergroupData['title']}\"");

			return;
		}

		// Confirm
		if (!CLIOutput::confirmDatabaseWrite("Add user \"{$userData['username']}\" to usergroup \"{$usergroupData['title']}\"")) {
			CLIOutput::info("Cancelled");

			return;
		}

		// Add to usergroup
		$result = Usergroups::assignToUser($usergroupId, $userId);

		if ($result) {
			CLIOutput::success("Added user \"{$userData['username']}\" to usergroup \"{$usergroupData['title']}\"");
		} else {
			CLIOutput::error("Failed to add user to usergroup");
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
