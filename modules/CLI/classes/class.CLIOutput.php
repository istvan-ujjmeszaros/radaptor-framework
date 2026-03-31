<?php

/**
 * CLI output helper class with color support and confirmation prompts.
 *
 * Provides:
 * - Color-coded database write confirmations
 * - Hidden password input
 * - Success/error/info message helpers
 */
class CLIOutput
{
	// ANSI color codes
	public const string RESET = "\033[0m";
	public const string RED_BG = "\033[41m";      // Red background
	public const string YELLOW_BG = "\033[43m";   // Yellow background
	public const string WHITE = "\033[97m";       // Bright white text
	public const string BLACK = "\033[30m";       // Black text
	public const string BOLD = "\033[1m";
	public const string GREEN = "\033[32m";
	public const string RED = "\033[31m";
	public const string CYAN = "\033[36m";
	public const string YELLOW = "\033[33m";

	/**
	 * Confirm database write operation with color-coded prompt.
	 * Uses readline() with default value [yes] - pressing Enter confirms.
	 *
	 * @param string $action Description of the action to be performed
	 * @return bool True if confirmed, false if aborted
	 */
	public static function confirmDatabaseWrite(string $action): bool
	{
		self::showStatus();
		echo "  Action:   {$action}\n\n";

		// Use readline with default [yes] - same pattern as entity:create
		$input = readline("Confirm? (yes/no) [yes]: ");

		// Empty input (Enter) or 'yes' = confirmed
		return strtolower($input) === 'yes' || $input === '';
	}

	/**
	 * Prompt for hidden password input (not shown on screen).
	 * Uses stty -echo pattern from Event.UserLogin.php.
	 *
	 * @param string $prompt The prompt to display
	 * @return string The password entered
	 */
	public static function promptPassword(string $prompt = "Enter password: "): string
	{
		echo $prompt;
		system('stty -echo');
		$password = trim(fgets(STDIN));
		system('stty echo');
		echo "\n";

		return $password;
	}

	/**
	 * Display a success message in green.
	 *
	 * @param string $message The message to display
	 */
	public static function success(string $message): void
	{
		echo self::GREEN . "\u{2713} {$message}" . self::RESET . "\n";
	}

	/**
	 * Display an error message in red.
	 *
	 * @param string $message The message to display
	 */
	public static function error(string $message): void
	{
		echo self::RED . "\u{2717} {$message}" . self::RESET . "\n";
	}

	/**
	 * Display an info message in cyan.
	 *
	 * @param string $message The message to display
	 */
	public static function info(string $message): void
	{
		echo self::CYAN . "\u{2139} {$message}" . self::RESET . "\n";
	}

	/**
	 * Display CLI status: user and database info with color coding.
	 * Centralized method for consistent status display.
	 * Includes newline before and after for consistent spacing.
	 */
	public static function showStatus(): void
	{
		$currentUser = User::getCurrentUser();
		$mode = Db::getCLIDatabaseMode();
		$dbName = Db::getDatabasenameFromDsn(Db::normalizeDsn());

		echo "\nCLI Status:\n";
		echo "  User:     " . self::_formatUser($currentUser) . "\n";
		echo "  Database: " . self::_formatDatabase($mode, $dbName) . "\n";
	}

	/**
	 * Format user display with color coding.
	 *
	 * @param array|null $currentUser The current user array or null
	 * @return string Formatted user string
	 */
	private static function _formatUser(?array $currentUser): string
	{
		if ($currentUser) {
			return "{$currentUser['username']} (logged in)";
		}

		return self::YELLOW_BG . self::BLACK . self::BOLD . ' (not logged in) ' . self::RESET;
	}

	/**
	 * Format database display with color coding.
	 *
	 * @param string $mode The current database mode ('test' or 'normal')
	 * @param string $dbName The database name
	 * @return string Formatted database string
	 */
	private static function _formatDatabase(string $mode, string $dbName): string
	{
		if ($mode === 'test') {
			$modeLabel = 'TEST MODE';
			$colorStart = self::YELLOW_BG . self::BLACK . self::BOLD;
		} else {
			$modeLabel = 'PRODUCTION';
			$colorStart = self::RED_BG . self::WHITE . self::BOLD;
		}

		return "{$colorStart} {$dbName} [{$modeLabel}] " . self::RESET;
	}

