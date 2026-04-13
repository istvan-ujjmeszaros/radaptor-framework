<?php

/**
 * DbHelper class provides utility methods for common database operations.
 */
class DbHelper
{
	/**
	 * Prepares and executes a PDO statement.
	 *
	 * @param string $query The SQL query to prepare and execute.
	 * @param array<int|float|string|bool>|null $values Optional array of parameters to bind to the statement.
	 * @param string $dsn
	 * @return ?PDOStatement The executed PDOStatement object, or null on failure.
	 */
	public static function prexecute(string $query, ?array $values = null, string $dsn = ''): ?PDOStatement
	{
		$stmt = Db::instance($dsn)->prepare($query);

		if ($stmt === false) {
			return null;
		}

		$stmt->execute($values);

		return $stmt;
	}

	/**
	 * Fetches all rows from a query result set.
	 *
	 * @param string $query The SQL query to execute.
	 * @param array<int|float|string|bool>|null $values Optional array of parameters to bind to the statement.
	 * @param string $dsn
	 * @return array<int, array<string, int|float|string|bool>> An array containing all the rows of the query result set.
	 */
	public static function fetchAll(string $query, ?array $values = null, string $dsn = ''): array
	{
		$return = self::prexecute($query, $values, $dsn)?->fetchAll(PDO::FETCH_ASSOC);

		return $return ?? [];
	}

	/**
	 * Fetches a single row from a query result set.
	 *
	 * @param string $query The SQL query to execute.
	 * @param array<int|float|string|bool>|null $values Optional array of parameters to bind to the statement.
	 * @param string $dsn
	 * @return array<string, int|float|string|bool>|null The first row of the query result set, or false if no rows are returned.
	 */
	public static function fetch(string $query, ?array $values = null, string $dsn = ''): mixed
	{
		return self::prexecute($query, $values, $dsn)?->fetch(PDO::FETCH_ASSOC);
	}

	/**
	 * Generates a comma-separated list of field-value pairs for use in SQL queries.
	 * Column names are wrapped in backticks to safely handle SQL reserved keywords.
	 *
	 * @param array<string, int|float|string|bool> $savedata An associative array of field-value pairs.
	 * @return string The generated enumeration string.
	 */
	public static function generateEnumeration(array $savedata): string
	{
		return implode('=?,', array_map(function ($column) {
			return '`' . $column . '`';
		}, array_keys($savedata))) . '=?';
	}

