<?php

/**
 * @template TData of array
 * Abstract Class SQLEntity.
 *
 * The `SQLEntity` class serves as a base class for all entity models that map to database tables.
 * It provides common functionality for interacting with the database, including methods for
 * retrieving, creating, updating, and deleting records. This class also offers a standard
 * structure for defining entity-specific behavior in derived classes.
 *
 * ## Usage Example:
 *
 * ```php
 * /**
 *  * @phpstan-type ShapeEntityUser array{
 *  *     user_id?: int,
 *  *     username: string,
 *  *     is_active?: bool,
 *  * }
 *  *
 *  * @extends SQLEntity<ShapeEntityUser>
 *  * @property ?int $user_id
 *  * @property ?string $username
 *  * @property ?bool $is_active
 *  * /
 * class EntityUser extends SQLEntity {
 *     public const string TABLE_NAME = 'users';
 * }
 *
 * // Create and save
 * $user = new EntityUser(['username' => 'johndoe', 'is_active' => true]);
 * $user->save();
 *
 * // Find and update
 * $existingUser = EntityUser::findById(1);
 * $existingUser->username = 'johnsmith';
 * $existingUser->update();
 * ```
 *
 * @phpstan-consistent-constructor
 * @abstract
 */

abstract class SQLEntity implements iEntity
{
	protected const string TABLE_NAME = '';

	/** @var TData Primary storage for entity data */
	private array $__data = [];

	/**
	 * SQLEntity constructor.
	 *
	 * @param TData $data Associative array of property values to initialize the entity.
	 */
	public function __construct(array $data)
	{
		$this->__data = $data;
	}

	/**
	 * Magic getter for property access
	 * Allows accessing array data as properties: $entity->key.
	 *
	 * If the entity has a primary key set (loaded from DB), accessing a missing
	 * property is a development error and will abort. For new entities (no PK),
	 * missing properties return null.
	 */
	public function __get(string $name): mixed
	{
		if (array_key_exists($name, $this->__data)) {
			return $this->__data[$name];
		}

		// Key doesn't exist - check if this entity was loaded from DB
		$pkeys = Db::getPrimaryKeys(static::getTableName());

		// If primary key is set, entity was loaded - missing property is a bug
		if (count($pkeys) > 0 && isset($this->__data[$pkeys[0]])) {
			Kernel::abort_unexpectedly(new RuntimeException(
				"Accessing uninitialized property '$name' on " . static::class
			));
		}

		// New entity (no PK set), null is expected
		return null;
	}

	/**
	 * Magic setter for property access
	 * Allows setting array data as properties: $entity->key = value.
	 */
	public function __set(string $name, mixed $value): void
	{
		$this->__data[$name] = $value;
	}

	/**
	 * Magic isset check for property access
	 * Allows checking if array key exists: isset($entity->key).
	 */
	public function __isset(string $name): bool
	{
		return isset($this->__data[$name]);
	}

	/**
	 * Get the entity data array for direct manipulation
	 * Returns by reference to allow array operations like: $entity->data()['key'] = 'value'.
	 *
	 * @return TData
	 */
	public function &data(): array
	{
		return $this->__data;
	}

	/**
	 * Get a copy of the entity data for safe passing (Data Transfer Object)
	 * Returns a copy, not a reference - modifications won't affect the entity.
	 *
	 * @return TData
	 */
	public function dto(): array
	{
		return $this->__data;
	}

	/**
	 * Merge data into the entity and persist it in one call.
	 *
	 * @param TData $data Data to merge into entity before save.
	 * @return static
	 */
	public function patch(array $data): static
	{
		foreach ($data as $key => $value) {
			$this->__data[$key] = $value;
		}

		return $this->save();
	}

	/**
	 * Magic unset for property access
	 * Allows removing array key: unset($entity->key).
	 */
	public function __unset(string $name): void
	{
		unset($this->__data[$name]);
	}

	/**
	 * Check if a key exists in entity data
	 * Unlike isset(), returns true even if value is null.
	 */
	public function has(string $key): bool
	{
		return array_key_exists($key, $this->__data);
	}

	/**
	 * Get all keys present in entity data.
	 * @return array<string>
	 */
	public function keys(): array
	{
		return array_keys($this->__data);
	}

	/**
	 * Check if entity has no data.
	 */
	public function isEmpty(): bool
	{
		return empty($this->__data);
	}

