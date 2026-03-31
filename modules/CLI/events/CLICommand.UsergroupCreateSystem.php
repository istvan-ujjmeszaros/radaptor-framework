<?php

/**
 * Create a new system usergroup interactively.
 *
 * Usage: radaptor usergroup:createSystem
 *
 * Same as usergroup:create but sets is_system_group = 1.
 * System usergroups cannot be deleted via usergroup:delete.
 */
class CLICommandUsergroupCreateSystem extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Create system usergroup';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Create a new system usergroup interactively.

			Usage: radaptor usergroup:createSystem

			Same as usergroup:create but sets is_system_group = 1.
			System usergroups cannot be deleted via usergroup:delete.
			DOC;
	}

	public function run(): void
	{
		echo "\n=== Create New System Usergroup ===\n";

		// Prompt for name
		$title = CLIOutput::prompt("System usergroup name");

		if (empty($title)) {
			CLIOutput::error("Usergroup name cannot be empty");

			return;
		}

		// Check if usergroup already exists
		$existing = Usergroups::getUsergroupByName($title);

		if ($existing) {
			CLIOutput::error("Usergroup \"{$title}\" already exists");

			return;
		}

		// Prompt for parent
		echo "\nSelect parent usergroup:\n";
		$parentId = CLIOutput::promptTreeSelection('usergroups_tree', 'Parent usergroup', true);

		if ($parentId === null) {
			CLIOutput::error("Invalid parent selection");

			return;
		}

		// Confirm
		$parentLabel = $parentId === 0 ? '(root level)' : $this->_getUsergroupName($parentId);

		if (!CLIOutput::confirmDatabaseWrite("Create system usergroup \"{$title}\" under {$parentLabel}")) {
			CLIOutput::info("Cancelled");

			return;
		}

		// Create the system usergroup
		$newId = Usergroups::addUsergroup([
			'title' => $title,
			'is_system_group' => 1,
		], $parentId);

		if ($newId) {
			CLIOutput::success("Created system usergroup \"{$title}\" (ID: {$newId})");
		} else {
			CLIOutput::error("Failed to create system usergroup");
		}
	}

	private function _getUsergroupName(int $nodeId): string
	{
		$data = Usergroups::getResourceTreeEntryDataById($nodeId);

		return $data['title'] ?? "Unknown ({$nodeId})";
	}
}
