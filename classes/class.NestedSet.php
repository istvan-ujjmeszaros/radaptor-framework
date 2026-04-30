<?php

/**
 * Low-level nested-set primitive.
 *
 * AI/agent rule: do not call this class directly from application, event, or controller code for
 * concrete business trees such as `resource_tree`. Always go through the owning business-layer
 * service (for example `ResourceTreeHandler`) so ACL, lifecycle hooks, path rebuilds, attribute
 * handling, file cleanup, and other side effects stay correct. Generic tree diagnostics/repair
 * utilities may call this primitive directly.
 */
class NestedSet
{
	public const int MAX_LEVEL = 128;

	private const array STRUCTURAL_FIELDS = [
		'lft' => true,
		'rgt' => true,
		'parent_id' => true,
	];

	/** @var array<string, mixed> */
	public static array $debug = [];

	/**
	 * @return array<string, string>
	 */
	public static function getTreeTableChoices(string $dsn = ''): array
	{
		$choices = [];

		foreach (Db::getNestedSetTables($dsn) as $table) {
			$key = str_ends_with($table, '_tree') ? substr($table, 0, -5) : $table;
			$choices[$key] = $table;
		}

		return $choices;
	}

	public static function resolveTreeTable(string $tree, string $dsn = ''): ?string
	{
		$tree = trim($tree);

		if ($tree === '') {
			return null;
		}

		if (Db::isNestedSetTable($tree, $dsn)) {
			return $tree;
		}

		$choices = self::getTreeTableChoices($dsn);

		if (isset($choices[$tree])) {
			return $choices[$tree];
		}

		$candidate = $tree . '_tree';

		return Db::isNestedSetTable($candidate, $dsn) ? $candidate : null;
	}

	public static function isStructuralField(string $field): bool
	{
		return isset(self::STRUCTURAL_FIELDS[strtolower($field)]);
	}

	/**
	 * @param array<string, mixed> $savedata
	 * @return list<string>
	 */
	public static function getStructuralFieldsInSavedata(array $savedata): array
	{
		$fields = [];

		foreach (array_keys($savedata) as $field) {
			if (self::isStructuralField((string) $field)) {
				$fields[] = (string) $field;
			}
		}

		return $fields;
	}

	/**
	 * @param string $table
	 * @param int $node_id
	 * @param bool $include_self
	 * @return array<int, array<string, mixed>>
	 */
	public static function getNodePath(string $table, int $node_id, bool $include_self = true): array
	{
		$path = [];

		$current_id = $node_id;

		$i = 0;

		do {
			++$i;

			if (++$i >= self::MAX_LEVEL) {
				Kernel::abort("Max hierarchy iterations level exceeded with level {$i}!");
			}

			$data = self::getNodeInfo($table, $current_id);

			// TODO: test this function
			if ($i == 2 && !is_array($data) || !isset($data['parent_id'])) {
				return [];
			}

			if ($data['node_id'] != $node_id || $include_self) {
				$path[] = $data;
			}

			$current_id = $data['parent_id'];
		} while ($current_id != 0);

		return array_reverse($path);
	}

	/**
	 * Get children of a node.
	 *
	 * @param string $table
	 * @param int $parent_id
	 * @param array<string> $extra_data_fields
	 * @param string $order_by
	 *
	 * @return array
	 */
	public static function getChildren(
		string $table,
		int $parent_id,
		array $extra_data_fields = [
			'node_name',
			'node_type',
		],
		string $order_by = 'lft ASC'
	): array {
		if (count($extra_data_fields) > 1) {
			$extra_data_fields = ', ' . implode(', ', $extra_data_fields);
		} elseif (count($extra_data_fields) == 1) {
			$extra_data_fields = ', ' . $extra_data_fields[0];
		} else {
			$extra_data_fields = '';
		}

		$order_by_text = '';

		if ($order_by !== '') {
			$order_by_text = ' ORDER BY ' . $order_by;
		}

		$query = "
SELECT node_id, lft, rgt, parent_id{$extra_data_fields}
FROM {$table}
WHERE
parent_id = ?
{$order_by_text}
";

		return DbHelper::selectManyFromQuery(
			$query,
			[
				$parent_id,
			]
		);
	}

	/**
	 * Get descendants of a node.
	 *
	 * @param string $table
	 * @param int $parent_id
	 * @param array<string> $extra_data_fields
	 * @param string $order_by
	 *
	 * @return array
	 */
	public static function getDescendants(
		string $table,
		int $parent_id,
		array $extra_data_fields = [
			'node_name',
			'node_type',
		],
		string $order_by = 'lft ASC'
	): array {
		if (count($extra_data_fields) > 1) {
			$extra_data_fields = ', ' . implode(', ', $extra_data_fields);
		} elseif (count($extra_data_fields) == 1) {
			$extra_data_fields = ', ' . $extra_data_fields[0];
		} else {
			$extra_data_fields = '';
		}

		$order_by_text = '';

		if ($order_by !== '') {
			$order_by_text = ' ORDER BY ' . $order_by;
		}

		$this_node_info = NestedSet::getNodeInfo($table, $parent_id);

		$query = "
SELECT node_id, lft, rgt, parent_id{$extra_data_fields}
FROM {$table}
WHERE
lft>=? AND rgt<=?
{$order_by_text}
";

		return DbHelper::selectManyFromQuery(
			$query,
			[
				$this_node_info['lft'],
				$this_node_info['rgt'],
			]
		);
	}

