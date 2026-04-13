<?php

/**
 * Db class.
 *
 * This class provides methods for interacting with the database, including:
 * - Establishing and managing database connections
 * - Retrieving table metadata (primary keys, fields, processable fields)
 * - Extracting primary key values from data arrays
 * - Filtering changed parameters for a table entry
 * - Validating parameters against table fields
 *
 * The class uses a singleton pattern to manage PDO instances for each database connection.
 * It also relies on the DbSchemaData class for storing and accessing table metadata.
 *
 * Usage:
 * - Use Db::instance() to get a PDO instance for a specific database (default or named)
 * - Use the various static methods to retrieve table metadata, extract values, filter changes, and validate parameters
 *
 * Note: Direct instantiation of the Db class is not allowed.
 */

class Db
{
	/** @var array<string, PDO> The PDO instances for each database connection */
	public static array $pdoInstances = [];

	public function __construct()
	{
		Kernel::abort("Instantiation of the 'DB' class is not allowed...");
	}

	/**
	 * Redacts the user and password in a DSN string.
	 *
	 * @param string $dsn The original DSN string.
	 * @return string The DSN string with the user and password redacted.
	 */
	public static function redactDSNUserAndPassword(string $dsn): string
	{
		// Split the DSN into its components
		$parts = explode(';', $dsn);
		$dsnComponents = [];

		foreach ($parts as $part) {
			if (str_starts_with($part, 'user=')) {
				$dsnComponents[] = 'user=<redacted>';

				continue;
			}

			if (str_starts_with($part, 'password=')) {
				$dsnComponents[] = 'password=<redacted>';

				continue;
			}
			$dsnComponents[] = $part;
		}

		// Reconstruct the DSN with <redacted> for user and password
		return implode(';', $dsnComponents);
	}

	/**
	 * Extracts the database name from a DSN string.
	 *
	 * @param string $dsn The DSN string.
	 * @return string The database name.
	 */
	public static function getDatabasenameFromDsn(string $dsn = ''): string
	{
		$dsn = self::normalizeDsn($dsn);

		// Split the DSN into its components
		$parts = explode(';', $dsn);

		foreach ($parts as $part) {
			if (str_starts_with($part, 'dbname=')) {
				// Extract and return the database name
				return substr($part, strlen('dbname='));
			}
		}

		Kernel::abort("The DSN '$dsn' does not contain a database name...");
	}

	/**
	 * Extracts the database name from a DSN string and appends '_audit' to it.
	 *
	 * @param string $dsn The original DSN string.
	 * @return string The modified database name with '_audit' appended.
	 */
	public static function getAuditDbFromDsn(string $dsn = ''): string
	{
		$dsn = self::normalizeDsn($dsn);

		// Split the DSN into its components
		$parts = explode(';', $dsn);

		foreach ($parts as $part) {
			if (str_starts_with($part, 'dbname=')) {
				// Extract and return the database name
				return substr($part, strlen('dbname=')) . '_audit';
			}
		}

		Kernel::abort("The DSN '$dsn' does not contain a database name...");
	}

	public static function getDbFromDsn(string $dsn): string
	{
		// Split the DSN into its components
		$parts = explode(';', $dsn);
		$dsnComponents = [];

		foreach ($parts as $part) {
			if (str_starts_with($part, 'dbname=')) {
				return substr($part, strlen('dbname='));
			}
		}

		Kernel::abort("The DSN '$dsn' does not contain a database name...");
	}

	/**
	 * Rewrites the DSN to point to the testing database.
	 *
	 * This function adds the '_test' suffix to the database name, intended to be used in testing environment.
	 *
	 * @param string $dsn The original DSN string.
	 * @return string The DSN string modified to point to the testing database.
	 */
	public static function rewriteDsnToTesting(string $dsn): string
	{
		// Split the DSN into its components
		$parts = explode(';', $dsn);
		$dsnComponents = [];

		foreach ($parts as $part) {
			if (str_starts_with($part, 'dbname=')) {
				$dbName = substr($part, strlen('dbname='));

				if (!str_ends_with($dbName, '_test') && !str_ends_with($dbName, '_audit')) {
					$part = 'dbname=' . $dbName . '_test';
				}
			}
			$dsnComponents[] = $part;
		}

		// Reconstruct the DSN with the modified database name
		return implode(';', $dsnComponents);
	}

