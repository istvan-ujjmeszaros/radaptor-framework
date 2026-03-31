<?php

/**
 * Fixtures - Loads test fixture data from fixture classes.
 *
 * Discovers fixture classes in tests/fixtures/, resolves dependencies,
 * and loads data in the correct order.
 *
 * Features:
 * - Automatic dependency resolution with topological sort
 * - Tree structure support via '_' key (nested set with lft/rgt/parent_id)
 * - Reference system: $referenceBy property + @table.refname syntax
 *
 * Reference System:
 * - Fixtures can define $referenceBy = 'column' to auto-generate refs
 * - After insert, refs are stored as 'table.column_value' => pk
 * - Other fixtures use '@table.refname' to get the actual PK
 */
class Fixtures
{
	/** @var array<string, AbstractFixture> Cached fixture instances */
	private static array $instances = [];

	/** @var array<string, int|string> Reference map: 'table.refname' => pk_value */
	private static array $refs = [];

	/**
	 * Loads all fixtures in dependency order.
	 */
	public static function loadAll(): void
	{
		$pdo = Db::instance();

		// Clear refs at start of each load
		self::$refs = [];

		// Disable foreign key checks during fixture loading
		$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

		try {
			// Discover and instantiate all fixture classes
			$fixtures = self::discoverFixtures();

			// Topologically sort by dependencies
			$sorted = self::sortByDependencies($fixtures);

			// Truncate tables in reverse order (dependents first)
			foreach (array_reverse($sorted) as $fixture) {
				$table = $fixture->getTableName();
				$pdo->exec("TRUNCATE TABLE `{$table}`");
			}

			// Load data in dependency order
			foreach ($sorted as $fixture) {
				self::loadFixture($fixture, $pdo);
			}
		} finally {
			$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
		}
	}

	/**
	 * Gets a fixture instance by class name.
	 *
	 * @template T of AbstractFixture
	 * @param class-string<T> $className
	 * @return T
	 */
	public static function get(string $className): AbstractFixture
	{
		if (!isset(self::$instances[$className])) {
			self::$instances[$className] = new $className();
		}

		return self::$instances[$className];
	}

	/**
	 * Gets a stored reference value.
	 *
	 * @param string $ref Reference key (e.g., 'users.admin_developer')
	 * @return int|string The primary key value
	 * @throws RuntimeException If reference doesn't exist
	 */
	public static function getRef(string $ref): int|string
	{
		if (!isset(self::$refs[$ref])) {
			throw new RuntimeException("Unknown fixture reference: @{$ref}");
		}

		return self::$refs[$ref];
	}

	/**
	 * Gets all stored references (for debugging).
	 *
	 * @return array<string, int|string>
	 */
	public static function getAllRefs(): array
	{
		return self::$refs;
	}

	/**
	 * Discovers all fixture classes in tests/fixtures/.
	 *
	 * Fixture files follow naming convention: Fixture.Name.php
	 * Class name is derived by removing dots: FixtureName
	 *
	 * @return list<AbstractFixture>
	 */
	private static function discoverFixtures(): array
	{
		$fixtures = [];

		// Calculate fixtures directory relative to the application root
		// The tests/fixtures/ path should be in the app directory
		$fixturesDir = dirname(__DIR__, 4) . '/tests/fixtures';

		foreach (glob($fixturesDir . '/Fixture.*.php') as $file) {
			// Fixture.Users.php -> FixtureUsers
			$fileName = basename($file, '.php');
			$className = str_replace('.', '', $fileName);

			// Require the file if not already loaded
			if (!class_exists($className)) {
				require_once $file;
			}

			$fixtures[] = self::get($className);
		}

		return $fixtures;
	}

	/**
	 * Topologically sorts fixtures by their dependencies.
	 *
	 * @param list<AbstractFixture> $fixtures
	 * @return list<AbstractFixture>
	 */
	private static function sortByDependencies(array $fixtures): array
	{
		$sorted = [];
		$visited = [];
		$visiting = [];

		// Build a map of class name -> fixture
		$fixtureMap = [];

		foreach ($fixtures as $fixture) {
			$fixtureMap[$fixture::class] = $fixture;
		}

		// Visit each fixture
		foreach ($fixtures as $fixture) {
			self::visit($fixture, $fixtureMap, $visited, $visiting, $sorted);
		}

		return $sorted;
	}

