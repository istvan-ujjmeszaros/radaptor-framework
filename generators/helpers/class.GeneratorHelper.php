<?php

class GeneratorHelper
{
	/**
	 * Flushes the output buffer and sends buffered contents to the browser.
	 */
	public static function flushOutput(): void
	{
		@flush();

		if (ob_get_length()) {
			@ob_flush();
			@ob_end_flush();
		}
	}

	/**
	 * Checks if a folder should be excluded from the generation process.
	 *
	 * @param string $path The path to the folder.
	 *
	 * @return bool True if the folder should be excluded, false otherwise.
	 */
	public static function folderIsExcluded(string $path): bool
	{
		// Remove DEPLOY_ROOT from the path
		$path = str_replace(
			DEPLOY_ROOT,
			'',
			$path
		);

		// Ensure a trailing slash at the end
		if (!str_ends_with(
			$path,
			'/'
		)) {
			$path .= '/';
		}

		// Ensure a leading slash at the beginning
		if (!str_starts_with(
			$path,
			'/'
		)) {
			$path = '/' . $path;
		}

		// Define excluded folders
		$excluded_folders = [
			...ApplicationConfig::GENERATOR_IGNORED_FOLDERS,
			ApplicationConfig::PATH_UPLOADED_FILES_DIRECTORY,
		];

		// Generate regex pattern for matching excluded folders and hidden folders
		$pattern = implode(
			'|',
			// Ensure trailing slash and then escaping the pattern
			array_map(
				fn ($folder) => preg_quote(
					rtrim(
						$folder,
						'/'
					) . '/',
					'/'
				),
				$excluded_folders
			)
		);

		// Match only at the beginning and also match hidden folders
		$pattern = '/^(' . $pattern . ')|(^|\/)\.[^\/]+/';

		// Check if the path matches the pattern
		return preg_match(
			$pattern,
			$path
		) === 1;
	}

	/**
	 * Writes content to a file and displays a success or error message.
	 *
	 * @param string $generatedFilename The path to the cache file.
	 * @param string $content The content to write to the file.
	 *
	 * @return bool True if the file was written successfully, false otherwise.
	 */
	public static function writeGeneratedFile(string $generatedFilename, string $content): bool
	{
		if (($cacheFilename_handle = fopen(
			$generatedFilename,
			"w"
		))) {
			if (fwrite(
				$cacheFilename_handle,
				str_replace(
					"	",
					"	",
					$content
				)
			) !== false) {
				echo "<b>" . basename($generatedFilename) . "</b> leíró fájl sikeresen létrehozva!\n";

				return true;
			} else {
				echo "Hiba történt a <b>" . basename($generatedFilename) . "</b> leíró fájl írásakor!\n";
			}
		} else {
			echo "<span style=\"background-color:red;\">Ismeretlen hiba a " . basename(
				$generatedFilename
			) . " leíró készítése közben!</span>\n";
		}

		return false;
	}

	/**
	 * @param string $dsn
	 * @param mixed $tablename
	 *
	 * @return structSQLTable|null
	 */
	public static function getTableDataFromDb(string $dsn, mixed $tablename): ?structSQLTable
	{
		$tableData = new structSQLTable($tablename);

		// Get table comment
		try {
			$stmt = Db::instance($dsn)->prepare(
				"SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
			);
			$stmt->execute([$tablename]);
			$tableData->comment = $stmt->fetchColumn() ?: '';
		} catch (PDOException) {
			// Ignore - comment will remain empty
		}

		try {
			$stmt = Db::instance($dsn)
					  ->prepare('SHOW FULL COLUMNS FROM ' . $tablename)
			;
			$stmt->execute();
		} catch (PDOException) {
			return null;
		}

		$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

		foreach ($columns as $column) {
			$tableData->field_names[] = $column['Field'];

			$field = new structSQLColumn($column['Field']);
			$field->comment = $column['Comment'];

			$field->type_sql = $column['Type'];
			$field->type_php = GeneratorHelper::mapDBTypeToPHPType($column);

			$field->default = $column['Default'];

			$field->extra = $column['Extra'];

			if ($column['Key'] == 'PRI') {
				$tableData->pkeys[] = $column['Field'];
				$field->is_primary_key = true;
			}

			if (str_contains($field->extra, 'auto_increment')) {
				$field->is_auto_increment = true;
				$tableData->is_auto_increment = true;
			}

			$field->is_nullable = ($column['Null'] === 'YES');
			// Field is optional if: nullable (effectively DEFAULT NULL), auto_increment, or has explicit non-NULL default
			$field->is_optional = ($field->is_nullable || $column['Extra'] === 'auto_increment' || $column['Default'] !== null);

			if ($column['Field'][0] == '_' && $column['Field'][1] == '_' && $column['Field'][2] != '_') {
				$tableData->processable_fields[$column['Field']] = mb_substr((string)$column['Field'], 2);
				$field->is_processable = true;
			}

			$tableData->fields[] = $field;
		}

		return $tableData;
	}

