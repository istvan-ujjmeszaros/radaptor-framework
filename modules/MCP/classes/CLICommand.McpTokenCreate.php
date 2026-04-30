<?php

declare(strict_types=1);

class CLICommandMcpTokenCreate extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Create MCP token';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Create a personal MCP token for a user.

			Usage: radaptor mcp:token-create <username|user_id> [--name <label>] [--days <days>] [--json]

			Examples:
			  radaptor mcp:token-create admin
			  radaptor mcp:token-create admin --name "Claude Desktop" --days 90 --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$target = Request::getMainArg();
		$json = Request::hasArg('json');

		if ($target === null || trim($target) === '') {
			$this->writeError('Usage: radaptor mcp:token-create <username|user_id> [--name <label>] [--days <days>] [--json]', $json);

			return;
		}

		$user = is_numeric($target)
			? User::getUserFromId((int) $target)
			: User::getUserByName($target);

		if (!is_array($user) || !isset($user['user_id'])) {
			$this->writeError("User not found: {$target}", $json);

			return;
		}

		$user_id = (int) $user['user_id'];

		$name = CLIOptionHelper::getOption('name', 'MCP token');
		$days_option = self::getDaysOption();

		if ($days_option === '' || !ctype_digit($days_option)) {
			$this->writeError('--days must be 0 or a positive integer.', $json);

			return;
		}

		$days = (int) $days_option;
		$days = min(3650, $days);

		try {
			$result = McpTokenService::createToken($user_id, (string) $name, $days, User::getCurrentUserId() > 0 ? User::getCurrentUserId() : null);
		} catch (Throwable $exception) {
			$this->writeError($exception->getMessage(), $json);

			return;
		}

		$output = [
			'status' => 'success',
			'user_id' => $user_id,
			'token_id' => $result['token_id'],
			'prefix' => $result['prefix'],
			'expires_at' => $result['expires_at'],
			'token' => $result['token'],
		];

		if ($json) {
			echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		echo "MCP token created for user {$target}.\n";
		echo "Prefix: {$result['prefix']}\n";
		echo "Expires at: " . ($result['expires_at'] ?? 'never') . "\n";
		echo "Token: {$result['token']}\n";
		echo "The full token is shown only once.\n";
	}

	private function writeError(string $message, bool $json): void
	{
		if ($json) {
			echo json_encode(['status' => 'error', 'message' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		echo "MCP token create failed: {$message}\n";
	}

	private static function getDaysOption(): string
	{
		global $argv;

		foreach ($argv ?? [] as $index => $arg) {
			if ($arg !== '--days') {
				continue;
			}

			$value = $argv[$index + 1] ?? null;

			return is_string($value) && !str_starts_with($value, '--') ? trim($value) : '';
		}

		return CLIOptionHelper::getOption('days', (string) McpTokenService::DEFAULT_EXPIRY_DAYS);
	}
}
