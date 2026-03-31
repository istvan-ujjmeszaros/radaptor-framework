<?php

/**
 * Create a new usergroup interactively.
 *
 * Usage: radaptor usergroup:create
 *
 * Prompts for:
 * - Usergroup name (title)
 * - Parent usergroup (shows tree for selection)
 */
class CLICommandUsergroupCreate extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Create usergroup';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Create a new usergroup interactively.

			Usage: radaptor usergroup:create

			Prompts for usergroup name and parent selection from tree.
			DOC;
	}

	public function run(): void
	{
		echo "\n=== Create New Usergroup ===\n";

		// Prompt for name
		$title = CLIOutput::prompt("Usergroup name");

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

		if (!CLIOutput::confirmDatabaseWrite("Create usergroup \"{$title}\" under {$parentLabel}")) {
			CLIOutput::info("Cancelled");

			return;
		}

		// Create the usergroup
		$newId = Usergroups::addUsergroup(['title' => $title], $parentId);

		if ($newId) {
			CLIOutput::success("Created usergroup \"{$title}\" (ID: {$newId})");
		} else {
			CLIOutput::error("Failed to create usergroup");
		}
	}

	private function _getUsergroupName(int $nodeId): string
	{
		$data = Usergroups::getResourceTreeEntryDataById($nodeId);

		return $data['title'] ?? "Unknown ({$nodeId})";
	}
}
