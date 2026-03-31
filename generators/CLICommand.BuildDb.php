<?php

class CLICommandBuildDb extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Build database schema';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Regenerate the database schema cache.

			Usage: radaptor build:db

			Reads table structures from both the app and test databases
			and generates the schema cache file.
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}
	public function getRiskLevel(): string
	{
		return 'build';
	}

	/**
	 * Runs the process of creating the app database schema and its testing schema, along with their triggers.
	 */
	public function run(): void
	{
		// Creating the app database schema and its testing schema, and their triggers
		self::create(
			[
				Config::DB_DEFAULT_DSN->value(),
				Db::rewriteDsnToTesting(Config::DB_DEFAULT_DSN->value()),
			]
		);
	}

	/**
	 * Builds schema data from the given DSN.
	 *
	 * @param string $dsn The Data Source Name for the database connection.
	 * @return array<string, structSQLTable> An associative array where the key is the table name and the value is a structSQLTable object containing table data.
	 */
	private static function _buildSchemaData(string $dsn): array
	{
		$stmt = Db::instance($dsn)->prepare('SHOW TABLES');
		$stmt->execute();

		$tables = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

		unset($stmt);

		$db_data = [];

		foreach ($tables as $tablename) {
			$db_data[$tablename] = GeneratorHelper::getTableDataFromDb(
				dsn: $dsn,
				tablename: $tablename,
			);
		}

		return $db_data;
	}

	/**
	 * Creates schema data files from an array of DSNs.
	 *
	 * @param array<string> $dsn_array An array of Data Source Names for the database connections.
	 * @return void
	 */
	public static function create(array $dsn_array): void
	{
		$schema_array = [];

		foreach ($dsn_array as $dsn) {
			$db_data = self::_buildSchemaData($dsn);

			// Convert structs to arrays for storage (human-readable, opcache-optimized)
			$db_data_array = [];

			foreach ($db_data as $table_name => $tableData) {
				$db_data_array[$table_name] = [
					'table_name' => $tableData->table_name,
					'fields' => array_map(fn (structSQLColumn $col) => [
						'column_name' => $col->column_name,
						'type_sql' => $col->type_sql,
						'type_php' => $col->type_php,
						'comment' => $col->comment,
						'default' => $col->default,
						'extra' => $col->extra,
						'is_optional' => $col->is_optional,
						'is_processable' => $col->is_processable,
						'is_primary_key' => $col->is_primary_key,
						'is_auto_increment' => $col->is_auto_increment,
					], $tableData->fields),
					'field_names' => $tableData->field_names,
					'pkeys' => $tableData->pkeys,
					'processable_fields' => $tableData->processable_fields,
					'is_auto_increment' => $tableData->is_auto_increment,
				];
			}

			$clean_dsn = Db::redactDSNUserAndPassword($dsn);

			$schema_array[$clean_dsn] = $db_data_array;

			PluginLifecycleManager::runAfterBuildDb($dsn, $db_data);
		}

		$schema_array_string = GeneratorHelper::formatArrayForExport($schema_array);

		$export = GeneratorHelper::fetchTemplate(
			'build_db_schema',
			[
				'schema_array_export' => $schema_array_string,
			]
		);

		$fp = fopen(DEPLOY_ROOT . ApplicationConfig::GENERATED_DB_FILE, 'w');
		$success = fwrite($fp, $export);
		fclose($fp);

		if ($success !== false) {
			echo "<b>db</b> leíró fájl sikeresen létrehozva!\n";
		} else {
			echo "<br>Hiba történt a <b>db</b> leíró fájl írásakor!\n";
		}
	}
}