	/**
	 * Prompt for text input with optional default value.
	 *
	 * @param string $message The prompt message
	 * @param string|null $default Optional default value (shown in brackets)
	 * @return string The user input or default value
	 */
	public static function prompt(string $message, ?string $default = null): string
	{
		$promptText = $default !== null ? "{$message} [{$default}]: " : "{$message}: ";
		$input = readline($promptText);

		if ($input === '' && $default !== null) {
			return $default;
		}

		return $input;
	}

	/**
	 * Prompt for integer input with optional default value.
	 *
	 * @param string $message The prompt message
	 * @param int|null $default Optional default value
	 * @return int|null The user input as integer, or null if invalid
	 */
	public static function promptInt(string $message, ?int $default = null): ?int
	{
		$input = self::prompt($message, $default !== null ? (string)$default : null);

		if ($input === '' && $default !== null) {
			return $default;
		}

		if (!ctype_digit($input) && $input !== '0') {
			return null;
		}

		return (int)$input;
	}

	/**
	 * Render a nested set tree with indentation.
	 *
	 * @param array<int, array{node_id: int, title: string, lft: int, rgt: int, is_system_group?: bool}> $nodes Flat array of nodes with lft/rgt values
	 * @param string $titleKey The key to use for display (default: 'title')
	 * @param callable|null $formatter Optional formatter function(node): string
	 * @return array<int, int> Map of display number => node_id for selection
	 */
	public static function renderTree(array $nodes, string $titleKey = 'title', ?callable $formatter = null): array
	{
		$nodeMap = [];
		$displayNum = 1;

		// Build parent-child relationships from lft/rgt
		$stack = [];

		foreach ($nodes as $node) {
			// Pop nodes that are "closed" (current node's lft is > their rgt)
			while (!empty($stack) && $node['lft'] > $stack[count($stack) - 1]['rgt']) {
				array_pop($stack);
			}

			$depth = count($stack);
			$indent = str_repeat('  ', $depth);

			if ($formatter !== null) {
				$label = $formatter($node);
			} else {
				$label = $node[$titleKey] ?? "Node {$node['node_id']}";

				if (!empty($node['is_system_group'])) {
					$label .= self::YELLOW . ' (system)' . self::RESET;
				}
			}

			echo "{$indent}" . self::CYAN . "[{$displayNum}]" . self::RESET . " {$label}\n";

			$nodeMap[$displayNum] = $node['node_id'];
			$displayNum++;

			// Push this node onto the stack (it may have children)
			$stack[] = $node;
		}

		return $nodeMap;
	}

	/**
	 * Prompt user to select from a tree structure.
	 *
	 * @param string $tableName The nested set table name (usergroups_tree, roles_tree)
	 * @param string $prompt The prompt message
	 * @param bool $allowRoot Whether to allow selecting root (0)
	 * @param int|null $excludeNodeId Optional node ID to exclude from selection (for move operations)
	 * @return int|null The selected node_id, 0 for root, or null if cancelled
	 */
	public static function promptTreeSelection(
		string $tableName,
		string $prompt,
		bool $allowRoot = true,
		?int $excludeNodeId = null
	): ?int {
		// Fetch all nodes ordered by lft for proper tree rendering
		$nodes = DbHelper::prexecute(
			"SELECT * FROM {$tableName} WHERE node_id > 0 ORDER BY lft ASC"
		)?->fetchAll(PDO::FETCH_ASSOC);

		if (empty($nodes)) {
			echo "No items found.\n";

			return $allowRoot ? 0 : null;
		}

		// Filter out excluded node and its children
		if ($excludeNodeId !== null) {
			$excludeNode = null;

			foreach ($nodes as $node) {
				if ($node['node_id'] === $excludeNodeId) {
					$excludeNode = $node;

					break;
				}
			}

			if ($excludeNode !== null) {
				$nodes = array_filter($nodes, function ($node) use ($excludeNode) {
					return !($node['lft'] >= $excludeNode['lft'] && $node['rgt'] <= $excludeNode['rgt']);
				});
				$nodes = array_values($nodes);
			}
		}

		echo "\n";
		$nodeMap = self::renderTree($nodes);

		if ($allowRoot) {
			echo "\n" . self::CYAN . "[0]" . self::RESET . " (root level)\n";
		}

		echo "\n";
		$selection = self::promptInt($prompt, $allowRoot ? 0 : null);

		if ($selection === null) {
			return null;
		}

		if ($selection === 0) {
			return $allowRoot ? 0 : null;
		}

		if (!isset($nodeMap[$selection])) {
			self::error("Invalid selection: {$selection}");

			return null;
		}

		return $nodeMap[$selection];
	}
}
