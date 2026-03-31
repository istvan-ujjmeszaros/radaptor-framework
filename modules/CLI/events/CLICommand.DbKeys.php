<?php

/**
 * Show indexes and foreign keys for a table.
 *
 * Usage: radaptor db:keys <table> [--json]
 *
 * Examples:
 *   radaptor db:keys users
 *   radaptor db:keys userconfig --json
 */
class CLICommandDbKeys extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show table indexes and foreign keys';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Show indexes and foreign keys for a table.

			Usage: radaptor db:keys <table> [--json]

			Examples:
			  radaptor db:keys users
			  radaptor db:keys userconfig --json
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
			Kernel::abort("Usage: radaptor db:keys <table> [--json]");
		}

		try {
			// Get indexes
			$stmt = Db::instance()->prepare("SHOW INDEX FROM `{$table}`");
			$stmt->execute();
			$indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

			// Get foreign keys
			$dbName = Db::getDatabasenameFromDsn(Db::normalizeDsn());
			$fkSql = "SELECT * FROM information_schema.KEY_COLUMN_USAGE
			          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
			          AND REFERENCED_TABLE_NAME IS NOT NULL";
			$stmt = Db::instance()->prepare($fkSql);
			$stmt->execute([$dbName, $table]);
			$foreignKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			Kernel::abort("Error: " . $e->getMessage());
		}

		$json_mode = Request::hasArg('json');

		if ($json_mode) {
			echo json_encode([
				'table' => $table,
				'indexes' => $indexes,
				'foreign_keys' => $foreignKeys,
			], JSON_PRETTY_PRINT) . "\n";
		} else {
			echo "Table: {$table}\n\n";

			echo "INDEXES:\n";
			echo str_pad("Key Name", 25) . str_pad("Column", 25) . str_pad("Unique", 8) . "Seq\n";
			echo str_repeat("-", 70) . "\n";

			foreach ($indexes as $idx) {
				echo str_pad($idx['Key_name'], 25);
				echo str_pad($idx['Column_name'], 25);
				echo str_pad($idx['Non_unique'] == 0 ? 'YES' : 'NO', 8);
				echo $idx['Seq_in_index'] . "\n";
			}

			echo "\nFOREIGN KEYS:\n";

			if (empty($foreignKeys)) {
				echo "  (none)\n";
			} else {
				foreach ($foreignKeys as $fk) {
					echo "  {$fk['CONSTRAINT_NAME']}: {$fk['COLUMN_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}\n";
				}
			}
		}
	}
}
