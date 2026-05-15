<?php

class DbSchemaDataBuilder
{
	/**
	 * @return array<string, structSQLTable>
	 */
	public static function buildSchemaDataForDsn(string $dsn): array
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
	 * @param array<string> $dsn_array
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function buildSchemaArray(array $dsn_array, bool $run_plugin_hooks = false): array
	{
		$schema_array = [];

		foreach ($dsn_array as $dsn) {
			$db_data = self::buildSchemaDataForDsn($dsn);
			$clean_dsn = Db::redactDSNUserAndPassword($dsn);
			$schema_array[$clean_dsn] = self::convertStructsToArrays($db_data);

			if ($run_plugin_hooks) {
				PluginLifecycleManager::runAfterBuildDb($dsn, $db_data);
			}
		}

		return $schema_array;
	}

	/**
	 * @param array<string, structSQLTable> $db_data
	 * @return array<string, array<string, mixed>>
	 */
	private static function convertStructsToArrays(array $db_data): array
	{
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

		return $db_data_array;
	}
}