	/**
	 * Recursive DFS visit for topological sort.
	 *
	 * @param AbstractFixture $fixture
	 * @param array<string, AbstractFixture> $fixtureMap
	 * @param array<string, bool> $visited
	 * @param array<string, bool> $visiting
	 * @param list<AbstractFixture> $sorted
	 */
	private static function visit(
		AbstractFixture $fixture,
		array $fixtureMap,
		array &$visited,
		array &$visiting,
		array &$sorted
	): void {
		$className = $fixture::class;

		if (isset($visited[$className])) {
			return;
		}

		if (isset($visiting[$className])) {
			throw new RuntimeException("Circular dependency detected involving {$className}");
		}

		$visiting[$className] = true;

		// Visit dependencies first
		foreach ($fixture->getDependencies() as $depClass) {
			if (isset($fixtureMap[$depClass])) {
				self::visit($fixtureMap[$depClass], $fixtureMap, $visited, $visiting, $sorted);
			}
		}

		unset($visiting[$className]);
		$visited[$className] = true;
		$sorted[] = $fixture;
	}

	/**
	 * Loads a single fixture's data into the database.
	 */
	private static function loadFixture(AbstractFixture $fixture, PDO $pdo): void
	{
		$table = $fixture->getTableName();
		$rows = $fixture->getData();
		$referenceBy = $fixture->getReferenceBy();

		if (empty($rows)) {
			return;
		}

		// Validate $referenceBy column has UNIQUE constraint
		if ($referenceBy !== '') {
			self::validateUniqueColumn($pdo, $table, $referenceBy);
		}

		// Check if this is nested tree data (has '_' key)
		if (self::isTreeData($rows)) {
			$rows = self::flattenTree($rows, $table, $referenceBy);
		} else {
			// For non-tree data, resolve refs and insert normally
			$rows = self::insertRows($rows, $table, $referenceBy, $pdo);

			return;
		}

		// Tree data has already been inserted by flattenTree
	}

