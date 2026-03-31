<?php

/**
 * Set a config value for a user.
 *
 * Usage: radaptor userconfig:set <username> <key> <value> [--json]
 *
 * Examples:
 *   radaptor userconfig:set admin editmode 0
 *   radaptor userconfig:set admin editmode 1 --json
 */
class CLICommandUserconfigSet extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Set user config';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Set a config value for a user.

			Usage: radaptor userconfig:set <username> <key> <value> [--json]

			Examples:
			  radaptor userconfig:set admin editmode 0
			  radaptor userconfig:set admin editmode 1 --json
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
			[
				'name' => 'value',
				'label' => 'Config value',
				'type' => 'extra_arg',
				'required' => true,
				'prefill_from' => 'key',
			],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		global $argv;

		$username = $argv[2] ?? null;
		$key = $argv[3] ?? null;
		$value = $argv[4] ?? null;

		if (is_null($username) || is_null($key) || is_null($value)
			|| str_starts_with($username, '--') || str_starts_with($key, '--') || str_starts_with($value, '--')) {
			Kernel::abort("Usage: radaptor userconfig:set <username> <key> <value> [--json]");
		}

		$user = User::getUserByName($username);

		if (is_null($user)) {
			Kernel::abort("Error: User \"{$username}\" not found");
		}

		$user_id = (int) $user['user_id'];

		UserConfig::setConfig($key, $value, $user_id);

		$json_mode = Request::hasArg('json');

		if ($json_mode) {
			echo json_encode([
				'success' => true,
				'username' => $username,
				'key' => $key,
				'value' => $value,
			], JSON_PRETTY_PRINT) . "\n";
		} else {
			echo "Set userconfig \"{$key}\" = \"{$value}\" for user \"{$username}\"\n";
		}
	}
}
