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

			Usage: radaptor build:db [--auto-sync]

			Reads table structures from both the app and test databases
			and generates the schema cache file. By default this command
			aborts when the test schema drifts from dev; run
			`radaptor db:test-sync --json` first or pass --auto-sync.
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
		$auto_sync = Request::hasArg('auto-sync');
		$sync = TestDatabaseSchemaSyncService::sync(!$auto_sync);

		if ($sync['drift_detected'] && !$auto_sync) {
			Kernel::abort(
				"Test schema drift detected. Run `radaptor db:test-sync --json` first or rerun `radaptor build:db --auto-sync`."
			);
		}

		self::create(
			[
				Config::DB_DEFAULT_DSN->value(),
				Db::rewriteDsnToTesting(Config::DB_DEFAULT_DSN->value()),
			]
		);
	}

	/**
	 * Creates schema data files from an array of DSNs.
	 *
	 * @param array<string> $dsn_array An array of Data Source Names for the database connections.
	 * @return void
	 */
	public static function create(array $dsn_array): void
	{
		$schema_array = DbSchemaDataBuilder::buildSchemaArray($dsn_array);

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

		GeneratorHelper::reportGeneratedFileWriteStatus('db', $success !== false);
	}
}
