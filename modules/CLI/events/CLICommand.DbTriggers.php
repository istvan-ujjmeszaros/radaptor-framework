<?php

/**
 * Show triggers for a table.
 *
 * Usage: radaptor db:triggers <table> [--json]
 *
 * Examples:
 *   radaptor db:triggers users
 *   radaptor db:triggers userconfig --json
 */
class CLICommandDbTriggers extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show table triggers';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Show triggers for a table.

			Usage: radaptor db:triggers <table> [--json]

			Examples:
			  radaptor db:triggers users
			  radaptor db:triggers userconfig --json
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
			Kernel::abort("Usage: radaptor db:triggers <table> [--json]");
		}

		try {
			$dbName = Db::getDatabasenameFromDsn(Db::normalizeDsn());
			$sql = "SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT
			        FROM information_schema.TRIGGERS
			        WHERE EVENT_OBJECT_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?";
			$stmt = Db::instance()->prepare($sql);
			$stmt->execute([$dbName, $table]);
			$triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			Kernel::abort("Error: " . $e->getMessage());
		}

		$json_mode = Request::hasArg('json');

		if ($json_mode) {
			echo json_encode([
				'table' => $table,
				'triggers' => $triggers,
			], JSON_PRETTY_PRINT) . "\n";
		} else {
			echo "Table: {$table}\n\n";

			if (empty($triggers)) {
				echo "No triggers found.\n";

				return;
			}

			foreach ($triggers as $trigger) {
				echo "Trigger: {$trigger['TRIGGER_NAME']}\n";
				echo "  Timing: {$trigger['ACTION_TIMING']} {$trigger['EVENT_MANIPULATION']}\n";
				echo "  Statement:\n";

				// Indent the statement
				$lines = explode("\n", $trigger['ACTION_STATEMENT']);

				foreach ($lines as $line) {
					echo "    {$line}\n";
				}

				echo "\n";
			}
		}
	}
}