	/**
	 * Save the current entity to the database (insert or update).
	 * Returns $this after saving for method chaining.
	 *
	 * @return static
	 */
	public function save(): static
	{
		$data = $this->dto();
		$saved = static::saveFromArray($data);

		// Copy the ID back if it was an insert
		$pkeys = Db::getPrimaryKeys(static::getTableName());

		if (count($pkeys) === 1) {
			$this->__data[$pkeys[0]] = $saved->__data[$pkeys[0]];
		}

		return $this;
	}

	/**
	 * Update the current entity in the database.
	 * Returns $this after updating for method chaining.
	 * Same as save() - both methods detect insert vs update based on primary key presence.
	 *
	 * @return static
	 */
	public function update(): static
	{
		return $this->save();
	}

	/**
	 * Returns the table name associated with the current entity.
	 *
	 * This method fetches the value of the TABLE_NAME constant defined in the child class.
	 * If the constant is not defined or is empty, it triggers an abort operation.
	 *
	 * @return string The name of the database table for the current entity.
	 */
	public static function getTableName(): string
	{
		$table_name = constant(static::class . '::TABLE_NAME');

		if (empty($table_name)) {
			Kernel::abort('The TABLE_NAME constant is not defined for the entity: ' . static::class);
		}

		return $table_name;
	}

