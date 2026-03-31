<?php

/**
 * Get a specific config value for a user.
 *
 * Usage: radaptor userconfig:get <username> <key> [--json]
 *
 * Examples:
 *   radaptor userconfig:get admin editmode
 *   radaptor userconfig:get admin editmode --json
 */
class CLICommandUserconfigGet extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Get user config';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Get a specific config value for a user.

			Usage: radaptor userconfig:get <username> <key> [--json]

			Examples:
			  radaptor userconfig:get admin editmode
			  radaptor userconfig:get admin editmode --json
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
			[
				'name' => 'key',
				'label' => 'Config key',
				'type' => 'extra_arg',
				'required' => true,
				'depends_on' => 'username',
				'source_command' => 'userconfig:list',
				'source_field' => 'configs{}',
				'source_args' => ['main_arg' => '$username'],
			],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		global $argv;

		$username = $argv[2] ?? null;
		$key = $argv[3] ?? null;

		if (is_null($username) || is_null($key) || str_starts_with($username, '--') || str_starts_with($key, '--')) {
			Kernel::abort("Usage: radaptor userconfig:get <username> <key> [--json]");
		}

		$user = User::getUserByName($username);

		if (is_null($user)) {
			Kernel::abort("Error: User \"{$username}\" not found");
		}

		$user_id = (int) $user['user_id'];

		$value = UserConfig::getConfig($key, $user_id);

		$json_mode = Request::hasArg('json');

		if ($json_mode) {
			echo json_encode([
				'username' => $username,
				'key' => $key,
				'value' => $value,
				'exists' => $value !== null,
			], JSON_PRETTY_PRINT) . "\n";
		} else {
			if ($value === null) {
				echo "{$key} = (not set)\n";
			} else {
				echo "{$key} = {$value}\n";
			}
		}
	}
}