	/**
	 * Quote a simple SQL identifier or dotted identifier path.
	 *
	 * Complex expressions are returned unchanged.
	 */
	private static function _quoteIdentifierPath(string $identifier): string
	{
		$identifier = trim($identifier);

		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $identifier)) {
			return $identifier;
		}

		return implode('.', array_map(
			fn (string $segment): string => '`' . $segment . '`',
			explode('.', $identifier)
		));
	}

	/**
	 * Build a WHERE comparison expression from a criteria key.
	 *
	 * Supports:
	 * - simple equality: username => `username`=?
	 * - LIKE suffix: username LIKE => `username` LIKE ?
	 * - dotted identifiers: u.username => `u`.`username`=?
	 *
	 * Complex expressions are left unchanged.
	 */
	private static function _buildSelectComparison(string $key): string
	{
		$key = trim($key);

		if (preg_match('/^(.+?)\s+(LIKE)$/i', $key, $matches)) {
			return self::_quoteIdentifierPath($matches[1]) . ' ' . strtoupper($matches[2]) . ' ?';
		}

		return self::_quoteIdentifierPath($key) . '=?';
	}

	/**
	 * Generates a comma-separated list of field-value pairs for use in SELECT queries.
	 *
	 * @param array<string, int|string> $criteria An associative array of primary key field-value pairs.
	 * @param string $type The logical operator to use between each pair (default: 'AND').
	 * @return string The generated enumeration string for the SELECT clause.
	 */
	public static function generateEnumerationForSelect(array $criteria, string $type = 'AND'): string
	{
		$criteria_count = count($criteria);
		$enumerated = '';
		$normalized_type = strtoupper($type) === 'OR' ? 'OR' : 'AND';

		$i = 0;

		foreach ($criteria as $key => $value) {
			++$i;

			$enumerated .= self::_buildSelectComparison($key);

			if ($i !== $criteria_count) {
				$enumerated .= " {$normalized_type} ";
			}
		}

		return $enumerated;
	}

	/**
	 * Sets the owner_id field in the savedata array if it exists in the table.
	 *
	 * @param string $table The name of the database table.
	 * @param array<string, int|float|string|bool> $savedata The savedata array to modify.
	 * @param string $dsn
	 * @return void
	 */
	private static function _setOwnerId(string $table, array &$savedata, string $dsn = ''): void
	{
		$fields = Db::getFieldNames($table, $dsn);

		if (in_array('owner_id', $fields)) {
			$savedata['owner_id'] ??= User::getCurrentUserId();
		}
	}

	/**
	 * Determines if a table has a sequence field.
	 *
	 * @param string $table The name of the database table.
	 * @param string $seq_field The name of the sequence field (default: 'seq').
	 * @param string $dsn
	 * @return bool True if the table has a sequence field, false otherwise.
	 */
	private static function _hasSeq(string $table, string $seq_field = 'seq', string $dsn = ''): bool
	{
		$fields = Db::getFieldNames($table, $dsn);

		return in_array($seq_field, $fields);
	}

	/**
	 * Sets the sequence field value for a table in the savedata array.
	 *
	 * @param string $table The name of the database table.
	 * @param array<string, int|float|string|bool> $savedata The savedata array to modify.
	 * @param string $seq_field The name of the sequence field (default: 'seq').
	 * @param string $dsn
	 * @return void
	 */
	private static function _setSeq(string $table, array &$savedata, string $seq_field = 'seq', string $dsn = ''): void
	{
		if (!self::_hasSeq($table, $seq_field, $dsn)) {
			return;
		}

		$query = "SELECT MAX($seq_field) as max_seq FROM $table";

		$stmt = Db::instance($dsn)->prepare($query);

		try {
			$stmt->execute();
		} catch (Exception $e) {
			SystemMessages::_error(basename(__FILE__) . " line " . __LINE__ . "<br>" . $e->getMessage());
			$savedata[$seq_field] = 1;

			return;
		}

		$rs = $stmt->fetch(PDO::FETCH_ASSOC);

		if (isset($rs['max_seq'])) {
			$savedata[$seq_field] = $rs['max_seq'] + 1;
		} else {
			$savedata[$seq_field] = 1;
		}
	}

	/**
	 * Retrieves the sequence field value from a result set.
	 *
	 * @param array<string, mixed> $rs The result set array.
	 * @param string $seq_field The name of the sequence field (default: 'seq').
	 * @return ?int The sequence value, or null if not found.
	 */
	private static function _getSeq(array $rs, string $seq_field = 'seq'): ?int
	{
		return $rs[$seq_field] ?? null;
	}

	/**
	 * Checks if a field value is unique in a table.
	 *
	 * @param string $table The name of the database table.
	 * @param string $field The name of the field to check for uniqueness.
	 * @param mixed $value The value to check for uniqueness.
	 * @param int|null $id Optional ID to exclude from the uniqueness check.
	 * @param string $dsn
	 * @return bool True if the value is unique, false otherwise.
	 */
	public static function checkIsUnique(string $table, string $field, mixed $value, ?int $id = null, string $dsn = ''): bool
	{
		try {
			if ($id !== null) {
				$pkeys = Db::getPrimaryKeys($table, $dsn);
				$pkey_values = [$pkeys[0] => $id];

				$query = "SELECT * FROM {$table} WHERE $field=? AND NOT (" . self::generateEnumerationForSelect($pkey_values) . ") LIMIT 1";
				$stmt = Db::instance($dsn)->prepare($query);
				$stmt->execute(array_merge([$value], array_values($pkey_values)));
			} else {
				$query = "SELECT * FROM {$table} WHERE $field=? LIMIT 1";
				$stmt = Db::instance($dsn)->prepare($query);
				$stmt->execute([$value]);
			}
		} catch (Exception $e) {
			SystemMessages::_error(basename(__FILE__) . " line " . __LINE__ . "<br>" . $e->getMessage());

			return false;
		}

		$rs = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($rs === false) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Makes a field value unique by appending a numeric suffix if necessary.
	 *
	 * @param string $table The name of the database table.
	 * @param string $field The name of the field to make unique.
	 * @param int $value The value to make unique.
	 * @param string $dsn
	 * @return int The modified unique value.
	 */
	public static function makeUnique(string $table, string $field, int $value, string $dsn = ''): int
	{
		$i = 0;

		while (!DbHelper::checkIsUnique($table, $field, $value . ($i == 0 ? '' : "_$i"), null, $dsn)) {
			++$i;
		}

		if ($i > 0) {
			$value .= "_$i";
		}

		return $value;
	}

	/**
	 * Retrieves the next auto-increment ID for a table.
	 *
	 * @param string $table The name of the database table.
	 * @param string $dsn
	 * @return int The next auto-increment ID.
	 */
	public static function getNextAutoIncrementId(string $table, string $dsn = ''): int
	{
		$stmt = Db::instance($dsn)->prepare("SHOW TABLE STATUS LIKE '{$table}'");

		try {
			$stmt->execute();
		} catch (Exception $e) {
			SystemMessages::_error(basename(__FILE__) . " line " . __LINE__ . "<br>" . $e->getMessage());

			return 0;
		}

		$result = $stmt->fetch(PDO::FETCH_ASSOC);

		return $result['Auto_increment'];
	}

	/**
	 * Inserts a new row into a table with the given data.
	 *
	 * @param string $table The name of the database table.
	 * @param array<string, int|float|string|bool> $savedata An associative array of field-value pairs to insert.
	 * @param string $dsn The Data Source Name for the database connection.
	 * @return int Returns the last insert ID for tables with single primary key,
	 *             1 for successful insertion with composite keys.
	 * @throws PDOException on database error
	 */
	public static function insertHelper(string $table, array $savedata, string $dsn = ''): int
	{
		$seq_field = 'seq';

		$savedata = self::normalizeSavedataValues($savedata);

		Db::validateParameters($table, $savedata, false, $dsn);

		self::_setOwnerId($table, $savedata, $dsn);

		if (!isset($savedata[$seq_field])) {
			self::_setSeq($table, $savedata, $seq_field, $dsn);
		}

		$savedata = DbHelper::generateProcessableFields($table, $savedata, $dsn);

		$query = "INSERT INTO {$table} SET " . self::generateEnumeration($savedata);

		$stmt = Db::instance($dsn)->prepare($query);
		$stmt->execute(array_values($savedata));

		$last_id = Db::instance($dsn)->lastInsertId();

		if (!is_numeric($last_id) || $last_id === "0") {
			// There is no auto-incremented primary key, so we are returning 1 to indicate success
			return 1;
		}

		return (int)$last_id;
	}

	/**
	 * Inserts a new row or handles duplicates gracefully.
	 *
	 * Behavior depends on whether $savedata contains non-PK columns:
	 * - With non-PK columns: Uses INSERT ... ON DUPLICATE KEY UPDATE (upsert)
	 * - PK columns only: Uses no-op ON DUPLICATE KEY UPDATE (idempotent insert)
	 *
	 * The second case handles composite PK mapping tables (e.g., user_role assignments)
	 * where there's nothing to update on duplicate - only the relationship matters.
	 * Uses no-op UPDATE instead of INSERT IGNORE to surface FK violations.
	 *
	 * @param string $table The name of the table to insert or update.
	 * @param array<string, mixed> $savedata An associative array of column-value pairs.
	 * @param string $dsn The Data Source Name for the database connection.
	 * @return int|null Returns row count on success (1 = inserted, 0 = existed/no change), null on error.
	 */
	public static function insertOrUpdateHelper(string $table, array $savedata, string $dsn = ''): ?int
	{
		$seq_field = 'seq';

		$savedata = self::normalizeSavedataValues($savedata);

		DbHelper::_setOwnerId($table, $savedata, $dsn);

		if (!isset($savedata[$seq_field])) {
			self::_setSeq($table, $savedata, $seq_field, $dsn);
		}

		$savedata = DbHelper::generateProcessableFields($table, $savedata, $dsn);

		// Build update_savedata by removing fields that shouldn't be overwritten on duplicate:
		// - owner_id and seq: these are set once on insert and should not change
		// - primary keys: these identify the row, not updatable data
		$update_savedata = $savedata;
		unset($update_savedata['owner_id'], $update_savedata[$seq_field]);

		foreach (Db::getPrimaryKeys($table, $dsn) as $value) {
			unset($update_savedata[$value]);
		}

		// If update_savedata is empty, it means $savedata contained only primary keys
		// (e.g., composite PK mapping tables like user_role assignments).
		// Use a no-op ON DUPLICATE KEY UPDATE to be idempotent while still surfacing
		// FK violations (INSERT IGNORE would silently suppress them).
		if (empty($update_savedata)) {
			$first_key = array_key_first($savedata);
			$query = "INSERT INTO `{$table}` SET " . self::generateEnumeration($savedata);
			$query .= " ON DUPLICATE KEY UPDATE `{$first_key}` = `{$first_key}`";
			$params = array_values($savedata);
		} else {
			$query = "INSERT INTO `{$table}` SET " . self::generateEnumeration($savedata);
			$query .= ' ON DUPLICATE KEY UPDATE ' . self::generateEnumeration($update_savedata);
			$params = array_merge(array_values($savedata), array_values($update_savedata));
		}

		$stmt = Db::instance($dsn)->prepare($query);

		try {
			$stmt->execute($params);
		} catch (Exception $e) {
			if (Kernel::getEnvironment() === 'development') {
				Kernel::abort_unexpectedly($e);
			}

			SystemMessages::_error(basename(__FILE__) . " line " . __LINE__ . "<br>" . $e->getMessage());

			return null;
		}

		// 0: already existed and no data modification
		if ($stmt->rowCount() == 0) {
			return 0;
		}

		return $stmt->rowCount();
	}

	/**
	 * Insert a row if it does not yet exist, leaving existing rows untouched.
	 *
	 * This is a semantic wrapper around insertOrUpdateHelper(). Callers should
	 * pass only identity/default fields so duplicates become a no-op.
	 *
	 * @param string $table
	 * @param array<string, mixed> $savedata
	 * @param string $dsn
	 * @return int|null Returns 1 on insert, 0 when the row already exists, null on error.
	 */
	public static function insertIfMissingHelper(string $table, array $savedata, string $dsn = ''): ?int
	{
		return self::insertOrUpdateHelper($table, $savedata, $dsn);
	}

	/**
	 * Generates an array of processable fields and their corresponding HTML-filtered values.
	 *
	 * @param string $table The name of the database table.
	 * @param array<string, int|float|string|bool> $savedata An associative array of field-value pairs.
	 * @param string $dsn
	 * @return array<string, int|float|string|bool> The modified savedata array with processable fields and their filtered values.
	 */
	public static function generateProcessableFields(string $table, array $savedata, string $dsn = ''): array
	{
		$processable_fields = Db::getProcessableFields($table, $dsn);

		// check which fields need an HTML filter applied, and if
		// the value for the filtered data field is not provided, then
		// generate it
		foreach ($savedata as $field => $value) {
			if (isset($processable_fields[$field]) && trim((string) $value) != '') {
				$savedata[$processable_fields[$field]] = HtmlProcessor::processHtmlContent($value);
			}
		}

		return $savedata;
	}

	/**
	 * Retrieves the comment from the savedata array and removes it.
	 *
	 * @param array<string, int|float|string|bool> $savedata The savedata array to retrieve the comment from.
	 * @return string|null The comment string, or null if not found or empty.
	 */
	private static function getComment(array &$savedata): ?string
	{
		$comment = null;

		if (isset($savedata['#comment'])) {
			if (trim(strip_tags((string) $savedata['#comment']))) {
				$comment = $savedata['#comment'];
			}

			unset($savedata['#comment']);
		}

		return $comment;
	}

	/**
	 * Normalize scalar values before binding them through PDO.
	 *
	 * Forces booleans into integers so MySQL receives deterministic values.
	 *
	 * @param array<string, mixed> $savedata
	 * @return array<string, mixed>
	 */
	private static function normalizeSavedataValues(array $savedata): array
	{
		foreach ($savedata as $field => $value) {
			if (is_bool($value)) {
				$savedata[$field] = (int)$value;
			}
		}

		return $savedata;
	}

	/**
	 * Updates a row in a table with the given data.
	 *
	 * @param string $table The name of the database table.
	 * @param array<string, int|float|string|bool> $savedata An associative array of field-value pairs to update.
	 * @param mixed $id The ID of the row to update, or null to use the primary key values in $savedata.
	 * @param string $dsn
	 * @return int The number of affected rows.
	 * @throws PDOException on database error
	 */
	public static function updateHelper(string $table, array $savedata, mixed $id = null, string $dsn = ''): int
	{
		$comment = self::getComment($savedata);

		$savedata = self::normalizeSavedataValues($savedata);

		$update_savedata = Db::filterChangedParameters($table, $savedata, $id);

		if (count($update_savedata) == 0) {
			// if there are no changes, save the comment as a regular comment
			if (!is_null($comment)) {
				return EntityComment::addComment($table, $id, $comment);
			}
		}

		$pkeys = Db::getPrimaryKeys($table, $dsn);

		// TODO: Test if working with composite pkeys
		if ($id !== null) {
			$pkey_values = [$pkeys[0] => $id];
		} else {
			$pkey_values = Db::getValuesForPrimaryKeys($pkeys, $savedata);
		}

		foreach ($pkeys as $pkey) {
			unset($update_savedata[$pkey]);
		}

		$update_savedata = DbHelper::generateProcessableFields($table, $update_savedata, $dsn);

		if (count($update_savedata) == 0) {
			return 0;
		}

		//Db::instance($dsn)->beginTransaction();

		$stmt = Db::instance($dsn)
			->prepare("UPDATE {$table} SET " . self::generateEnumeration($update_savedata) . " WHERE " . self::generateEnumerationForSelect($pkey_values));
		$stmt->execute(array_merge(array_values($update_savedata), array_values($pkey_values)));

		if (!is_null($comment)) {
			if (class_exists(Audit::class) && Audit::isAudited($dsn)) {
				$audit_id = Audit::getLastAuditId($dsn);

				if (!is_null($audit_id)) {
					if (class_exists(History::class)) {
						History::addCommentAsAuditTransaction($audit_id, $comment);
					} else {
						EntityComment::addComment($table, $id, $comment);
					}
				}
			} else {
				EntityComment::addComment($table, $id, $comment);
			}
		}

		//Db::instance($dsn)->commit();

		return $stmt->rowCount();
	}

	/**
	 * Deletes a row or rows from a table based on the given ID(s).
	 *
	 * @param string $table The name of the database table.
	 * @param int|array<string, int|string> $id The ID for single primary key or an array of primary key field-value pairs for composite keys.
	 * @param bool $limited_to_one Whether to limit the deletion to a single row (default: true).
	 * @param string $dsn
	 * @return bool True if any rows were deleted, false otherwise.
	 * @throws PDOException on database error
	 */
	public static function deleteHelper(string $table, int|array $id, bool $limited_to_one = true, string $dsn = ''): bool
	{
		if ($id == null) {
			return false;
		}

		$limittext = '';

		if ($limited_to_one) {
			$limittext = ' LIMIT 1';
		}

		$pkeys = Db::getPrimaryKeys($table, $dsn);

		$pkey_values = [];

		if (is_array($id)) {
			foreach ($id as $name => $value) {
				if (!in_array($name, $pkeys)) {
					return false;
				}

				$pkey_values[$name] = $value;
			}
		} else {
			$pkey_values = [$pkeys[0] => $id];
		}

		$stmt = Db::instance($dsn)
			->prepare("DELETE FROM {$table} WHERE " . self::generateEnumerationForSelect($pkey_values) . "{$limittext}");
		$stmt->execute(array_values($pkey_values));

		return $stmt->rowCount() > 0;
	}

	/**
	 * Executes a select-related callback and returns a fallback value on error.
	 *
	 * @template T
	 * @param callable():T $callback
	 * @param T $fallback
	 * @return T
	 */
	private static function _runSelectSafely(callable $callback, mixed $fallback): mixed
	{
		try {
			return $callback();
		} catch (Exception $e) {
			SystemMessages::_error(basename(__FILE__) . " line " . __LINE__ . "<br>" . $e->getMessage());

			return $fallback;
		}
	}

	/**
	 * Builds a select query and its bound values.
	 *
	 * @param string $table
	 * @param string $cols
	 * @param array<string, mixed> $where
	 * @param bool|int $limit
	 * @param string $order_by
	 * @param string $filter_type
	 * @return array{0:string, 1:array<int, mixed>}
	 */
	private static function _buildSelectQueryAndValues(string $table, string $cols, array $where, bool|int $limit, string $order_by, string $filter_type): array
	{
		$limittext = '';

		if ($limit !== false) {
			$limittext = " LIMIT " . (int) $limit;
		}

		$orderbytext = '';

		if (!empty($order_by)) {
			$orderbytext = " ORDER BY $order_by ";
		}

		if (count($where) > 0) {
			return [
				"SELECT $cols FROM {$table} WHERE " . self::generateEnumerationForSelect($where, $filter_type) . "{$orderbytext}{$limittext}",
				array_values($where),
			];
		}

		return [
			"SELECT $cols FROM {$table}{$orderbytext}{$limittext}",
			[],
		];
	}

	/**
	 * Retrieves rows from a table based on the given criteria.
	 *
	 * @param string $table The name of the database table.
	 * @param array<string, mixed> $where An associative array of field-value pairs to filter the rows.
	 * @param bool|int $limit The maximum number of rows to retrieve, or false for no limit.
	 * @param string $order_by The ORDER BY clause for the query.
	 * @param string $cols The columns to retrieve (default: '*' for all columns).
	 * @param string $filter_type The logical operator to use between each $where pair (default: 'AND').
	 * @param string $dsn
	 * @return array<int, array<string, int|float|string|bool>> An array of rows, where each row is an associative array.
	 */
	public static function selectMany(string $table, array $where = [], bool|int $limit = false, string $order_by = '', string $cols = '*', string $filter_type = 'AND', string $dsn = ''): array
	{
		return self::_runSelectSafely(function () use ($table, $where, $limit, $order_by, $cols, $filter_type, $dsn) {
			[$query, $values] = self::_buildSelectQueryAndValues($table, $cols, $where, $limit, $order_by, $filter_type);

			return self::selectManyFromQuery($query, $values, $dsn);
		}, []);
	}

	/**
	 * Retrieves rows from a table based on the given criteria.
	 *
	 * @template T of iEntity
	 * @param string $table The name of the database table.
	 * @param class-string<T> $class_name The name of the class to create.
	 * @param array<string, mixed> $where An associative array of field-value pairs to filter the rows.
	 * @param bool|int $limit The maximum number of rows to retrieve, or false for no limit.
	 * @param string $order_by The ORDER BY clause for the query.
	 * @param string $cols The columns to retrieve (default: '*' for all columns).
	 * @param string $filter_type The logical operator to use between each $where pair (default: 'AND').
	 * @param string $dsn
	 * @return array<int, T> An array of instances of the specified class implementing iEntity.
	 */
	public static function selectManyEntity(string $table, string $class_name, array $where = [], bool|int $limit = false, string $order_by = '', string $cols = '*', string $filter_type = 'AND', string $dsn = ''): array
	{
		return self::_runSelectSafely(function () use ($table, $class_name, $where, $limit, $order_by, $cols, $filter_type, $dsn) {
			[$query, $values] = self::_buildSelectQueryAndValues($table, $cols, $where, $limit, $order_by, $filter_type);

			return self::selectManyEntityFromQuery($class_name, $query, $values, $dsn);
		}, []);
	}

	/**
	 * Retrieves one row from a table based on the given criteria.
	 *
	 * @param string $table The name of the database table.
	 * @param array<string, mixed> $where An associative array of field-value pairs to filter the rows.
	 * @param string $order_by The ORDER BY clause for the query.
	 * @param string $cols The columns to retrieve (default: '*' for all columns).
	 * @param string $filter_type The logical operator to use between each $where pair (default: 'AND').
	 * @param string $dsn
	 * @return array<string, mixed>|null A single row as an associative array, or null if no row is found.
	 */
	public static function selectOne(string $table, array $where = [], string $order_by = '', string $cols = '*', string $filter_type = 'AND', string $dsn = ''): ?array
	{
		return self::_runSelectSafely(function () use ($table, $where, $order_by, $cols, $filter_type, $dsn) {
			[$query, $values] = self::_buildSelectQueryAndValues($table, $cols, $where, 1, $order_by, $filter_type);

			return self::selectOneFromQuery($query, $values, $dsn);
		}, null);
	}

	/**
	 * Retrieves one row from a table based on the given criteria.
	 *
	 * @template T of iEntity
	 * @param string $table The name of the database table.
	 * @param class-string<T> $class_name The name of the class to create.
	 * @param array<string, mixed> $where An associative array of field-value pairs to filter the rows.
	 * @param string $order_by The ORDER BY clause for the query.
	 * @param string $cols The columns to retrieve (default: '*' for all columns).
	 * @param string $filter_type The logical operator to use between each $where pair (default: 'AND').
	 * @param string $dsn
	 * @return T of iEntity|null A single row as an associative array, or null if no row is found.
	 */
	public static function selectOneEntity(string $table, string $class_name, array $where = [], string $order_by = '', string $cols = '*', string $filter_type = 'AND', string $dsn = ''): iEntity|null
	{
		$data = self::_runSelectSafely(function () use ($table, $where, $order_by, $cols, $filter_type, $dsn) {
			[$query, $values] = self::_buildSelectQueryAndValues($table, $cols, $where, 1, $order_by, $filter_type);

			return self::selectOneFromQuery($query, $values, $dsn);
		}, null);

		if ($data === null) {
			return null;
		}

		return $class_name::instantiateFromArray($data);
	}

	/**
	 * Retrieves rows from a table based on the given criteria.
	 *
	 * @param string $table The name of the database table.
	 * @param string $col The column to retrieve.
	 * @param array<string, mixed> $where An associative array of field-value pairs to filter the rows.
	 * @param bool|int $limit The maximum number of rows to retrieve, or false for no limit.
	 * @param string $order_by The ORDER BY clause for the query.
	 * @param string $filter_type The logical operator to use between each $where pair (default: 'AND').
	 * @param string $dsn
	 * @return array<int|float|string|bool> An array with column values.
	 */
	public static function selectManyColumn(string $table, string $col, array $where = [], bool|int $limit = false, string $order_by = '', string $filter_type = 'AND', string $dsn = ''): array
	{
		return self::_runSelectSafely(function () use ($table, $col, $where, $limit, $order_by, $filter_type, $dsn) {
			[$query, $values] = self::_buildSelectQueryAndValues($table, $col, $where, $limit, $order_by, $filter_type);
			$stmt = Db::instance($dsn)->prepare($query);
			$stmt->execute($values);

			return $stmt->fetchAll(PDO::FETCH_COLUMN);
		}, []);
	}

	/**
	 * Retrieves one row from a table based on the given criteria.
	 *
	 * @param string $table The name of the database table.
	 * @param array<string, mixed> $where An associative array of field-value pairs to filter the rows.
	 * @param string $order_by The ORDER BY clause for the query.
	 * @param string $cols The columns to retrieve (default: '*' for all columns).
	 * @param string $filter_type The logical operator to use between each $where pair (default: 'AND').
	 * @param string $dsn
	 * @return mixed A single column (false on failure)
	 */
	public static function selectOneColumn(string $table, array $where = [], string $order_by = '', string $cols = '*', string $filter_type = 'AND', string $dsn = ''): mixed
	{
		return self::_runSelectSafely(function () use ($table, $where, $order_by, $cols, $filter_type, $dsn) {
			[$query, $values] = self::_buildSelectQueryAndValues($table, $cols, $where, 1, $order_by, $filter_type);

			return self::selectOneColumnFromQuery($query, $values, $dsn);
		}, false);
	}

	/**
	 * Swaps the sequence values of two rows in a table.
	 *
	 * @param string $table The name of the database table.
	 * @param int $id1 The ID of the first row to swap.
	 * @param int $id2 The ID of the second row to swap.
	 * @param string $seq_field The name of the sequence field (default: 'seq').
	 * @param string $dsn
	 * @return bool True if the swap was successful, false otherwise.
	 */
	public static function swapHelper(string $table, int $id1, int $id2, string $seq_field = 'seq', string $dsn = ''): bool
	{
		$pkeys = Db::getPrimaryKeys($table, $dsn);

		if (count($pkeys) !== 1) {
			Kernel::abort("swapHelper must have exactly one primary key field on table ($table)");
		}

		$pkey = $pkeys[0];

		$stmt = Db::instance($dsn)
			->prepare("SELECT $seq_field FROM {$table} WHERE " . self::generateEnumerationForSelect([$pkey => $id1]) . " LIMIT 1");

		try {
			$stmt->execute([$id1]);
		} catch (Exception $e) {
			SystemMessages::_error(basename(__FILE__) . " line " . __LINE__ . "<br>" . $e->getMessage());

			return false;
		}

		$seq1 = self::_getSeq($stmt->fetch(PDO::FETCH_ASSOC), $seq_field);

		if (is_null($seq1)) {
			return false;
		}

		unset($stmt);

		$stmt = Db::instance($dsn)
			->prepare("SELECT $seq_field FROM {$table} WHERE " . self::generateEnumerationForSelect([$pkey => $id2]) . " LIMIT 1");

		try {
			$stmt->execute([$id2]);
		} catch (Exception $e) {
			SystemMessages::_error(basename(__FILE__) . " line " . __LINE__ . "<br>" . $e->getMessage());

			return false;
		}

		$seq2 = self::_getSeq($stmt->fetch(PDO::FETCH_ASSOC), $seq_field);

		if (is_null($seq2)) {
			return false;
		}

		unset($stmt);

		Db::instance($dsn)->beginTransaction();

		$stmt = Db::instance($dsn)
			->prepare("UPDATE {$table} SET $seq_field=? WHERE " . self::generateEnumerationForSelect([$pkey => $id1]) . " LIMIT 1");

		try {
			$stmt->execute([
				$seq2,
				$id1,
			]);
		} catch (Exception $e) {
			SystemMessages::_error(basename(__FILE__) . " line " . __LINE__ . "<br>" . $e->getMessage());

			return false;
		}

		$stmt = Db::instance($dsn)
			->prepare("UPDATE {$table} SET $seq_field=? WHERE " . self::generateEnumerationForSelect([$pkey => $id2]) . " LIMIT 1");

		try {
			$stmt->execute([
				$seq1,
				$id2,
			]);
		} catch (Exception $e) {
			SystemMessages::_error(basename(__FILE__) . " line " . __LINE__ . "<br>" . $e->getMessage());

			return false;
		}

		Db::instance($dsn)->commit();

		return $stmt->rowCount() > 0;
	}

	/**
	 * Extracts the row names from a query result set, excluding names starting with '__'.
	 *
	 * @param array<int, array<string, mixed>>|false $rs The query result set or false on failure.
	 * @return array<int, string> An array of row names.
	 */
	public static function extractRowNamesFromResultSet(array|false $rs): array
	{
		if (empty($rs)) {
			return [];
		}

		return array_values(
			array_filter(
				array_keys($rs[0]),
				fn ($row_name) => mb_strpos($row_name, '__') !== 0
			)
		);
	}

	/**
	 * Executes a custom query and returns the result set.
	 *
	 * @param string $query The SQL query to execute.
	 * @param array<int|string, int|float|string|bool> $values An array of values to bind to the query.
	 * @param string $dsn
	 * @return array<int, array<string, int|float|string|bool>> The query result set.
	 */
	public static function selectManyFromQuery(string $query, array $values = [], string $dsn = ''): array
	{
		$stmt = Db::instance($dsn)->prepare($query);
		$stmt->execute($values);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Executes a custom query and returns the result set.
	 *
	 * @template T
	 * @param class-string<T> $class_name The name of the class to create.
	 * @param string $query The SQL query to execute.
	 * @param array<int|string, int|float|string|bool> $values An array of values to bind to the query.
	 * @param string $dsn
	 * @return array<int, T> The query result set.
	 */
	public static function selectManyEntityFromQuery(string $class_name, string $query, array $values = [], string $dsn = ''): array
	{
		$stmt = Db::instance($dsn)->prepare($query);
		$stmt->execute($values);

		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		return array_map(
			fn ($row) => $class_name::instantiateFromArray($row),
			$rows
		);
	}

	/**
	 * Executes a custom query and returns a single column from the result set.
	 *
	 * @param string $query The SQL query to execute.
	 * @param array<int, int|float|string|bool> $values An array of values to bind to the query.
	 * @param string $dsn
	 * @return int|float|string|bool|null The first column of the first row of the query result set, or null if no rows are returned.
	 */
	public static function selectOneColumnFromQuery(string $query, array $values = [], string $dsn = ''): mixed
	{
		$stmt = Db::instance($dsn)->prepare($query);
		$stmt->execute($values);

		return $stmt->fetch(PDO::FETCH_COLUMN);
	}

	/**
	 * Executes a custom query and returns a single column from the result set.
	 *
	 * @param string $query The SQL query to execute.
	 * @param array<int, int|float|string|bool> $values An array of values to bind to the query.
	 * @param string $dsn
	 * @return array<string, int|float|string|bool>|null The first row of the query result set, or null if no rows are returned.
	 */
	public static function selectOneFromQuery(string $query, array $values = [], string $dsn = ''): ?array
	{
		$stmt = Db::instance($dsn)->prepare($query);
		$stmt->execute($values);

		$result = $stmt->fetch(PDO::FETCH_ASSOC);

		return $result === false ? null : $result;
	}

	/**
	 * Executes a custom query and returns the number of affected rows.
	 *
	 * @param string $query The SQL query to execute.
	 * @param array<int, int|float|string|bool> $values An array of values to bind to the query.
	 * @param string $dsn
	 * @return int The number of affected rows.
	 */
	public static function runCustomQuery(string $query, array $values = [], string $dsn = ''): int
	{
		$stmt = Db::instance($dsn)->prepare($query);
		$stmt->execute($values);

		return $stmt->rowCount();
	}

	/**
	 * Counts rows in a table based on the given criteria.
	 *
	 * @param string $table The name of the database table.
	 * @param array<string, mixed> $where An associative array of field-value pairs to filter the rows.
	 * @param string $filter_type The logical operator to use between each $where pair (default: 'AND').
	 * @param string $dsn
	 * @return int The number of rows matching the criteria, or 0 if an exception occurs.
	 */
	public static function count(string $table, array $where = [], string $filter_type = 'AND', string $dsn = ''): int
	{
		try {
			if (count($where) > 0) {
				return DbHelper::selectOneColumnFromQuery(
					query: "SELECT COUNT(1) FROM {$table} WHERE " . self::generateEnumerationForSelect($where, $filter_type),
					values: array_values($where),
					dsn: $dsn
				);
			} else {
				return DbHelper::selectOneColumnFromQuery(
					query: "SELECT COUNT(1) FROM {$table}",
					dsn: $dsn
				);
			}
		} catch (Exception $e) {
			SystemMessages::_error(basename(__FILE__) . " line " . __LINE__ . "<br>" . $e->getMessage());

			return 0;
		}
	}
}
