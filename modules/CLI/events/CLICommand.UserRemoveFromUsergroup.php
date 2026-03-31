<?php

/**
 * Remove a user from a usergroup interactively.
 *
 * Usage: radaptor user:removeFromUsergroup
 *
 * Shows user list, then usergroup tree for selection.
 */
class CLICommandUserRemoveFromUsergroup extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Remove user from usergroup';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Remove a user from a usergroup interactively.

			Usage: radaptor user:removeFromUsergroup

			Shows user list, then assigned usergroups for selection. Not available via web runner.
			DOC;
	}

	public function run(): void
	{
		echo "\n=== Remove User from Usergroup ===\n";

		// Select user
		$userId = $this->_promptUserSelection();

		if ($userId === null) {
			return;
		}

		$userData = User::getUserFromId($userId);
		echo "\n" . CLIOutput::CYAN . "Selected user: " . CLIOutput::RESET . "{$userData['username']}\n";

		// Get user's assigned usergroups
		$mappings = EntityUsersUsergroupsMapping::findByUserId($userId);

		if (empty($mappings)) {
			CLIOutput::info("User \"{$userData['username']}\" is not assigned to any usergroups");

			return;
		}

		// Build list of usergroups with details
		echo "\nUsergroups assigned to this user:\n";
		$usergroupMap = [];
		$index = 1;

		foreach ($mappings as $mapping) {
			$ugData = Usergroups::getResourceTreeEntryDataById($mapping->usergroup_id);

			if ($ugData) {
				$usergroupMap[$index] = [
					'id' => $mapping->usergroup_id,
					'title' => $ugData['title'],
					'is_system' => $ugData['is_system_group'],
				];
				$systemTag = $ugData['is_system_group'] ? CLIOutput::YELLOW . ' [system]' . CLIOutput::RESET : '';
				echo "  " . CLIOutput::CYAN . "[{$index}]" . CLIOutput::RESET . " {$ugData['title']}{$systemTag}\n";
				$index++;
			}
		}

		echo "\n";
		$selection = CLIOutput::promptInt("Select usergroup to remove");

		if ($selection === null || !isset($usergroupMap[$selection])) {
			CLIOutput::error("Invalid selection");

			return;
		}

		$usergroupId = $usergroupMap[$selection]['id'];
		$usergroupData = ['title' => $usergroupMap[$selection]['title']];

		// Confirm
		if (!CLIOutput::confirmDatabaseWrite("Remove user \"{$userData['username']}\" from usergroup \"{$usergroupData['title']}\"")) {
			CLIOutput::info("Cancelled");

			return;
		}

		// Remove from usergroup
		$result = Usergroups::removeFromUser($usergroupId, $userId);

		if ($result) {
			CLIOutput::success("Removed user \"{$userData['username']}\" from usergroup \"{$usergroupData['title']}\"");
		} else {
			CLIOutput::error("Failed to remove user from usergroup");
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
