<?php

/**
 * List all users.
 *
 * Usage: radaptor user:list [--json]
 */
class CLICommandUserList extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'List users';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			List all users with their usergroups and direct roles.

			Usage: radaptor user:list [--json]

			Examples:
			  radaptor user:list
			  radaptor user:list --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$users = User::getUserList();

		if (empty($users)) {
			if (Request::hasArg('json')) {
				echo json_encode(['users' => []], JSON_PRETTY_PRINT) . "\n";
			} else {
				echo "No users found.\n";
			}

			return;
		}

		if (Request::hasArg('json')) {
			// Remove password hashes from JSON output
			$sanitized = array_map(function ($user) {
				unset($user['password']);

				return $user;
			}, $users);

			echo json_encode(['users' => $sanitized], JSON_PRETTY_PRINT) . "\n";

			return;
		}

		echo "\nUsers:\n";

		foreach ($users as $index => $user) {
			$num = $index + 1;
			$username = $user['username'];
			$userId = $user['user_id'];

			// Get usergroups for this user
			$usergroups = $this->_getUsergroupsForUser($userId);
			$usergroupStr = !empty($usergroups) ? CLIOutput::CYAN . ' [' . implode(', ', $usergroups) . ']' . CLIOutput::RESET : '';

			// Get direct roles for this user
			$roles = $this->_getDirectRolesForUser($userId);
			$roleStr = !empty($roles) ? CLIOutput::GREEN . ' {' . implode(', ', $roles) . '}' . CLIOutput::RESET : '';

			echo "  " . CLIOutput::CYAN . "[{$num}]" . CLIOutput::RESET . " {$username} (ID: {$userId}){$usergroupStr}{$roleStr}\n";
		}

		echo "\n";
		echo CLIOutput::CYAN . "[usergroup]" . CLIOutput::RESET . " " . CLIOutput::GREEN . "{direct role}" . CLIOutput::RESET . "\n";
		echo "\n";
	}

	/**
	 * Get usergroup names for a user.
	 *
	 * @return array<string>
	 */
	private function _getUsergroupsForUser(int $userId): array
	{
		$query = "
			SELECT ugt.title
			FROM users_usergroups_mapping uum
			JOIN usergroups_tree ugt ON ugt.node_id = uum.usergroup_id
			WHERE uum.user_id = ?
			ORDER BY ugt.title
		";

		$rows = DbHelper::prexecute($query, [$userId])?->fetchAll(PDO::FETCH_COLUMN) ?? [];

		return $rows;
	}

	/**
	 * Get direct role names for a user (not via usergroups).
	 *
	 * @return array<string>
	 */
	private function _getDirectRolesForUser(int $userId): array
	{
		$query = "
			SELECT rt.role
			FROM users_roles_mapping urm
			JOIN roles_tree rt ON rt.node_id = urm.role_id
			WHERE urm.user_id = ?
			ORDER BY rt.title
		";

		$rows = DbHelper::prexecute($query, [$userId])?->fetchAll(PDO::FETCH_COLUMN) ?? [];

		return $rows;
	}
}
