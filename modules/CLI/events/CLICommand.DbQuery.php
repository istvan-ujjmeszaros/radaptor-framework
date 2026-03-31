<?php

/**
 * Run a SELECT query and display results.
 *
 * Usage: radaptor db:query "<SQL>" [--json]
 *
 * Examples:
 *   radaptor db:query "SELECT user_id, username FROM users LIMIT 5"
 *   radaptor db:query "SELECT * FROM userconfig WHERE user_id = 1" --json
 */
class CLICommandDbQuery extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Run a read-only SQL query';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Run a SELECT query and display results. Only SELECT, SHOW, and DESCRIBE queries are allowed.

			Usage: radaptor db:query "<SQL>" [--json]

			Examples:
			  radaptor db:query "SELECT user_id, username FROM users LIMIT 5"
			  radaptor db:query "SELECT * FROM userconfig WHERE user_id = 1" --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'SQL query', 'type' => 'main_arg', 'required' => true],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$sql = Request::getMainArg();

		if (is_null($sql)) {
			Kernel::abort("Usage: radaptor db:query \"<SQL>\" [--json]");
		}

		// Only allow SELECT queries for safety
		$trimmed = trim(strtoupper($sql));

		if (!str_starts_with($trimmed, 'SELECT') && !str_starts_with($trimmed, 'SHOW') && !str_starts_with($trimmed, 'DESCRIBE')) {
			Kernel::abort("Error: Only SELECT, SHOW, and DESCRIBE queries are allowed");
		}

		try {
			$stmt = Db::instance()->prepare($sql);
			$stmt->execute();
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			Kernel::abort("Error: " . $e->getMessage());
		}

		$json_mode = Request::hasArg('json');

		if ($json_mode) {
			echo json_encode([
				'query' => $sql,
				'row_count' => count($rows),
				'rows' => $rows,
			], JSON_PRETTY_PRINT) . "\n";
		} else {
			if (empty($rows)) {
				echo "No results.\n";

				return;
			}

			// Get column names from first row
			$columns = array_keys($rows[0]);

			// Calculate column widths
			$widths = [];

			foreach ($columns as $col) {
				$widths[$col] = strlen($col);
			}

			foreach ($rows as $row) {
				foreach ($columns as $col) {
					$len = strlen((string) ($row[$col] ?? 'NULL'));
					$widths[$col] = max($widths[$col], min($len, 40)); // Cap at 40 chars
				}
			}

			// Print header
			foreach ($columns as $col) {
				echo str_pad($col, $widths[$col] + 2);
			}

			echo "\n" . str_repeat("-", array_sum($widths) + count($columns) * 2) . "\n";

			// Print rows
			foreach ($rows as $row) {
				foreach ($columns as $col) {
					$val = $row[$col] ?? 'NULL';

					if (strlen($val) > 40) {
						$val = substr($val, 0, 37) . '...';
					}

					echo str_pad($val, $widths[$col] + 2);
				}

				echo "\n";
			}

			echo "\n" . count($rows) . " row(s)\n";
		}
	}
}