	/**
	 * Rewrites the DSN to point to the audit database.
	 *
	 * This function adds the '_audit' suffix to the database name.
	 *
	 * @param string $dsn The original DSN string.
	 * @return string The DSN string modified to point to the testing database.
	 */
	public static function rewriteDsnToAudit(string $dsn = ''): string
	{
		$dsn = self::normalizeDsn($dsn);

		// Split the DSN into its components
		$parts = explode(';', $dsn);
		$dsnComponents = [];

		foreach ($parts as $part) {
			if (str_starts_with($part, 'dbname=')) {
				$dbName = substr($part, strlen('dbname='));

				if (!str_ends_with($dbName, '_audit')) {
					$part = 'dbname=' . $dbName . '_audit';
				}
			}
			$dsnComponents[] = $part;
		}

		// Reconstruct the DSN with the modified database name
		return implode(';', $dsnComponents);
	}

	/**
	 * Normalizes the given DSN string.
	 *
	 * If the DSN string is empty, it uses the default DSN from the configuration.
	 * If the environment is 'test', it rewrites the DSN to use the test database.
	 * If running in CLI with test mode enabled, it also rewrites to testing database.
	 *
	 * @param ?string $dsn The original DSN string.
	 * @return string The normalized DSN string.
	 */
	public static function normalizeDsn(?string $dsn = null): string
	{
		if (empty($dsn)) {
			$dsn = Config::DB_DEFAULT_DSN->value();
		}

		// PHPUnit test environment
		if (Kernel::getEnvironment() === 'test') {
			$dsn = self::rewriteDsnToTesting($dsn);
		}

		// CLI database mode from session storage
		if (defined('RADAPTOR_CLI') && self::getCLIDatabaseMode() === 'test') {
			$dsn = self::rewriteDsnToTesting($dsn);
		}

		return $dsn;
	}

	/**
	 * Gets the current CLI database mode from session storage.
	 *
	 * @return string 'test' or 'normal' (default: 'normal')
	 */
	public static function getCLIDatabaseMode(): string
	{
		if (!defined('RADAPTOR_CLI')) {
			return 'normal';
		}

		return CLIStorage::read('CLI_DATABASE_MODE') ?? 'normal';
	}

	/**
	 * Establishes a database connection the first time it's called for a given DSN and returns
	 * the same PDO instance for subsequent calls to the same DSN.
	 * In 'test' environment, adds the '_test' suffix to the database name.
	 *
	 * @param string $dsn The DSN string to connect to, defaults to Config::DB_DSN.
	 * @return PDO The PDO instance for the specified DSN.
	 */
	public static function instance(string $dsn = ''): PDO
	{
		$dsn = self::normalizeDsn($dsn);

		if (!isset(self::$pdoInstances[$dsn])) {
			try {
				self::$pdoInstances[$dsn] = self::createPdoConnection($dsn);
			} catch (PDOException $e) {
				Kernel::abort("Database connection error: " . $e->getMessage());
			}
		} elseif (self::shouldValidateConnectionForRequest($dsn)) {
			try {
				self::ensureConnectionAlive($dsn);
			} catch (PDOException $e) {
				Kernel::abort("Database connection error: " . $e->getMessage());
			}
		}

		return self::$pdoInstances[$dsn];
	}

	/**
	 * Checks if a database connection can be established using the provided DSN.
	 *
	 * This method attempts to create a PDO instance with the given DSN.
	 * If successful, it stores the instance and returns true.
	 * If the connection fails, it returns false.
	 *
	 * @param string $dsn The Data Source Name for the database connection.
	 * @return bool True if the connection is successful, false otherwise.
	 */
	public static function checkDsnConnection(string $dsn): bool
	{
		if (!isset(self::$pdoInstances[$dsn])) {
			try {
				self::$pdoInstances[$dsn] = self::createPdoConnection($dsn);
			} catch (PDOException) {
				return false;
			}
		}

		return true;
	}

	private static function createPdoConnection(string $dsn): PDO
	{
		$options = [];

		if (self::shouldUsePersistentConnections()) {
			$options[PDO::ATTR_PERSISTENT] = true;
		}

		$pdo = new PDO($dsn, null, null, $options);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
		$pdo->exec('SET NAMES utf8mb4');
		$pdo->exec("SET time_zone = '+00:00'");

		return $pdo;
	}

