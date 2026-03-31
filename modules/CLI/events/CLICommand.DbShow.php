<?php

/**
 * Show rows from a table with optional limit and offset.
 *
 * Usage: radaptor db:show <table> [limit=N] [offset=N] [--json]
 *
 * Examples:
 *   radaptor db:show users
 *   radaptor db:show users limit=10
 *   radaptor db:show users limit=10 offset=20
 *   radaptor db:show userconfig --json
 */
class CLICommandDbShow extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show table rows';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Show rows from a table with optional limit and offset.

			Usage: radaptor db:show <table> [limit=N] [offset=N] [--json]

			Examples:
			  radaptor db:show users
			  radaptor db:show users limit=10
			  radaptor db:show users limit=10 offset=20
			  radaptor db:show userconfig --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'Table name', 'type' => 'main_arg', 'required' => true],
			['name' => 'limit', 'label' => 'Limit', 'type' => 'option'],
			['name' => 'offset', 'label' => 'Offset', 'type' => 'option'],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$table = Request::getMainArg();

		if (is_null($table)) {
			Kernel::abort("Usage: radaptor db:show <table> [--limit=N] [--offset=N] [--json]");
		}

		$limit = (int) (Request::getArg('limit') ?? 20);
		$offset = (int) (Request::getArg('offset') ?? 0);

		try {
			$sql = "SELECT * FROM `{$table}` LIMIT {$limit} OFFSET {$offset}";
			$stmt = Db::instance()->prepare($sql);
			$stmt->execute();
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

			// Get total count
			$countStmt = Db::instance()->prepare("SELECT COUNT(*) as total FROM `{$table}`");
			$countStmt->execute();
			$total = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
		} catch (PDOException $e) {
			Kernel::abort("Error: " . $e->getMessage());
		}

		$json_mode = Request::hasArg('json');

		if ($json_mode) {
			echo json_encode([
				'table' => $table,
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
				'row_count' => count($rows),
				'rows' => $rows,
			], JSON_PRETTY_PRINT) . "\n";

			return;
		}

		echo "Table: {$table} (showing {$offset}-" . ($offset + count($rows)) . " of {$total})\n\n";

		if (empty($rows)) {
			echo "No rows found.\n";

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
				$widths[$col] = max($widths[$col], min($len, 30)); // Cap at 30 chars
			}
		}

		// Print header
		foreach ($columns as $col) {
			echo str_pad(substr($col, 0, 30), $widths[$col] + 2);
		}

		echo "\n" . str_repeat("-", array_sum($widths) + count($columns) * 2) . "\n";

		// Print rows
		foreach ($rows as $row) {
			foreach ($columns as $col) {
				$val = $row[$col] ?? 'NULL';

				if (strlen($val) > 30) {
					$val = substr($val, 0, 27) . '...';
				}

				echo str_pad($val, $widths[$col] + 2);
			}

			echo "\n";
		}
	}
}