	/**
	 * Inserts rows and stores references.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @param string $table
	 * @param string $referenceBy
	 * @param PDO $pdo
	 * @return list<array<string, mixed>>
	 */
	private static function insertRows(array $rows, string $table, string $referenceBy, PDO $pdo): array
	{
		if (empty($rows)) {
			return $rows;
		}

		// Get column names from first row (exclude '_' meta key)
		$columns = array_filter(array_keys($rows[0]), fn ($c) => $c !== '_');
		$columnList = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));

		$sql = "INSERT INTO `{$table}` ({$columnList}) VALUES ({$placeholders})";
		$stmt = $pdo->prepare($sql);

		foreach ($rows as $row) {
			// Resolve @table.ref references
			$row = self::resolveRefs($row);

			// Extract ref name before inserting
			$refName = self::extractRefName($row, $referenceBy);

			// Filter out '_' key and get values in column order
			$values = [];

			foreach ($columns as $col) {
				$values[] = $row[$col] ?? null;
			}
			$stmt->execute($values);

			// Store reference if we have a ref name
			if ($refName !== null && $refName !== '') {
				$pk = $pdo->lastInsertId();
				self::$refs["{$table}.{$refName}"] = (int) $pk;
			}
		}

		return $rows;
	}

	/**
	 * Validates that $referenceBy column has a UNIQUE constraint.
	 *
	 * @throws RuntimeException If column doesn't have UNIQUE constraint
	 */
	private static function validateUniqueColumn(PDO $pdo, string $table, string $column): void
	{
		$stmt = $pdo->prepare(
			'SELECT COUNT(*) FROM information_schema.STATISTICS
			 WHERE TABLE_SCHEMA = DATABASE()
			 AND TABLE_NAME = ?
			 AND COLUMN_NAME = ?
			 AND NON_UNIQUE = 0'
		);
		$stmt->execute([$table, $column]);
		$count = (int) $stmt->fetchColumn();

		if ($count === 0) {
			throw new RuntimeException(
				"Fixture {$table}: \$referenceBy column '{$column}' must have a UNIQUE constraint"
			);
		}
	}

	/**
	 * Extract ref name from row based on $referenceBy column.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function extractRefName(array $row, string $referenceBy): ?string
	{
		if ($referenceBy === '') {
			return null;
		}

		if (!array_key_exists($referenceBy, $row)) {
			return null;
		}

		$value = $row[$referenceBy];

		if ($value === null) {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Resolves @table.ref references in row values.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function resolveRefs(array $row): array
	{
		foreach ($row as $key => $value) {
			if (is_string($value) && str_starts_with($value, '@')) {
				$refKey = substr($value, 1);

				if (!isset(self::$refs[$refKey])) {
					throw new RuntimeException("Unknown fixture reference: @{$refKey}");
				}
				$row[$key] = self::$refs[$refKey];
			}
		}

		return $row;
	}

	/**
	 * Checks if fixture data contains nested tree structure.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 */
	private static function isTreeData(array $rows): bool
	{
		foreach ($rows as $row) {
			if (isset($row['_'])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Flattens nested tree data and calculates lft/rgt values.
	 * Inserts rows as it traverses to get real PKs for parent_id.
	 *
	 * Tree structure uses '_' key for children:
	 * ['title' => 'Root', '_' => [
	 *     ['title' => 'Child'],
	 * ]]
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param string $table
	 * @param string $referenceBy
	 * @return list<array<string, mixed>>
	 */
	private static function flattenTree(array $rows, string $table, string $referenceBy): array
	{
		$pdo = Db::instance();
		$flat = [];
		$counter = 0;

		foreach ($rows as $row) {
			self::flattenAndInsertNode($row, 0, $counter, $flat, $table, $referenceBy, $pdo);
		}

		return $flat;
	}

	/**
	 * Recursively flattens a tree node and its children, inserting as we go.
	 *
	 * @param array<string, mixed> $node
	 * @param int $parentId
	 * @param int $counter Current lft/rgt counter (passed by reference)
	 * @param array<int, array<string, mixed>> $flat Flattened output (passed by reference)
	 * @param string $table
	 * @param string $referenceBy
	 * @param PDO $pdo
	 */
	private static function flattenAndInsertNode(
		array $node,
		int $parentId,
		int &$counter,
		array &$flat,
		string $table,
		string $referenceBy,
		PDO $pdo
	): void {
		$children = $node['_'] ?? [];
		unset($node['_']);

		// Resolve @table.ref references in node values
		$node = self::resolveRefs($node);

		// Set lft
		$counter++;
		$node['lft'] = $counter;

		// Set parent_id
		$node['parent_id'] = $parentId;

		// Calculate rgt (need to account for all children first)
		// We'll update it after processing children
		$lft = $counter;

		// Process children first to calculate rgt
		$childNodes = [];

		foreach ($children as $child) {
			// Recursively process, but don't insert yet
			$childData = self::processChildForRgt($child, $counter);
			$childNodes[] = $childData;
		}

		// Now we know rgt
		$counter++;
		$node['rgt'] = $counter;

		// Extract ref name before inserting
		$refName = self::extractRefName($node, $referenceBy);

		// Insert this node
		$columns = array_keys($node);
		$columnList = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$sql = "INSERT INTO `{$table}` ({$columnList}) VALUES ({$placeholders})";
		$stmt = $pdo->prepare($sql);
		$stmt->execute(array_values($node));

		$nodeId = (int) $pdo->lastInsertId();

		// Store reference
		if ($refName !== null && $refName !== '') {
			self::$refs["{$table}.{$refName}"] = $nodeId;
		}

		$flat[] = $node;

		// Now insert children with correct parent_id
		$childCounter = $lft;

		foreach ($children as $child) {
			self::flattenAndInsertNode($child, $nodeId, $childCounter, $flat, $table, $referenceBy, $pdo);
		}
	}

	/**
	 * Process child node to calculate its rgt contribution without inserting.
	 *
	 * @param array<string, mixed> $node
	 * @param int $counter
	 * @return array{node: array<string, mixed>, rgt: int}
	 */
	private static function processChildForRgt(array $node, int &$counter): array
	{
		$children = $node['_'] ?? [];
		$counter++; // lft

		foreach ($children as $child) {
			self::processChildForRgt($child, $counter);
		}

		$counter++; // rgt

		return ['node' => $node, 'rgt' => $counter];
	}
}