	/**
	 * Map database-specific types to PHP types.
	 *
	 * @param array{
	 *     Field: string,
	 *     Type: string,
	 *     Collation: string|null,
	 *     Null: string,
	 *     Key: string,
	 *     Default: string|null,
	 *     Extra: string,
	 *     Privileges: string,
	 *     Comment: string
	 * } $column The array of the column data returned by "SHOW FULL COLUMNS FROM $tablename".
	 *
	 * @return string The corresponding PHP type.
	 */
	public static function mapDBTypeToPHPType(array $column): string
	{
		$fieldType = strtolower($column['Type']);

		$php_type = match (true) {
			str_starts_with(
				$fieldType,
				'boolean'
			), str_starts_with(
				$fieldType,
				'bool'
			), str_starts_with(
				$fieldType,
				'tinyint(1)'
			) => 'bool',

			str_starts_with(
				$fieldType,
				'int'
			), str_starts_with(
				$fieldType,
				'integer'
			), str_starts_with(
				$fieldType,
				'smallint'
			), str_starts_with(
				$fieldType,
				'mediumint'
			), str_starts_with(
				$fieldType,
				'bigint'
			), str_starts_with(
				$fieldType,
				'tinyint'
			), str_starts_with(
				$fieldType,
				'serial'
			), str_starts_with(
				$fieldType,
				'bigserial'
			) => 'int',

			str_starts_with(
				$fieldType,
				'decimal'
			), str_starts_with(
				$fieldType,
				'numeric'
			), str_starts_with(
				$fieldType,
				'float'
			), str_starts_with(
				$fieldType,
				'double'
			), str_starts_with(
				$fieldType,
				'real'
			), str_starts_with(
				$fieldType,
				'double precision'
			) => 'float',

			str_starts_with(
				$fieldType,
				'date'
			), str_starts_with(
				$fieldType,
				'datetime'
			), str_starts_with(
				$fieldType,
				'timestamp'
			), str_starts_with(
				$fieldType,
				'timestamp with time zone'
			), str_starts_with(
				$fieldType,
				'timestamp without time zone'
			), str_starts_with(
				$fieldType,
				'time'
			), str_starts_with(
				$fieldType,
				'time with time zone'
			), str_starts_with(
				$fieldType,
				'time without time zone'
			), str_starts_with(
				$fieldType,
				'year'
			),
			str_starts_with(
				$fieldType,
				'char'
			), str_starts_with(
				$fieldType,
				'varchar'
			), str_starts_with(
				$fieldType,
				'text'
			), str_starts_with(
				$fieldType,
				'tinytext'
			), str_starts_with(
				$fieldType,
				'mediumtext'
			), str_starts_with(
				$fieldType,
				'longtext'
			), str_starts_with(
				$fieldType,
				'character varying'
			), str_starts_with(
				$fieldType,
				'character'
			), str_starts_with(
				$fieldType,
				'json'
			), str_starts_with(
				$fieldType,
				'jsonb'
			), str_starts_with(
				$fieldType,
				'enum'
			) => 'string',	// Decided to go with a string by default, so anyone can use whatever they want, like Carbon

			str_starts_with(
				$fieldType,
				'blob'
			), str_starts_with(
				$fieldType,
				'tinyblob'
			), str_starts_with(
				$fieldType,
				'mediumblob'
			), str_starts_with(
				$fieldType,
				'longblob'
			), str_starts_with(
				$fieldType,
				'bytea'
			) => 'string', // or 'resource' if you prefer

			default => 'mixed', // Fallback for any other types
		};

		return $php_type;
	}

