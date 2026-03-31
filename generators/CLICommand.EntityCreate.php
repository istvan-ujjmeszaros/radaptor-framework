<?php

class CLICommandEntityCreate extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Create entity';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Generate an entity class from a database table.

			Usage: radaptor entity:create <table_name> [--force] [--table <name>] [--dsn <dsn>]

			Examples:
			  radaptor entity:create users
			  radaptor entity:create users --force
			  radaptor entity:create blog --table blog_posts
			DOC;
	}

	public function run(): void
	{
		$entity_name = Request::getMainArg();

		if (is_null($entity_name)) {
			Kernel::abort("Error: Missing entity name. Use 'radaptor entity:create <entity_name" . ">'.");
		}

		$inflector = new Symfony\Component\String\Inflector\EnglishInflector();
		$singularized_name = $inflector->singularize($entity_name)[0];
		$pluralized_name = $inflector->pluralize($singularized_name)[0]; // Pluralize the singular form for consistency

		$entity_class_name = 'Entity' . ucfirst($singularized_name);

		$dsn = Request::getArg('dsn') ?? Db::normalizeDsn();

		$entityAlreadyExists = AutoloaderFromGeneratedMap::autoloaderClassExists($entity_class_name);

		if ($entityAlreadyExists && !Request::hasArg('force')) {
			Kernel::abort("Error: Entity <$entity_class_name> already exists. Use --force to overwrite.");
		}

		// Build list of table names to try (input as-is, plural, singular)
		$input_table_name = GeneratorHelper::toSnakeCase($entity_name);
		$plural_table_name = GeneratorHelper::toSnakeCase($pluralized_name);
		$singular_table_name = GeneratorHelper::toSnakeCase($singularized_name);

		$table_names_to_try = array_unique([$input_table_name, $plural_table_name, $singular_table_name]);

		// If --table is specified, only use that
		if (Request::hasArg('table')) {
			$table_names_to_try = [Request::getArg('table')];
		}

		$table_info = null;
		$table_name = null;

		foreach ($table_names_to_try as $try_name) {
			$table_info = GeneratorHelper::getTableDataFromDb($dsn, $try_name);

			if (!is_null($table_info)) {
				$table_name = $try_name;

				break;
			}
		}

		if (is_null($table_info)) {
			$filtered_dsn = Db::redactDSNUserAndPassword($dsn);
			$searched = implode(', ', $table_names_to_try);
			Kernel::abort("Error: Table not found in database.\nSearched: $searched\nDSN: {$filtered_dsn}");
		}

		$variables = [
			'entity_class_name' => $entity_class_name,
			'table_name' => $table_name,
			'singular_table_name' => $singular_table_name,
			'table_info' => $table_info,
			'dsn' => $dsn,
		];

		$file_content = GeneratorHelper::fetchTemplate(
			'entity',
			$variables
		);

		$file_name = "Entity." . ucfirst($singularized_name) . ".php";

		if ($entityAlreadyExists) {
			$path = $this->getExistingEntityFilePath($entity_class_name);
			echo("Overwriting existing entity at:\n$path\n");
		} else {
			$path = GeneratorHelper::getModulePathForEntity(ucfirst($singularized_name));

			$radaptorCliRoot = defined('RADAPTOR_CLI') ? RADAPTOR_CLI : DEPLOY_ROOT;

			if ($radaptorCliRoot !== DEPLOY_ROOT) {
				$path = $radaptorCliRoot . $file_name;
				echo("Creating entity in current subfolder:\n$path\n");
			} elseif (is_null($path)) {
				Kernel::abort("Error: No matching module found for `$singularized_name`. Run the command from the target subfolder.");
			} else {
				$path .= $file_name;
				echo("Matching module found. Creating entity at:\n$path\n");
			}
		}

		$confirmation = readline("Proceed with entity creation? (yes/no) [yes]: ");

		if (strtolower($confirmation) !== 'yes' && strtolower($confirmation) !== '') {
			Kernel::abort("Entity creation cancelled.");
		}

		if (GeneratorHelper::saveFileContents(path: $path, contents: $file_content)) {
			echo("Entity successfully created at: $path\n");
		} else {
			Kernel::abort("Error: Failed to create entity at: $path");
		}
	}
	private function getExistingEntityFilePath(string $entity_class_name): string
	{
		$map = AutoloaderGeneratedMap::getAutoloadMap();

		return DEPLOY_ROOT . ltrim($map[$entity_class_name], '/');
	}
}
