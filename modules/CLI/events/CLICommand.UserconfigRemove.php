<?php

/**
 * Remove a config key for a user.
 *
 * Usage: radaptor userconfig:remove <username> <key> [--json]
 *
 * Examples:
 *   radaptor userconfig:remove admin editmode
 *   radaptor userconfig:remove admin editmode --json
 */
class CLICommandUserconfigRemove extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Remove user config';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Remove a config key for a user.

			Usage: radaptor userconfig:remove <username> <key> [--json]

			Examples:
			  radaptor userconfig:remove admin editmode
			  radaptor userconfig:remove admin editmode --json
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
			Kernel::abort("Usage: radaptor userconfig:remove <username> <key> [--json]");
		}

		$user = User::getUserByName($username);

		if (is_null($user)) {
			Kernel::abort("Error: User \"{$username}\" not found");
		}

		$user_id = (int) $user['user_id'];

		// Check if the config exists first
		$existing = UserConfig::getConfig($key, $user_id);

		if ($existing === null) {
			$json_mode = Request::hasArg('json');

			if ($json_mode) {
				echo json_encode([
					'success' => false,
					'username' => $username,
					'key' => $key,
					'error' => 'Config key not found',
				], JSON_PRETTY_PRINT) . "\n";
			} else {
				echo "Config key \"{$key}\" not found for user \"{$username}\"\n";
			}

			return;
		}

		UserConfig::removeConfig($key, $user_id);

		$json_mode = Request::hasArg('json');

		if ($json_mode) {
			echo json_encode([
				'success' => true,
				'username' => $username,
				'key' => $key,
			], JSON_PRETTY_PRINT) . "\n";
		} else {
			echo "Removed userconfig \"{$key}\" for user \"{$username}\"\n";
		}
	}
}