	public static function getModulePath(string $moduleName): ?string
	{
		// Get the autoload map
		$autoloadMap = AutoloaderGeneratedMap::getAutoloadMap();

		// Extract the module name from the path
		if (array_any(
			$autoloadMap,
			fn ($path) => preg_match(
				'/app\/modules\/' . preg_quote(
					$moduleName,
					'/'
				) . '\//',
				$path,
				$matches
			)
		)) {
			return 'app/modules/' . $moduleName . '/';
		}

		return null; // Return null if the module is not found
	}

	public static function getModulePathForEntity(string $entityName): ?string
	{
		// Get the module paths
		$modulePath = self::getModulePath($entityName);

		if (is_null($modulePath)) {
			return null;
		}

		return $modulePath . 'entities/';
	}

	public static function saveFileContents(string $path, string $contents): bool
	{
		if (!is_dir(dirname($path))) {
			mkdir(
				dirname($path),
				0o777,
				true
			);
			chown(dirname($path), Config::LINUX_FILE_OWNER->value());
			chgrp(dirname($path), Config::LINUX_FILE_GROUP->value());
			chmod(dirname($path), Config::LINUX_FILE_MODE_DIRECTORY->value());
		}

		if (file_put_contents(
			$path,
			$contents
		) === false) {
			return false;
		} else {
			chown($path, Config::LINUX_FILE_OWNER->value());
			chgrp($path, Config::LINUX_FILE_GROUP->value());
			chmod($path, Config::LINUX_FILE_MODE->value());

			return true;
		}
	}

	/**
	 * Render a template with the given variables.
	 *
	 * @param string $templateName The name of the template to render.
	 * @param array $variables An associative array of variables to extract into the template.
	 *
	 * @return string The rendered template content or an error message if the template file is not found.
	 */
	public static function fetchTemplate(string $templateName, array $variables = []): string
	{
		$templatePath = __DIR__ . "/../templates/" . $templateName . ".template.php";

		if (!file_exists($templatePath)) {
			return "Template file for $templateName not found";
		}

		extract($variables);

		ob_start();

		include $templatePath;

		return "<?php\n\n" . ob_get_clean();
	}

	/**
	 * Format an array export to short syntax with tabs.
	 * Base indent is 2 tabs, then +1 tab per additional nesting level.
	 * Closing bracket is at the same level as the key that opens the array.
	 *
	 * @param array<mixed> $data
	 * @param int $indentLevel
	 */
	public static function formatArrayForExport(array $data, int $indentLevel = 0): string
	{
		// Content indent: base 2 tabs + nesting level
		$contentIndent = str_repeat("\t", $indentLevel + 2);
		// Closing bracket: one level less than content (same as the key that opens it)
		$closingIndent = str_repeat("\t", $indentLevel + 1);

		$lines = ["["];

		foreach ($data as $key => $value) {
			$keyExport = is_int($key) ? $key : "'" . addslashes($key) . "'";

			if (is_array($value)) {
				$valueExport = self::formatArrayForExport($value, $indentLevel + 1);
			} elseif (is_bool($value)) {
				$valueExport = $value ? 'true' : 'false';
			} elseif (is_null($value)) {
				$valueExport = 'null';
			} elseif (is_string($value)) {
				$valueExport = "'" . addslashes($value) . "'";
			} else {
				$valueExport = var_export($value, true);
			}

			$lines[] = $contentIndent . $keyExport . " => " . $valueExport . ",";
		}

		$lines[] = $closingIndent . "]";

		return implode("\n", $lines);
	}

	/**
	 * Convert a PascalCase or camelCase string to snake_case.
	 *
	 * @param string $input The input string in PascalCase or camelCase.
	 *
	 * @return string The converted string in snake_case.
	 */
	public static function toSnakeCase(string $input): string
	{
		// Replace capital letters with an underscore followed by the lowercase letter
		$snakeCase = preg_replace(
			'/([a-z])([A-Z])/',
			'$1_$2',
			$input
		);

		// Convert the entire string to lowercase
		return strtolower($snakeCase);
	}

	/**
	 * Convert a snake_case string to camelCase.
	 *
	 * @param string $input The input string in snake_case.
	 *
	 * @return string The converted string in camelCase.
	 */
	public static function toCamelCase(string $input): string
	{
		// Split the string by underscores
		$words = explode(
			'_',
			$input
		);

		// Convert the first word to lowercase
		$camelCase = strtolower(array_shift($words));

		// Capitalize the first letter of each remaining word and append to the result
		foreach ($words as $word) {
			$camelCase .= ucfirst(strtolower($word));
		}

		return $camelCase;
	}
}
