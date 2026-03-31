<?php

/**
 * Show table schema (columns, types, defaults).
 *
 * Usage: radaptor db:schema <table> [--json]
 *
 * Examples:
 *   radaptor db:schema users
 *   radaptor db:schema userconfig --json
 */
class CLICommandDbSchema extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show table schema';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Show table schema (columns, types, defaults, keys, extras).

			Usage: radaptor db:schema <table> [--json]

			Examples:
			  radaptor db:schema users
			  radaptor db:schema userconfig --json
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
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$table = Request::getMainArg();

		if (is_null($table)) {
			Kernel::abort("Usage: radaptor db:schema <table> [--json]");
		}

		$sql = "DESCRIBE `{$table}`";

		try {
			$stmt = Db::instance()->prepare($sql);
			$stmt->execute();
			$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			Kernel::abort("Error: " . $e->getMessage());
		}

		$json_mode = Request::hasArg('json');

		if ($json_mode) {
			echo json_encode([
				'table' => $table,
				'columns' => $columns,
			], JSON_PRETTY_PRINT) . "\n";
		} else {
			echo "Table: {$table}\n\n";
			echo str_pad("Column", 30) . str_pad("Type", 25) . str_pad("Null", 6) . str_pad("Key", 5) . str_pad("Default", 15) . "Extra\n";
			echo str_repeat("-", 100) . "\n";

			foreach ($columns as $col) {
				echo str_pad($col['Field'], 30);
				echo str_pad($col['Type'], 25);
				echo str_pad($col['Null'], 6);
				echo str_pad($col['Key'], 5);
				echo str_pad($col['Default'] ?? 'NULL', 15);
				echo $col['Extra'] . "\n";
			}
		}
	}
}