	private static function shouldUsePersistentConnections(): bool
	{
		if (getenv('RADAPTOR_RUNTIME') !== 'swoole') {
			return false;
		}

		$env = getenv('SWOOLE_PERSISTENT_DB_CONNECTION');

		if ($env === false) {
			return true;
		}

		return filter_var($env, FILTER_VALIDATE_BOOLEAN);
	}

	private static function shouldValidateConnectionForRequest(string $dsn): bool
	{
		if (!self::shouldUsePersistentConnections()) {
			return false;
		}

		$ctx = RequestContextHolder::current();
		$cacheKey = 'db_connection_validated:' . $dsn;

		if (isset($ctx->inMemoryCache[$cacheKey])) {
			return false;
		}

		$ctx->inMemoryCache[$cacheKey] = true;

		return true;
	}

	private static function ensureConnectionAlive(string $dsn): void
	{
		$pdo = self::$pdoInstances[$dsn] ?? null;

		if (!$pdo instanceof PDO) {
			self::$pdoInstances[$dsn] = self::createPdoConnection($dsn);

			return;
		}

		try {
			$pdo->query('SELECT 1');
		} catch (PDOException $e) {
			if (!self::isRecoverableConnectionError($e)) {
				throw $e;
			}

			self::$pdoInstances[$dsn] = self::createPdoConnection($dsn);
		}
	}

	private static function isRecoverableConnectionError(PDOException $e): bool
	{
		$message = strtolower($e->getMessage());

		if (str_contains($message, 'server has gone away') || str_contains($message, 'lost connection')) {
			return true;
		}

		if ($e->errorInfo === null) {
			return false;
		}

		$driverErrorCode = (int)($e->errorInfo[1] ?? 0);

		return in_array($driverErrorCode, [2006, 2013], true);
	}

	/**
	 * Returns the primary keys for a specified table.
	 *
	 * @param string $table The logical name of the table.
	 * @return list<string> The primary keys of the specified table.
	 */
	public static function getPrimaryKeys(string $table, string $dsn = ''): array
	{
		$dsn = self::normalizeDsn($dsn);

		// Verify the existence of table information
		if (is_null(DbSchemaData::getTableData($table, $dsn))) {
			Kernel::abort("Function: " . __METHOD__ . ", line " . __LINE__ . "<br>Table information for '$table' not found!");
		}

		return DbSchemaData::getTableData($table, $dsn)['pkeys'];
	}

	/**
	 * Returns the fields that require post-processing for a specified table.
	 *
	 * @param string $table The logical name of the requested table.
	 * @return array<string, string> The processable fields of the specified table.
	 */
	public static function getProcessableFields(string $table, string $dsn = ''): array
	{
		$dsn = Db::normalizeDsn($dsn);

		// Check if table data exists
		if (is_null(DbSchemaData::getTableData($table, $dsn))) {
			Kernel::abort("Function: " . __METHOD__ . ", line " . __LINE__ . "<br>Table information for '$table' not found!");
		}

		return DbSchemaData::getTableData($table, $dsn)['processable_fields'];
	}

	/**
	 * Retrieves the names of the fields for a specified table.
	 *
	 * @param string $table The logical name of the table.
	 * @return list<string> The fields of the specified table.
	 */
	public static function getFieldNames(string $table, string $dsn = ''): array
	{
		$dsn = self::normalizeDsn($dsn);

		// Handle absence of table data
		if (is_null(DbSchemaData::getTableData($table, $dsn))) {
			Kernel::abort("Function: " . __METHOD__ . ", line " . __LINE__ . "<br>Table information for '$table' not found!");
		}

		return DbSchemaData::getTableData($table, $dsn)['field_names'];
	}

	/**
	 * Extracts values for specified primary keys from a given array.
	 *
	 * @param array<string> $pkeys An array of primary key names.
	 * @param array<string, mixed> $savedata The data array from which to extract the primary key values.
	 * @return array<string, mixed> An associative array of primary key values.
	 * @throws InvalidArgumentException If a key is not found in the data array.
	 */
	public static function getValuesForPrimaryKeys(array $pkeys, array $savedata): array
	{
		$pkey_values = [];

		foreach ($pkeys as $pkey) {
			if (!array_key_exists($pkey, $savedata)) {
				throw new InvalidArgumentException("Key '{$pkey}' not found in the data array.");
			} else {
				$pkey_values[$pkey] = $savedata[$pkey];
			}
		}

		return $pkey_values;
	}