	/**
	 * {@inheritDoc}
	 */
	public static function findMany(array $criteria = [], string $order_by = '', string $filter_type = 'AND'): array
	{
		return DbHelper::selectManyEntity(
			table: static::getTableName(),
			class_name: static::class,
			where: $criteria,
			order_by: $order_by,
			filter_type: $filter_type
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public static function findFirst(array $criteria = [], string $order_by = '', string $filter_type = 'AND'): ?static
	{
		return DbHelper::selectOneEntity(
			table: static::getTableName(),
			class_name: static::class,
			where: $criteria,
			order_by: $order_by,
			filter_type: $filter_type
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public static function findById(int|array $id): ?static
	{
		$pkeys = Db::getPrimaryKeys(static::getTableName());

		$pkey_values = [];

		if (is_array($id)) {
			foreach ($id as $name => $value) {
				if (!in_array(
					$name,
					$pkeys
				)) {
					throw new PDOException("Invalid primary key name '$name' for table '" . static::getTableName() . "'");
				}

				$pkey_values[$name] = $value;
			}
		} else {
			$pkey_values = [$pkeys[0] => $id];
		}

		return DbHelper::selectOneEntity(
			table: static::getTableName(),
			class_name: static::class,
			where: $pkey_values
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public static function pluckAll(string $key, array $criteria = [], string $order_by = '', string $filter_type = 'AND'): array
	{
		return DbHelper::selectManyColumn(
			table: static::getTableName(),
			col: $key,
			where: $criteria,
			order_by: $order_by,
			filter_type: $filter_type
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public static function pluckFirst(string $key, array $criteria = [], string $order_by = '', string $filter_type = 'AND'): mixed
	{
		return DbHelper::selectOneColumn(
			table: static::getTableName(),
			where: $criteria,
			order_by: $order_by,
			cols: $key,
			filter_type: $filter_type
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public static function customQuery(string $query, array $param_values = []): array
	{
		return DbHelper::selectManyEntityFromQuery(
			class_name: static::class,
			query: $query,
			values: $param_values
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public static function count(array $criteria = []): int
	{
		return DbHelper::count(
			table: static::getTableName(),
			where: $criteria
		);
	}

	/**
	 * Resolve primary key values from explicit id input.
	 *
	 * @param int|array<string, mixed> $id
	 * @return array<string, mixed>
	 */
	private static function _idToPrimaryKeyValues(int|array $id): array
	{
		$table = static::getTableName();
		$pkeys = Db::getPrimaryKeys($table);

		if (is_array($id)) {
			$pkey_values = [];

			foreach ($id as $name => $value) {
				if (!in_array($name, $pkeys, true)) {
					throw new EntitySaveException(
						"Invalid primary key name '{$name}' for table '{$table}'",
						static::class,
						['id' => $id]
					);
				}

				$pkey_values[$name] = $value;
			}

			return $pkey_values;
		}

		return [$pkeys[0] => $id];
	}

	/**
	 * Core upsert logic based on primary key presence in data.
	 *
	 * @param TData $data
	 * @return static
	 * @throws EntitySaveException
	 */
	protected static function _doSave(array $data): static
	{
		$table = static::getTableName();
		$pkeys = Db::getPrimaryKeys($table);
		$hasPrimaryKey = count($pkeys) > 0 && array_all(
			$pkeys,
			fn ($key) => isset($data[$key])
		);

		try {
			if ($hasPrimaryKey) {
				DbHelper::updateHelper(table: $table, savedata: $data);

				$entity = static::findById((array) array_intersect_key($data, array_flip($pkeys)));

				if ($entity !== null) {
					return $entity;
				}

				return new static($data);
			}

			$insertedId = DbHelper::insertHelper(table: $table, savedata: $data);
			$entity = new static($data);

			if (count($pkeys) === 1) {
				$entity->{$pkeys[0]} = $insertedId;
			}

			return $entity;
		} catch (PDOException $e) {
			throw new EntitySaveException(
				$e->getMessage(),
				static::class,
				$data,
				$e
			);
		}
	}

	/**
	 * {@inheritDoc}
	 * @param TData $data
	 */
	public static function saveFromArray(array $data): static
	{
		return static::_doSave($data);
	}

	/**
	 * {@inheritDoc}
	 * @param TData $data
	 */
	public static function createFromArray(array $data): static
	{
		$table = static::getTableName();
		$pkeys = Db::getPrimaryKeys($table);

		foreach ($pkeys as $key) {
			// Preserve caller-supplied non-null PKs (composite tables, non-auto-increment).
			// Only strip null/missing PKs so the DB can auto-generate the value.
			if (array_key_exists($key, $data) && $data[$key] !== null) {
				continue;
			}
			unset($data[$key]);
		}

		try {
			$insertedId = DbHelper::insertHelper(table: $table, savedata: $data);
		} catch (PDOException $e) {
			throw new EntitySaveException(
				$e->getMessage(),
				static::class,
				$data,
				$e
			);
		}

		$entity = new static($data);

		// Only set the auto-generated ID when one was actually returned (non-zero).
		// Caller-supplied PKs are already in $data passed to the constructor above.
		if (count($pkeys) === 1 && !empty($insertedId)) {
			$entity->{$pkeys[0]} = $insertedId;
		}

		return $entity;
	}

	/**
	 * {@inheritDoc}
	 * @param int|array<string, mixed> $id
	 * @param TData $data
	 */
	public static function updateById(int|array $id, array $data): static
	{
		$table = static::getTableName();
		$pkey_values = self::_idToPrimaryKeyValues($id);
		$entity = static::findById($id);

		if ($entity === null) {
			throw new EntitySaveException(
				'Entity not found for update',
				static::class,
				['id' => $id, 'data' => $data]
			);
		}

		$save_data = array_merge($entity->dto(), $data);
		$save_data = array_merge($save_data, $pkey_values);

		try {
			DbHelper::updateHelper(
				table: $table,
				savedata: $save_data,
				id: is_int($id) ? $id : null
			);
		} catch (PDOException $e) {
			throw new EntitySaveException(
				$e->getMessage(),
				static::class,
				$save_data,
				$e
			);
		}

		$updated = static::findById($id);

		if ($updated === null) {
			throw new EntitySaveException(
				'Entity was updated but not found afterwards',
				static::class,
				['id' => $id]
			);
		}

		return $updated;
	}

	/**
	 * Get the auto-increment primary key value.
	 * Only works for tables with a single auto-increment primary key.
	 *
	 * @return int
	 */
	public function pkey(): int
	{
		$table = static::getTableName();
		$pkeys = Db::getPrimaryKeys($table);

		if (count($pkeys) !== 1) {
			Kernel::abort('pkey() only works with simple (non-composite) primary keys');
		}

		$pkColumn = $pkeys[0];
		$tableData = DbSchemaData::getTableData($table);

		if ($tableData === null || !$tableData['is_auto_increment']) {
			Kernel::abort('pkey() only works with auto-increment primary keys');
		}

		return $this->__data[$pkColumn] ?? 0;
	}

	/**
	 * {@inheritDoc}
	 * @param TData $data
	 */
	public static function instantiateFromArray(array $data): static
	{
		return new static($data);
	}

	/**
	 * {@inheritDoc}
	 */
	public static function delete(int|array $id): bool
	{
		return DbHelper::deleteHelper(
			table: static::getTableName(),
			id: $id
		);
	}
}
