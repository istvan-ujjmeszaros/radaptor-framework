<?php

/**
 * Ensure a role exists and is placed under the expected parent role.
 *
 * Usage:
 *   radaptor role:ensure <role_slug> --parent <parent_role_slug> [--title "Title"] [--description "Description"]
 *
 * Examples:
 *   radaptor role:ensure acl_admin --parent system_developer --title "Weboldal hozzáférés adminisztrátor"
 *   radaptor role:ensure acl_viewer --parent system_administrator --title "Weboldal hozzáférés megtekintő"
 */
class CLICommandRoleEnsure extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Ensure role exists';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Ensure a role exists and is placed under the expected parent role.
			Creates the role if missing, or moves/updates it if it already exists.

			Usage: radaptor role:ensure <role_slug> --parent <parent_role_slug> [--title "Title"] [--description "Description"]

			Examples:
			  radaptor role:ensure acl_admin --parent system_developer --title "ACL Admin"
			  radaptor role:ensure acl_viewer --parent system_administrator
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}
	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'Role name', 'type' => 'main_arg', 'required' => true],
			['name' => 'parent', 'label' => 'Parent role slug', 'type' => 'option'],
		];
	}

	public function run(): void
	{
		$roleSlug = Request::getMainArg();

		if (is_null($roleSlug) || str_starts_with($roleSlug, '--') || str_contains($roleSlug, '=')) {
			$roleSlug = Request::getArg('role');
		}

		if (is_null($roleSlug) || trim($roleSlug) === '') {
			Kernel::abort("Usage: radaptor role:ensure <role_slug> --parent <parent_role_slug> [--title \"Title\"] [--description \"Description\"]");
		}

		$parentSlug = $this->getCliOption('parent', '');

		if ($parentSlug === '') {
			Kernel::abort("Missing required --parent <parent_role_slug>");
		}

		$title = $this->getCliOption('title', ucwords(str_replace('_', ' ', $roleSlug)));
		$description = $this->getCliOption('description', '');

		$parent = DbHelper::selectOne('roles_tree', ['role' => $parentSlug], '', 'node_id');

		if (!is_array($parent)) {
			Kernel::abort("Parent role not found: {$parentSlug}");
		}

		$parentId = (int) $parent['node_id'];
		$existing = DbHelper::selectOne('roles_tree', ['role' => $roleSlug], '', 'node_id,parent_id,title,description');

		if (!is_array($existing)) {
			$newId = Roles::addRole([
				'role' => $roleSlug,
				'title' => $title,
				'description' => $description,
			], $parentId);

			if (is_null($newId)) {
				Kernel::abort("Failed to create role: {$roleSlug}");
			}

			CLIOutput::success("Created role '{$roleSlug}' under '{$parentSlug}' (node_id={$newId})");

			return;
		}

		$roleId = (int) $existing['node_id'];
		$currentParentId = (int) $existing['parent_id'];
		$updated = false;

		if ($currentParentId !== $parentId) {
			$moved = Roles::moveToPosition($roleId, $parentId, 0);

			if (!$moved) {
				$debug = print_r(NestedSet::$debug, true);
				Kernel::abort("Failed to move role '{$roleSlug}' under '{$parentSlug}'. Debug: {$debug}");
			}

			$updated = true;
			CLIOutput::success("Moved role '{$roleSlug}' under '{$parentSlug}'");
		}

		$saveData = ['node_id' => $roleId];

		if ($existing['title'] !== $title) {
			$saveData['title'] = $title;
		}

		if ((string) $existing['description'] !== (string) $description) {
			$saveData['description'] = $description;
		}

		if (count($saveData) > 1) {
			Roles::updateRole($saveData, $roleId);
			$updated = true;
			CLIOutput::success("Updated metadata for role '{$roleSlug}'");
		}

		if (!$updated) {
			CLIOutput::info("Role '{$roleSlug}' already matches requested state.");
		}
	}

	private function getCliOption(string $name, string $default = ''): string
	{
		global $argv;

		// Prefer explicit --flag value syntax.
		foreach ($argv as $idx => $arg) {
			if ($arg === "--{$name}") {
				$value = $argv[$idx + 1] ?? null;

				return is_string($value) ? trim($value) : $default;
			}
		}

		// Support key=value syntax for backwards compatibility.
		$keyValue = Request::getArg($name);

		if (!is_null($keyValue) && trim($keyValue) !== '') {
			return trim($keyValue);
		}

		// Last fallback for older CLI internals that may inject $_GET.
		$fallback = $_GET[$name] ?? null;

		if (is_string($fallback) && trim($fallback) !== '') {
			return trim($fallback);
		}

		return $default;
	}
}