	/**
	 * Filters and returns only the changed parameters for a given table entry compared to existing database data.
	 * If only primary keys are provided, no data is considered changed unless additional fields are present.
	 *
	 * @param string $table The name of the database table.
	 * @param array<string, mixed> $savedata Data array to compare against existing database entry.
	 * @param mixed $id Optional primary key value; if provided, used instead of extracting from $savedata.
	 * @return array<string, mixed> An array of fields that have changed (empty if no changes).
	 */
	public static function filterChangedParameters(string $table, array $savedata, mixed $id = null): array
	{
		$changedData = [];
		$primaryKeys = Db::getPrimaryKeys($table);

		// Determine the primary key values to use
		if ($id !== null) {
			$primaryKeyValues = is_array($id) ? $id : [$primaryKeys[0] => $id];

			if (is_array($id)) {
				foreach ($id as $key => $value) {
					if (!in_array($key, $primaryKeys) || isset($savedata[$key]) && $savedata[$key] !== $value) {
						ResourceTreeHandler::drop400("The provided primary key values do not match the primary key values in the savedata array.");
					}
				}
			} elseif (count($primaryKeys) > 1) {
				ResourceTreeHandler::drop400("Explicit primary key value must be an array for tables with a composite primary key.");
			} elseif (isset($savedata[$primaryKeys[0]]) && $savedata[$primaryKeys[0]] !== $id) {
				ResourceTreeHandler::drop400(
					"The provided primary key value does not match the primary key value in the savedata array."
				);
			}
		} else {
			$primaryKeyValues = Db::getValuesForPrimaryKeys($primaryKeys, $savedata);
		}

		// Prepare and execute the query to fetch the existing data
		$query = "SELECT * FROM {$table} WHERE " . DbHelper::generateEnumerationForSelect($primaryKeyValues);
		$stmt = Db::instance()->prepare($query);
		$stmt->execute(array_values($primaryKeyValues));
		$existingData = $stmt->fetch(PDO::FETCH_ASSOC);

		$changesDetected = 0;

		// Compare each field in the saved data to the existing data
		foreach ($savedata as $field => $value) {
			if (!in_array($field, $primaryKeys)) {
				if (!array_key_exists($field, $existingData) || $value != $existingData[$field]) {
					$changedData[$field] = $value;
					$changesDetected++;
				}
			}
		}

		// Return the changed data, or an empty array if no changes detected
		if ($changesDetected > 0) {
			return $changedData;
		} else {
			return [];
		}
	}

	/**
	 * Validates that all parameters in the provided data are valid fields of the specified table
	 * and, if required, that primary keys are also present.
	 *
	 * @param string $table The name of the database table.
	 * @param array<string, mixed> $savedata An associative array of data to validate, where keys are field names and values are field values.
	 * @param bool $pkey_required Flag indicating whether primary keys are required in the data.
	 * @param string $dsn Optional Data Source Name for database connection.
	 * @return bool Returns true if all parameters are valid and primary keys are present (if required).
	 */
	public static function validateParameters(string $table, array $savedata, bool $pkey_required = true, string $dsn = ''): bool
	{
		$dsn = self::normalizeDsn($dsn);

		$fields = Db::getFieldNames($table, $dsn);
		$pkeys = Db::getPrimaryKeys($table, $dsn);

		// Validate that all provided data fields are valid table fields
		foreach ($savedata as $field => $value) {
			if (!in_array($field, $fields)) {
				ResourceTreeHandler::drop400("Unknown parameter '{$field}' for table '{$table}'.");
			}
		}

		// If primary keys are required, ensure they are present in the data
		if ($pkey_required) {
			foreach ($pkeys as $pkey) {
				if (!isset($savedata[$pkey])) {
					ResourceTreeHandler::drop400("Missing primary key '{$pkey}' value for table '{$table}'.");
				}
			}
		}

		return true;
	}
}
