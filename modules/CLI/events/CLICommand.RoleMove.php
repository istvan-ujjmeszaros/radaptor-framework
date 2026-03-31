<?php

/**
 * Move a role to a new parent interactively.
 *
 * Usage: radaptor role:move
 *
 * Shows tree twice:
 * 1. Select the role to move
 * 2. Select the new parent (excluding the selected node and its children)
 */
class CLICommandRoleMove extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Move role';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Move a role to a new parent interactively.

			Usage: radaptor role:move

			Shows tree twice: first to select the role, then to select the new parent.
			DOC;
	}

	public function run(): void
	{
		echo "\n=== Move Role ===\n";

		// First, select the role to move
		echo "\nSelect role to move:\n";
		$nodeId = CLIOutput::promptTreeSelection('roles_tree', 'Role to move', false);

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

		echo "\n" . CLIOutput::CYAN . "Selected: " . CLIOutput::RESET . "{$data['title']} ({$data['role']})\n";

		// Now select new parent (excluding the selected node and its children)
		echo "\nSelect new parent (cannot select the moved role or its children):\n";
		$newParentId = CLIOutput::promptTreeSelection(
			'roles_tree',
			'New parent',
			true,
			$nodeId  // Exclude this node and its children
		);

		if ($newParentId === null) {
			CLIOutput::error("Invalid parent selection");

			return;
		}

		// Get new parent name for display
		$newParentLabel = $newParentId === 0 ? '(root level)' : $this->_getRoleName($newParentId);

		// Confirm
		if (!CLIOutput::confirmDatabaseWrite("Move \"{$data['title']}\" under {$newParentLabel}")) {
			CLIOutput::info("Cancelled");

			return;
		}

		// Move to the end of the new parent's children (position = 0)
		$result = Roles::moveToPosition($nodeId, $newParentId, 0);

		if ($result) {
			CLIOutput::success("Moved \"{$data['title']}\" under {$newParentLabel}");
		} else {
			CLIOutput::error("Failed to move role");
		}
	}

	private function _getRoleName(int $nodeId): string
	{
		$data = Roles::getRoleValues($nodeId);

		return $data['title'] ?? "Unknown ({$nodeId})";
	}
}
