<?php

/**
 * Create a new role interactively.
 *
 * Usage: radaptor role:create
 *
 * Prompts for:
 * - Role identifier (snake_case, used in code)
 * - Title (human-readable name)
 * - Parent selection from tree
 */
class CLICommandRoleCreate extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Create role';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Create a new role interactively.

			Usage: radaptor role:create

			Prompts for role identifier (snake_case), title, and parent selection from tree.
			DOC;
	}

	public function run(): void
	{
		echo "\n=== Create New Role ===\n\n";

		// Get role identifier
		$role = CLIOutput::prompt("Role identifier (snake_case, e.g., content_admin)");

		if (empty($role)) {
			CLIOutput::error("Role identifier is required");

			return;
		}

		// Validate role format
		if (!preg_match('/^[a-z][a-z0-9_]*$/', $role)) {
			CLIOutput::error("Role identifier must be snake_case (lowercase letters, numbers, underscores)");

			return;
		}

		// Check if role already exists
		$existing = DbHelper::selectOne('roles_tree', ['role' => $role]);

		if ($existing) {
			CLIOutput::error("Role '{$role}' already exists");

			return;
		}

		// Get title
		$title = CLIOutput::prompt("Title (human-readable)", ucwords(str_replace('_', ' ', $role)));

		if (empty($title)) {
			CLIOutput::error("Title is required");

			return;
		}

		// Select parent
		echo "\nSelect parent role (0 for root level):\n";
		$parentId = CLIOutput::promptTreeSelection('roles_tree', 'Parent', true);

		if ($parentId === null) {
			CLIOutput::error("Invalid parent selection");

			return;
		}

		$parentLabel = $parentId === 0 ? '(root level)' : $this->_getRoleName($parentId);

		// Confirm
		echo "\n";

		if (!CLIOutput::confirmDatabaseWrite("Create role \"{$title}\" ({$role}) under {$parentLabel}")) {
			CLIOutput::info("Cancelled");

			return;
		}

		// Create
		$newId = Roles::addRole([
			'role' => $role,
			'title' => $title,
			'description' => '',
		], $parentId);

		if ($newId) {
			CLIOutput::success("Created role \"{$title}\" ({$role}) with ID {$newId}");
			echo "\nRebuilding roles enum...\n";
			CLICommandBuildRoles::create();
		} else {
			CLIOutput::error("Failed to create role");
		}
	}

	private function _getRoleName(int $nodeId): string
	{
		$data = Roles::getRoleValues($nodeId);

		return $data['title'] ?? "Unknown ({$nodeId})";
	}
}
