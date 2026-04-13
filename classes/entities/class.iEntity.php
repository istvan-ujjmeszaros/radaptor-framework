<?php

/**
 * Interface iEntity.
 *
 * Defines the contract for entity classes.
 */
interface iEntity
{
	/**
	 * Retrieves multiple entities based on specified criteria.
	 *
	 * @param array<string, mixed> $criteria An associative array of column-value pairs for filtering.
	 * @param string $order_by The column name to order the results by.
	 * @param string $filter_type The type of filter to apply ('AND' or 'OR').
	 *
	 * @return array<static> An array of instances of the inheriting class.
	 */
	public static function findMany(array $criteria = [], string $order_by = '', string $filter_type = 'AND'): array;

	/**
	 * Retrieves the first entity matching the specified criteria.
	 *
	 * @param array<string, mixed> $criteria An associative array of column-value pairs for filtering.
	 * @param string $order_by The column name to order the results by.
	 * @param string $filter_type The type of filter to apply ('AND' or 'OR').
	 *
	 * @return static|null An instance of the inheriting class if found, or null if no matching entity is found.
	 */
	public static function findFirst(array $criteria = [], string $order_by = '', string $filter_type = 'AND'): ?static;

	/**
	 * Retrieves an entity by its primary key(s).
	 *
	 * @param int|array<string, mixed> $id The primary key value(s). Can be a single integer for tables with a single primary key,
	 *                                     or an associative array of column names and values for composite primary keys.
	 *
	 * @return static|null The entity with the given identifier, or null if not found.
	 * @throws PDOException If the primary key name is invalid.
	 */
	public static function findById(int|array $id): ?static;

	/**
	 * Retrieves an array of values from a specific column for all matching entities.
	 *
	 * @param string $key The name of the column to pluck values from.
	 * @param array<string, mixed> $criteria An associative array of column-value pairs for filtering.
	 * @param string $order_by The column name to order the results by.
	 * @param string $filter_type The type of filter to apply ('AND' or 'OR').
	 *
	 * @return array<int, mixed> An array of values from the specified column for all matching entities.
	 */
	public static function pluckAll(string $key, array $criteria = [], string $order_by = '', string $filter_type = 'AND'): array;

	/**
	 * Retrieves the first value from a specific column for the matching entity.
	 *
	 * @param string $key The name of the column to pluck the value from.
	 * @param array<string, mixed> $criteria An associative array of column-value pairs for filtering.
	 * @param string $order_by The column name to order the results by.
	 * @param string $filter_type The type of filter to apply ('AND' or 'OR').
	 *
	 * @return mixed The value from the specified column for the first matching entity.
	 */
	public static function pluckFirst(string $key, array $criteria = [], string $order_by = '', string $filter_type = 'AND'): mixed;

	/**
	 * Executes a custom SQL query and returns an array of entity objects.
	 *
	 * @param string $query The custom SQL query to execute.
	 * @param array<int|string, int|float|string|bool> $param_values An associative array of parameter names and their corresponding values.
	 *
	 * @return array<static> An array of entity objects, representing the query results.
	 */
	public static function customQuery(string $query, array $param_values = []): array;

	/**
	 * Counts the number of entities matching the given criteria.
	 *
	 * @param array<string, mixed> $criteria An associative array of column-value pairs for filtering.
	 *
	 * @return int The number of entities matching the criteria.
	 */
	public static function count(array $criteria = []): int;

	/**
	 * Save data to database and return entity.
	 * Uses upsert semantics based on primary key presence in data:
	 * - INSERT if primary key not present in data
	 * - UPDATE if primary key present in data
	 * Throws EntitySaveException on persistence errors.
	 *
	 * @param array<string, mixed> $data An array of entity data to save.
	 * @return static The saved entity (with auto-generated ID populated for inserts).
	 * @throws EntitySaveException on persistence errors
	 */
	public static function saveFromArray(array $data): static;

	/**
	 * Create a new entity from data.
	 * Throws EntitySaveException on persistence errors.
	 *
	 * @param array<string, mixed> $data An array of entity data to save.
	 * @return static The saved entity (with auto-generated ID populated for inserts).
	 * @throws EntitySaveException on persistence errors
	 */
	public static function createFromArray(array $data): static;

	/**
	 * Update an existing entity by primary key and return updated entity.
	 *
	 * @param int|array<string, mixed> $id The primary key value(s).
	 * @param array<string, mixed> $data The changed entity data.
	 * @return static
	 * @throws EntitySaveException on persistence errors
	 */
	public static function updateById(int|array $id, array $data): static;

	/**
	 * Factory method to create an instance of a subclass from an associative array of data.
	 *
	 * @param array<string, mixed> $data An associative array where keys are property names.
	 *
	 * @return static
	 */
	public static function instantiateFromArray(array $data): static;

	/**
	 * Deletes one or more entities from the database.
	 *
	 * @param int|array<string, int|string> $id The ID or an array of primary key field-value pairs to delete.
	 * @return bool True if the entity was deleted successfully, false otherwise.
	 */
	public static function delete(int|array $id): bool;
}