	/**
	 * @param string $table
	 * @param int $node_id
	 * @param int $fetch_mode
	 * @return array<string, mixed>|null
	 */
	public static function getNodeInfo(string $table, int $node_id, int $fetch_mode = PDO::FETCH_ASSOC): ?array
	{
		$cache_key = $table . $node_id . $fetch_mode;

		if ($node_id == 0) {
			return null;
		}

		$cached = Cache::get(self::class, $cache_key);

		if (!is_null($cached)) {
			return ($cached === false) ? null : $cached;
		}

		$stmt = Db::instance()->prepare("
			SELECT * FROM {$table}
			WHERE
			node_id = ?
			LIMIT 1
		");

		$stmt->execute([$node_id]);
		$rs = $stmt->fetch($fetch_mode);

		Cache::set(self::class, $cache_key, $rs);

		return ($rs === false) ? null : $rs;
	}

	/**
	 * Retrieves the 'lft' value for a given node from the specified database table.
	 *
	 * This function fetches the 'lft' column value for the specified node ID from the given table.
	 * If the 'lft' value is NULL or the query returns no rows, the function will return null.
	 * This allows the caller to handle the absence of a value explicitly.
	 *
	 * @param string $table The name of the database table to query.
	 * @param int $node_id The ID of the node for which to retrieve the 'lft' value.
	 * @return int|null The 'lft' value for the specified node, or null if the value is NULL or not found.
	 */
	public static function getLft(string $table, int $node_id): ?int
	{
		$query = "SELECT lft FROM {$table} WHERE node_id = ? LIMIT 1";

		$stmt = Db::instance()->prepare($query);
		$stmt->execute([$node_id]);

		$rs = $stmt->fetch(PDO::FETCH_ASSOC);

		return $rs['lft'] ?? null;
	}

	/**
	 * Wrap existing rootless tree rows under a new root node.
	 *
	 * @param string $table
	 * @param array<string, mixed> $savedata
	 */
	public static function wrapExistingTreeWithRoot(string $table, array $savedata): ?int
	{
		if (!Db::isNestedSetTable($table)) {
			throw new InvalidArgumentException("Table '{$table}' is not a nested-set table.");
		}

		$structural_fields = self::getStructuralFieldsInSavedata($savedata);

		if ($structural_fields !== []) {
			throw new InvalidArgumentException('Root savedata must not contain nested-set structural fields: ' . implode(', ', $structural_fields));
		}

		$pdo = Db::instance();
		$started_transaction = !$pdo->inTransaction();

		try {
			if ($started_transaction) {
				$pdo->beginTransaction();
			}

			$max_rgt = (int) DbHelper::selectOneColumnFromQuery(
				"SELECT COALESCE(MAX(rgt), 0) FROM {$table}"
			);

			$stmt = $pdo->prepare("UPDATE {$table} SET lft = lft + 1, rgt = rgt + 1");
			$stmt->execute();

			$root_savedata = [
				'lft' => 1,
				'rgt' => $max_rgt + 2,
				'parent_id' => 0,
			] + $savedata;

			$insert_root = $pdo->prepare(
				"INSERT INTO {$table} SET " . DbHelper::generateEnumeration($root_savedata)
			);
			$insert_root->execute(array_values($root_savedata));
			$root_id = (int) $pdo->lastInsertId();

			$update_children = $pdo->prepare(
				"UPDATE {$table} SET parent_id = ? WHERE parent_id = 0 AND node_id <> ?"
			);
			$update_children->execute([$root_id, $root_id]);

			$report = self::analyzeConsistency($table);

			if (!$report['ok']) {
				throw new RuntimeException("Nested-set wrap left {$table} inconsistent.");
			}

			if ($started_transaction) {
				$pdo->commit();
			}
		} catch (Throwable $exception) {
			if ($started_transaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}

			error_log("NestedSet wrapExistingTreeWithRoot failed for {$table}: " . $exception->getMessage());

			return null;
		}

		Cache::flush();

		return $root_id;
	}

	/**
	 * @param string $table
	 * @param int $ref_id
	 * @param array<string, mixed> $savedata
	 * @return int|null
	 */
	public static function addNode(string $table, int $ref_id, array $savedata): ?int
	{
		// NULL means there was no parent so the new node should have lft=1
		$lft = self::getLft($table, $ref_id) ?? 1;
		$pdo = Db::instance();
		$started_transaction = !$pdo->inTransaction();

		try {
			if ($started_transaction) {
				$pdo->beginTransaction();
			}

			$stmt = $pdo
					  ->prepare("SELECT @myRight := rgt FROM {$table} WHERE node_id = ? AND lft = ? LIMIT 1");
			$stmt->execute([
				$ref_id,
				$lft,
			]);

			$rs = $stmt->fetch(PDO::FETCH_ASSOC);

			if ($rs !== false) {
				// Not inserted at the first level, move subsequent nodes

				/* Increment the nodes by two */
				$stmt = $pdo->prepare("UPDATE {$table} SET rgt = rgt + 2 WHERE rgt >= @myRight");
				$stmt->execute();
				$stmt = $pdo->prepare("UPDATE {$table} SET lft = lft + 2 WHERE lft > @myRight");
				$stmt->execute();

				/* Insert the new node */
				$stmt = $pdo
						  ->prepare("INSERT INTO {$table} SET lft=@myRight, rgt=@myRight + 1, parent_id=?," . DbHelper::generateEnumeration($savedata));
			} else {
				// Inserted at the first level, add to the beginning of the tree

				/* Increment the nodes by two */
				$stmt = $pdo->prepare("UPDATE {$table} SET rgt = rgt + 2 WHERE rgt >= 2");
				$stmt->execute();
				$stmt = $pdo->prepare("UPDATE {$table} SET lft = lft + 2 WHERE lft >= 1");
				$stmt->execute();

				/* Insert the new node */
				$stmt = $pdo
						  ->prepare("INSERT INTO {$table} SET lft=1, rgt=2, parent_id=?, " . DbHelper::generateEnumeration($savedata));
			}

			$stmt->execute(array_values([$ref_id] + $savedata));
			$last_id = $pdo->lastInsertId();

			/* Commit the transaction */
			if ($started_transaction) {
				$pdo->commit();
			}
		} catch (Exception) {
			if ($started_transaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}

			return null;
		}

		Cache::flush();

		return (int)$last_id;
	}

	/**
	 * @param string $table
	 * @param int $node_id
	 * @return bool
	 */
	public static function deleteNode(string $table, int $node_id): bool
	{
		try {
			$stmt = Db::instance()->prepare("SELECT lft, rgt, parent_id FROM {$table} WHERE node_id = ? LIMIT 1");
			$stmt->execute([$node_id]);

			$rs = $stmt->fetch(PDO::FETCH_ASSOC);

			if ($rs === false) {
				return false;
			}

			$lft = $rs['lft'];
			$rgt = $rs['rgt'];
			$parent_id = $rs['parent_id'];

			Db::instance()->beginTransaction();

			$stmt = Db::instance()->prepare("DELETE FROM {$table} WHERE node_id = ? LIMIT 1");
			$stmt->execute([$node_id]);

			if ($rgt - $lft == 1) {
				// deleting a leaf
				// decrease the following ones by two
				$stmt = Db::instance()->prepare("UPDATE {$table} SET rgt = rgt-2 WHERE rgt > ?");
				$stmt->execute([$rgt]);
				$stmt = Db::instance()->prepare("UPDATE {$table} SET lft = lft-2 WHERE lft > ?");
				$stmt->execute([$lft]);
			} else {
				// deleting a node
				// decrease the ones below it by one
				$stmt = Db::instance()
						  ->prepare("UPDATE {$table} SET lft = lft-1, rgt = rgt-1 WHERE lft > ? AND lft < ?");
				$stmt->execute([
					$lft,
					$rgt,
				]);

				// and decrease the rest following it by two
				$stmt = Db::instance()->prepare("UPDATE {$table} SET rgt = rgt-2 WHERE rgt > ?");
				$stmt->execute([$rgt]);
				$stmt = Db::instance()->prepare("UPDATE {$table} SET lft = lft-2 WHERE lft > ?");
				$stmt->execute([$rgt]);

				// and update the parent_id on direct children (grandparent inherits the children)
				$stmt = Db::instance()->prepare("UPDATE {$table} SET parent_id = ? WHERE parent_id = ?");
				$stmt->execute([
					$parent_id,
					$node_id,
				]);
			}

			Db::instance()->commit();
		} catch (Exception) {
			Db::instance()->rollBack();

			return false;
		}

		Cache::flush();

		return true;
	}

	/**
	 * Deletes an entire branch.
	 *
	 * @param string $table
	 * @param int $node_id
	 * @return int
	 */
	public static function deleteNodeRecursive(string $table, int $node_id): int
	{
		// TODO: still needs to be tested, as so far I've only tried with a node in the root,
		// and it should be tested with a branch further down to ensure
		// the lft and rgt values don't get misaligned...
		try {
			Db::instance()->beginTransaction();

			$stmt1 = Db::instance()->prepare("SELECT lft, rgt, parent_id FROM {$table} WHERE node_id = ? LIMIT 1");
			$stmt1->execute([$node_id]);

			$rs = $stmt1->fetch(PDO::FETCH_ASSOC);

			if ($rs === false) {
				return 0;
			}

			$lft = $rs['lft'];
			$rgt = $rs['rgt'];

			$stmt3 = Db::instance()->prepare("DELETE FROM {$table} WHERE lft>=$lft AND rgt<=$rgt");
			$stmt3->execute();

			$offset = $rgt - $lft + 1;
			$stmt4 = Db::instance()->prepare("UPDATE {$table} nt SET lft=lft-$offset WHERE lft>$lft");
			$stmt4->execute();
			$stmt5 = Db::instance()->prepare("UPDATE {$table} nt SET rgt=rgt-$offset WHERE rgt>$rgt");
			$stmt5->execute();

			Db::instance()->commit();
		} catch (Exception) {
			Db::instance()->rollBack();

			return 0;
		}

		Cache::flush();

		return $stmt3->rowCount();
	}

	/**
	 * @param string $table
	 * @param int $id
	 * @return array<string, mixed>|null
	 */
	public static function getData(string $table, int $id): ?array
	{
		return NestedSet::getNodeInfo($table, $id);
	}

	/**
	 * @param string $table
	 * @param int $node_id
	 * @param int $parent_id
	 * @param int $position
	 * @return bool
	 */
	public static function moveToPosition(string $table, int $node_id, int $parent_id, int $position): bool
	{
		$children = self::getChildren($table, $parent_id, []);

		if (count($children) == 0) {
			$ref_node_id = $parent_id;
			$move_type = 'inside';

			return self::move($table, $node_id, $ref_node_id, $move_type);
		}

		if ($position == 0) {
			$ref_node_id = $children[0]['node_id'];
			$move_type = 'before';

			return self::move($table, $node_id, $ref_node_id, $move_type);
		}

		if (count($children) <= $position) {
			$ref_node_id = $children[count($children) - 1]['node_id'];
			$move_type = 'after';

			return self::move($table, $node_id, $ref_node_id, $move_type);
		}

		$ref_node_id = $children[$position - 1]['node_id'];
		$move_type = 'after';

		return self::move($table, $node_id, $ref_node_id, $move_type);
	}

	/**
	 * Move a node in the nested set structure.
	 *
	 * @param string $table The name of the database table.
	 * @param int $node_id The ID of the node to be moved.
	 * @param int $ref_node_id The ID of the reference node.
	 * @param string $move_type The type of move operation ('inside', 'before', or 'after').
	 * @return bool True if the move was successful, false otherwise.
	 */
	public static function move(string $table, int $node_id, int $ref_node_id, string $move_type): bool
	{
		// if we want to move it into itself, we don't move
		if ($node_id == $ref_node_id) {
			// moving into itself is not good
			self::$debug[] = "DEBUG: Moving inside self is impossible!";

			return false;
		}

		// there were problems when dragging something to the root, so in this case
		// we convert the move to moving before the first element
		if ($ref_node_id == 0) {
			$query = "SELECT node_id FROM {$table} WHERE lft = (SELECT MIN(lft) FROM {$table}) LIMIT 1";

			$stmt = Db::instance()->prepare($query);
			$stmt->execute([]);

			$rs = $stmt->fetch(PDO::FETCH_ASSOC);

			$ref_node_id = $rs['node_id'];
			$move_type = 'before';
		}

		if (!in_array($move_type, [
			'inside',
			'before',
			'after',
		])) {
			// unknown move_type
			self::$debug[] = "DEBUG: unknown move_type: {$move_type}";

			return false;
		}

		/**
		 * @var array{node_id: int, parent_id: int, lft: int, rgt: int} $moved_node
		 */
		$moved_node = self::getData($table, $node_id);

		/**
		 * @var array{node_id: int, parent_id: int, lft: int, rgt: int} $ref_node
		 */
		$ref_node = self::getData($table, $ref_node_id);

		// if we want to move it to where it already is
		if ($move_type == 'inside' && ($moved_node['parent_id'] == $ref_node_id)) {
			self::$debug[] = "DEBUG: Moving inside is only allowed from outside of the parents scope. Have to use position = 0 instead!";

			return false;
		}

		$moved_lft = $moved_node['lft'];
		$moved_rgt = $moved_node['rgt'];
		$offset = $moved_rgt - $moved_lft + 1;

		$ref_lft = $ref_node['lft'];
		$ref_rgt = $ref_node['rgt'];

		/** @var array<string> $sql */
		$sql = [];

		$sql[] = "UPDATE {$table} SET lft = lft - $offset WHERE lft > $moved_rgt";

		$sql[] = "UPDATE {$table} SET rgt = rgt - $offset WHERE rgt > $moved_rgt";

		if ($ref_lft > $moved_rgt) {
			$ref_lft -= $offset;
		}

		if ($ref_rgt > $moved_rgt) {
			$ref_rgt -= $offset;
		}

		$stmt = Db::instance()->prepare("SELECT node_id FROM {$table} WHERE lft >= ? AND rgt <= ?");
		$stmt->execute([
			$moved_lft,
			$moved_rgt,
		]);

		/** @var array<int> $ids */
		$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

		$new_parent_id = false;

		switch ($move_type) {
			case "before":
				$new_parent_id = $ref_node['parent_id'];

				$sql[] = "UPDATE {$table} SET lft = lft + " . $offset . " WHERE lft >= " . $ref_lft . " AND node_id NOT IN(" . implode(",", $ids) . ") ";
				$sql[] = "UPDATE {$table} SET rgt = rgt + " . $offset . " WHERE rgt > " . $ref_lft . " AND node_id NOT IN(" . implode(",", $ids) . ") ";

				$offset = $ref_lft - $moved_lft;

				$sql[] = "UPDATE {$table} SET lft = lft + (" . $offset . "), rgt = rgt + (" . $offset . ") WHERE node_id IN (" . implode(",", $ids) . ") ";

				break;

			case "after":

				$new_parent_id = $ref_node['parent_id'];

				$sql[] = "UPDATE {$table} SET lft = lft + " . $offset . " WHERE lft > " . $ref_rgt . " AND node_id NOT IN(" . implode(",", $ids) . ") ";
				$sql[] = "UPDATE {$table} SET rgt = rgt + " . $offset . " WHERE rgt > " . $ref_rgt . " AND node_id NOT IN(" . implode(",", $ids) . ") ";

				$offset = ($ref_rgt + 1) - $moved_lft;

				$sql[] = "UPDATE {$table} SET lft = lft + (" . $offset . "), rgt = rgt + (" . $offset . ") WHERE node_id IN (" . implode(",", $ids) . ") ";

				break;

			case "inside":

				$new_parent_id = $ref_node['node_id'];

				$sql[] = "UPDATE {$table} SET lft = lft + $offset WHERE lft > $ref_lft AND node_id NOT IN(" . implode(",", $ids) . ") ";
				$sql[] = "UPDATE {$table} SET rgt = rgt + $offset WHERE rgt > $ref_lft AND node_id NOT IN(" . implode(",", $ids) . ") ";

				$offset = ($ref_lft + 1) - $moved_lft;

				$sql[] = "UPDATE {$table} SET lft = lft + ($offset), rgt = rgt + ($offset) WHERE node_id IN (" . implode(",", $ids) . ") ";

				break;
		}

		$sql[] = "UPDATE {$table} SET parent_id = $new_parent_id WHERE node_id = $moved_node[node_id]";

		foreach ($sql as $query) {
			$stmt = Db::instance()->prepare($query);
			$stmt->execute();
		}

		Cache::flush();

		return true;
	}

	/**
	 * Rebuilds the path for nodes in the given table.
	 *
	 * @param string $table The name of the table.
	 * @param int $from_node_id The ID of the node to start rebuilding from. Default is 0.
	 * @return int The number of nodes updated.
	 */
	public static function rebuildPath(string $table, int $from_node_id = 0): int
	{
		/** @var array{node_id: int, lft: int, rgt: int}|null $node_info */
		$node_info = NestedSet::getData($table, $from_node_id);

		if (is_null($node_info)) {
			$query = "SELECT node_id, node_type FROM {$table} ORDER BY lft";

			$stmt = Db::instance()->prepare($query);
			$stmt->execute();
		} else {
			$query = "SELECT node_id, node_type FROM {$table} WHERE lft>=? AND rgt<=? ORDER BY lft";

			$stmt = Db::instance()->prepare($query);
			$stmt->execute([
				$node_info['lft'],
				$node_info['rgt'],
			]);
		}

		/** @var array<array{node_id: int, node_type: string}> $updatable_nodes */
		$updatable_nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$i = 0;

		foreach ($updatable_nodes as $node) {
			if ($node['node_type'] == 'catalog') {
				continue;
			}
			++$i;

			/** @var array{path: string, node_id: int} $savedata */
			$savedata = [
				'path' => self::generatePath($table, $node['node_id']),
				'node_id' => $node['node_id'],
			];

			DbHelper::updateHelper($table, $savedata);
		}

		return $i;
	}

	/**
	 * Generates a path string for a given node in a nested set structure.
	 *
	 * @param string $table The name of the database table containing the nested set.
	 * @param int $node_id The ID of the node for which to generate the path.
	 * @param bool $include_self Whether to include the current node in the path.
	 * @return string The generated path string.
	 */
	public static function generatePath(string $table, int $node_id, bool $include_self = false): string
	{
		/** @var array<int, array{node_id: int, node_type: string, resource_name: string, path: string}> $path_array */
		$path_array = NestedSet::getNodePath($table, $node_id);

		if (!$include_self) {
			unset($path_array[count($path_array) - 1]);
		}

		$path = '/';

		foreach ($path_array as $path_element) {
			if (!in_array($path_element['node_type'], [
				'catalog',
				'root',
			])) {
				$path .= $path_element['resource_name'] . '/';
			} else {
				$path .= $path_element['path'] . '/';
			}
		}

		while (mb_strpos($path, '//') !== false) {
			$path = str_replace('//', '/', $path);
		}

		return $path;
	}

	/**
	 * Recompute lft/rgt from parent_id links and current sibling order.
	 *
	 * @return array{
	 *     table: string,
	 *     dry_run: bool,
	 *     applied: bool,
	 *     node_count: int,
	 *     before: array<string, mixed>,
	 *     after?: array<string, mixed>,
	 *     planned_updates: int,
	 *     updates: list<array{node_id: int, old_lft: int, old_rgt: int, new_lft: int, new_rgt: int}>,
	 *     issues: list<array{code: string, message: string, node_id?: int, other_node_id?: int}>,
	 *     ok: bool
	 * }
	 */
	public static function repairConsistencyFromParentLinks(string $table, bool $dry_run = true): array
	{
		if (!Db::isNestedSetTable($table)) {
			throw new InvalidArgumentException("Table '{$table}' is not a nested-set table.");
		}

		$before = self::analyzeConsistency($table);
		$rows = DbHelper::selectManyFromQuery(
			"SELECT node_id, parent_id, lft, rgt FROM {$table} ORDER BY lft ASC, rgt ASC, node_id ASC"
		);
		$by_id = [];
		$children = [0 => []];
		$issues = [];

		foreach ($rows as $row) {
			$node_id = (int) $row['node_id'];
			$parent_id = (int) $row['parent_id'];

			$by_id[$node_id] = [
				'node_id' => $node_id,
				'parent_id' => $parent_id,
				'lft' => (int) $row['lft'],
				'rgt' => (int) $row['rgt'],
			];

			$children[$parent_id] ??= [];
			$children[$parent_id][] = $node_id;
		}

		foreach ($by_id as $node_id => $row) {
			$parent_id = $row['parent_id'];

			if ($parent_id !== 0 && !isset($by_id[$parent_id])) {
				$issues[] = [
					'code' => 'missing_parent',
					'message' => "Node {$node_id} points to missing parent {$parent_id}.",
					'node_id' => $node_id,
					'other_node_id' => $parent_id,
				];
			}
		}

		foreach ($children as &$child_ids) {
			usort(
				$child_ids,
				static fn (int $left, int $right): int => [
					$by_id[$left]['lft'] ?? PHP_INT_MAX,
					$by_id[$left]['rgt'] ?? PHP_INT_MAX,
					$left,
				] <=> [
					$by_id[$right]['lft'] ?? PHP_INT_MAX,
					$by_id[$right]['rgt'] ?? PHP_INT_MAX,
					$right,
				]
			);
		}
		unset($child_ids);

		if (count($rows) > 0 && ($children[0] ?? []) === []) {
			$issues[] = [
				'code' => 'missing_root',
				'message' => "{$table} has no root-level node with parent_id=0.",
			];
		}

		$visit_state = [];
		$new_bounds = [];
		$counter = 1;
		$visit = function (int $node_id) use (&$visit, &$visit_state, &$new_bounds, &$counter, &$children, &$issues): void {
			if (($visit_state[$node_id] ?? '') === 'visiting') {
				$issues[] = [
					'code' => 'cycle_detected',
					'message' => "Cycle detected at node {$node_id}.",
					'node_id' => $node_id,
				];

				return;
			}

			if (($visit_state[$node_id] ?? '') === 'visited') {
				return;
			}

			$visit_state[$node_id] = 'visiting';
			$lft = $counter++;

			foreach ($children[$node_id] ?? [] as $child_id) {
				$visit((int) $child_id);
			}

			$rgt = $counter++;
			$new_bounds[$node_id] = [
				'lft' => $lft,
				'rgt' => $rgt,
			];
			$visit_state[$node_id] = 'visited';
		};

		if ($issues === []) {
			foreach ($children[0] ?? [] as $root_id) {
				$visit((int) $root_id);
			}
		}

		if ($issues === []) {
			foreach (array_keys($by_id) as $node_id) {
				if (($visit_state[$node_id] ?? '') !== 'visited') {
					$issues[] = [
						'code' => 'unreachable_node',
						'message' => "Node {$node_id} is not reachable from a root-level node.",
						'node_id' => $node_id,
					];
				}
			}
		}

		$updates = [];

		if ($issues === []) {
			foreach ($by_id as $node_id => $row) {
				$new_lft = $new_bounds[$node_id]['lft'];
				$new_rgt = $new_bounds[$node_id]['rgt'];

				if ($row['lft'] === $new_lft && $row['rgt'] === $new_rgt) {
					continue;
				}

				$updates[] = [
					'node_id' => $node_id,
					'old_lft' => $row['lft'],
					'old_rgt' => $row['rgt'],
					'new_lft' => $new_lft,
					'new_rgt' => $new_rgt,
				];
			}
		}

		$result = [
			'table' => $table,
			'dry_run' => $dry_run,
			'applied' => false,
			'node_count' => count($rows),
			'before' => $before,
			'planned_updates' => count($updates),
			'updates' => $updates,
			'issues' => $issues,
			'ok' => $issues === [] && (bool) ($before['ok'] ?? false),
		];

		if ($issues !== [] || $dry_run || $updates === []) {
			$result['after'] = $before;

			return $result;
		}

		$pdo = Db::instance();
		$started_transaction = !$pdo->inTransaction();

		try {
			if ($started_transaction) {
				$pdo->beginTransaction();
			}

			$stmt = $pdo->prepare("UPDATE {$table} SET lft = ?, rgt = ? WHERE node_id = ?");

			foreach ($updates as $update) {
				$stmt->execute([
					$update['new_lft'],
					$update['new_rgt'],
					$update['node_id'],
				]);
			}

			if ($started_transaction) {
				$pdo->commit();
			}
		} catch (Throwable $exception) {
			if ($started_transaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}

			throw $exception;
		}

		Cache::flush();

		$result['applied'] = true;
		$result['after'] = self::analyzeConsistency($table);
		$result['ok'] = (bool) $result['after']['ok'];

		return $result;
	}

	/**
	 * Gets the last child node ID or the parent ID if no children exist.
	 *
	 * @param string $table The name of the database table.
	 * @param int $parent_id The ID of the parent node.
	 * @return int The ID of the last child node or the parent ID.
	 */
	public static function getLastChildOrSelfNodeId(string $table, int $parent_id): int
	{
		$query = "SELECT node_id FROM {$table} WHERE parent_id = ? ORDER BY lft DESC LIMIT 1";

		$stmt = Db::instance()->prepare($query);
		$stmt->execute([$parent_id]);

		/** @var array{node_id: int}|false $rs */
		$rs = $stmt->fetch(PDO::FETCH_ASSOC);

		return $rs['node_id'] ?? $parent_id;
	}

	/**
	 * Get the node ID of the first child or the parent itself if no children exist.
	 *
	 * @param string $table The name of the database table.
	 * @param int $parent_id The ID of the parent node.
	 * @return int The node ID of the first child or the parent itself.
	 */
	public static function getFirstChildOrSelfNodeId(string $table, int $parent_id): int
	{
		$query = "SELECT node_id FROM {$table} WHERE parent_id = ? ORDER BY lft ASC LIMIT 1";

		$stmt = Db::instance()->prepare($query);
		$stmt->execute([$parent_id]);

		/** @var array{node_id: int}|false $rs */
		$rs = $stmt->fetch(PDO::FETCH_ASSOC);

		return $rs['node_id'] ?? $parent_id;
	}

	/**
	 * Get node data for a specific node in a nested set table.
	 *
	 * @param string $table The name of the table containing the nested set data.
	 * @param int $node_id The ID of the node to retrieve data for.
	 * @return array<string, int>|null An array containing 'lft', 'rgt', and 'parent_id' as integers, or null if not found.
	 */
	public static function getNodeData(string $table, int $node_id): ?array
	{
		$query = "SELECT lft, rgt, parent_id FROM {$table} WHERE node_id = ? LIMIT 1";

		$stmt = Db::instance()->prepare($query);
		$stmt->execute([$node_id]);

		$rs = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($rs !== false) {
			return $rs;
		} else {
			return null;
		}
	}

	/**
	 * Get parent nodes for a given node in a nested set table.
	 *
	 * @param string $table The name of the database table.
	 * @param int $node_id The ID of the node to get parents for.
	 * @return array<int, array{node_id: int}>|null An array of parent nodes, each containing 'node_id', or null if node not found.
	 */
	public static function getParentNodes(string $table, int $node_id): ?array
	{
		$node_data = self::getNodeData($table, $node_id);

		if (is_null($node_data)) {
			return null;
		}

		$query = "
            SELECT
            node_id
            FROM
            {$table}
            WHERE lft <= ?
            AND rgt >= ?
            ORDER BY rgt - lft ASC";

		$stmt = Db::instance()->prepare($query);
		$stmt->execute([
			$node_data['lft'],
			$node_data['rgt'],
		]);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Retrieves the tree structure from the specified table.
	 *
	 * @param string $table The name of the table to query.
	 * @param string $classname Optional. The name of the class to use for object hydration.
	 * @return array<int, array<string, mixed>|object> An array of nodes, where the key is the node_id and the value is either an associative array or an object of the specified class.
	 */
	public static function getTree(string $table, string $classname = ''): array
	{
		$query = "SELECT * FROM {$table} ORDER BY lft";

		$stmt = Db::instance()->prepare($query);
		$stmt->execute();

		$rs = [];

		if ($classname == '') {
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$rs[$row['node_id']] = $row;
			}
		} else {
			while ($row = $stmt->fetchObject($classname)) {
				$rs[$row->node_id] = $row;
			}
		}

		return $rs;
	}

	/**
	 * Build JS tree data for user groups.
	 *
	 * @param string $id_prefix The prefix for the node ID.
	 * @param string $title Title for the injected root node.
	 * @return array {
	 *           0: array {
	 *             attr: array {
	 *               id: string,
	 *               rel: string
	 *             },
	 *             state: string,
	 *             data: array {
	 *               title: string
	 *             }
	 *	         }
	 *	       }
	 */
	public static function buildJsTreeDataForRoot(string $id_prefix, string $title): array
	{
		return [
			[
				'attr' => [
					'id' => $id_prefix . '_' . '0',
					'rel' => 'root',
				],
				'state' => 'closed',
				'data' => [
					'title' => $title,
				],
			],
		];
	}

	/**
	 * @return array{
	 *     table: string,
	 *     node_count: int,
	 *     issues: list<array{code: string, message: string, node_id?: int, other_node_id?: int, value?: int}>,
	 *     ok: bool
	 * }
	 */
	public static function analyzeConsistency(string $table): array
	{
		$rows = DbHelper::selectManyFromQuery(
			"SELECT node_id, parent_id, lft, rgt FROM {$table} ORDER BY lft ASC, node_id ASC"
		);
		$issues = [];
		$by_id = [];
		$seen_values = [];

		foreach ($rows as $row) {
			$node_id = (int) $row['node_id'];
			$parent_id = (int) $row['parent_id'];
			$lft = (int) $row['lft'];
			$rgt = (int) $row['rgt'];

			$by_id[$node_id] = [
				'node_id' => $node_id,
				'parent_id' => $parent_id,
				'lft' => $lft,
				'rgt' => $rgt,
			];

			if ($lft >= $rgt) {
				$issues[] = [
					'code' => 'invalid_range',
					'message' => "Node {$node_id} must satisfy lft < rgt.",
					'node_id' => $node_id,
				];
			}

			foreach (['lft' => $lft, 'rgt' => $rgt] as $value) {
				if (isset($seen_values[$value])) {
					$issues[] = [
						'code' => 'duplicate_boundary',
						'message' => "Boundary value {$value} is duplicated between node {$seen_values[$value]} and {$node_id}.",
						'node_id' => $node_id,
						'other_node_id' => $seen_values[$value],
						'value' => $value,
					];
				} else {
					$seen_values[$value] = $node_id;
				}
			}
		}

		$node_count = count($rows);
		$expected_max = $node_count * 2;

		if ($node_count > 0) {
			for ($value = 1; $value <= $expected_max; ++$value) {
				if (!isset($seen_values[$value])) {
					$issues[] = [
						'code' => 'missing_boundary',
						'message' => "Boundary value {$value} is missing from {$table}.",
						'value' => $value,
					];
				}
			}

			foreach ($seen_values as $value => $node_id) {
				$value = (int) $value;

				if ($value <= $expected_max) {
					continue;
				}

				$issues[] = [
					'code' => 'overflow_boundary',
					'message' => "Boundary value {$value} exceeds expected max {$expected_max} in {$table}.",
					'node_id' => $node_id,
					'value' => $value,
				];
			}
		}

		foreach ($by_id as $node_id => $row) {
			$parent_id = $row['parent_id'];

			if ($parent_id === 0) {
				continue;
			}

			if ($parent_id === $node_id) {
				$issues[] = [
					'code' => 'self_parent',
					'message' => "Node {$node_id} cannot be its own parent.",
					'node_id' => $node_id,
				];

				continue;
			}

			if (!isset($by_id[$parent_id])) {
				$issues[] = [
					'code' => 'missing_parent',
					'message' => "Node {$node_id} points to missing parent {$parent_id}.",
					'node_id' => $node_id,
					'other_node_id' => $parent_id,
				];

				continue;
			}

			$parent = $by_id[$parent_id];

			if ($parent['lft'] >= $row['lft'] || $parent['rgt'] <= $row['rgt']) {
				$issues[] = [
					'code' => 'parent_range_mismatch',
					'message' => "Parent {$parent_id} does not properly contain node {$node_id}.",
					'node_id' => $node_id,
					'other_node_id' => $parent_id,
				];
			}
		}

		$sorted = array_values($by_id);
		usort(
			$sorted,
			static fn (array $a, array $b): int => [$a['lft'], $a['rgt'], $a['node_id']] <=> [$b['lft'], $b['rgt'], $b['node_id']]
		);

		for ($i = 0; $i < count($sorted); ++$i) {
			for ($j = $i + 1; $j < count($sorted); ++$j) {
				$a = $sorted[$i];
				$b = $sorted[$j];

				if ($b['lft'] > $a['rgt']) {
					break;
				}

				$intersects = $b['lft'] < $a['rgt'] && $b['rgt'] > $a['lft'];
				$a_contains_b = $a['lft'] < $b['lft'] && $a['rgt'] > $b['rgt'];
				$b_contains_a = $b['lft'] < $a['lft'] && $b['rgt'] > $a['rgt'];

				if ($intersects && !$a_contains_b && !$b_contains_a) {
					$issues[] = [
						'code' => 'illegal_overlap',
						'message' => "Nodes {$a['node_id']} and {$b['node_id']} overlap illegally.",
						'node_id' => $a['node_id'],
						'other_node_id' => $b['node_id'],
					];
				}
			}
		}

		return [
			'table' => $table,
			'node_count' => $node_count,
			'issues' => $issues,
			'ok' => $issues === [],
		];
	}
}
