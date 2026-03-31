<?php

/**
 * Move a usergroup to a new parent interactively.
 *
 * Usage: radaptor usergroup:move
 *
 * Shows tree twice:
 * 1. Select the usergroup to move
 * 2. Select the new parent (excluding the selected node and its children)
 */
class CLICommandUsergroupMove extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Move usergroup';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Move a usergroup to a new parent interactively.

			Usage: radaptor usergroup:move

			Shows tree twice: first to select the usergroup, then to select the new parent.
			DOC;
	}

	public function run(): void
	{
		echo "\n=== Move Usergroup ===\n";

		// First, select the usergroup to move
		echo "\nSelect usergroup to move:\n";
		$nodeId = CLIOutput::promptTreeSelection('usergroups_tree', 'Usergroup to move', false);

		if ($nodeId === null || $nodeId === 0) {
			CLIOutput::error("Invalid selection");

			return;
		}

		// Get usergroup info
		$data = Usergroups::getResourceTreeEntryDataById($nodeId);

		if (!$data) {
			CLIOutput::error("Usergroup not found");

			return;
		}

		// Check if it's a system group
		if (!empty($data['is_system_group'])) {
			CLIOutput::error("Cannot move system usergroup \"{$data['title']}\"");

			return;
		}

		echo "\n" . CLIOutput::CYAN . "Selected: " . CLIOutput::RESET . "{$data['title']}\n";

		// Now select new parent (excluding the selected node and its children)
		echo "\nSelect new parent (cannot select the moved group or its children):\n";
		$newParentId = CLIOutput::promptTreeSelection(
			'usergroups_tree',
			'New parent',
			true,
			$nodeId  // Exclude this node and its children
		);

		if ($newParentId === null) {
			CLIOutput::error("Invalid parent selection");

			return;
		}

		// Get new parent name for display
		$newParentLabel = $newParentId === 0 ? '(root level)' : $this->_getUsergroupName($newParentId);

		// Confirm
		if (!CLIOutput::confirmDatabaseWrite("Move \"{$data['title']}\" under {$newParentLabel}")) {
			CLIOutput::info("Cancelled");

			return;
		}

		// Move to the end of the new parent's children (position = 0)
		$result = Usergroups::moveToPosition($nodeId, $newParentId, 0);

		if ($result) {
			CLIOutput::success("Moved \"{$data['title']}\" under {$newParentLabel}");
		} else {
			CLIOutput::error("Failed to move usergroup");
		}
	}

	private function _getUsergroupName(int $nodeId): string
	{
		$data = Usergroups::getResourceTreeEntryDataById($nodeId);

		return $data['title'] ?? "Unknown ({$nodeId})";
	}
}
