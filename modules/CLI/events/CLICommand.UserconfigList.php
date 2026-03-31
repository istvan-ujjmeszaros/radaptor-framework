<?php

/**
 * List all configs for a user.
 *
 * Usage: radaptor userconfig:list <username> [--json]
 *
 * Examples:
 *   radaptor userconfig:list admin
 *   radaptor userconfig:list admin --json
 */
class CLICommandUserconfigList extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'List user configs';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			List all config entries for a user.

			Usage: radaptor userconfig:list <username> [--json]

			Examples:
			  radaptor userconfig:list admin
			  radaptor userconfig:list admin --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			[
				'name' => 'username',
				'label' => 'Username',
				'type' => 'main_arg',
				'required' => true,
				'source_command' => 'user:list',
				'source_field' => 'users[].username',
			],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$username = Request::getMainArg();

		if (is_null($username)) {
			Kernel::abort("Usage: radaptor userconfig:list <username> [--json]");
		}

		$user = User::getUserByName($username);

		if (is_null($user)) {
			Kernel::abort("Error: User \"{$username}\" not found");
		}

		$user_id = (int) $user['user_id'];

		$configs = UserConfig::listConfigs($user_id);
		$json_mode = Request::hasArg('json');

		if (empty($configs)) {
			if ($json_mode) {
				echo json_encode([
					'username' => $username,
					'user_id' => $user_id,
					'configs' => [],
				], JSON_PRETTY_PRINT) . "\n";
			} else {
				echo "No configs found for user \"{$username}\"\n";
			}

			return;
		}

		if ($json_mode) {
			echo json_encode([
				'username' => $username,
				'user_id' => $user_id,
				'configs' => $configs,
			], JSON_PRETTY_PRINT) . "\n";
		} else {
			echo "User configs for \"{$username}\":\n";

			foreach ($configs as $key => $value) {
				// Truncate long values for display
				$display_value = strlen($value) > 50 ? substr($value, 0, 47) . '...' : $value;
				echo "  {$key}: {$display_value}\n";
			}
		}
	}
}
