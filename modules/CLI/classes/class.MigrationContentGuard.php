<?php

declare(strict_types=1);

final class MigrationContentGuard
{
	/**
	 * These source checks are intentionally conservative. The before/after snapshot is
	 * the runtime guard; this scan fails early when a migration appears to contain a
	 * destructive resource_tree operation, even before that code can run.
	 */
	private const array FORBIDDEN_SOURCE_PATTERNS = [
		'/\bDELETE\s+FROM\s+`?resource_tree`?\b/i' => 'raw resource_tree delete',
		'/\bTRUNCATE\s+(?:TABLE\s+)?`?resource_tree`?\b/i' => 'raw resource_tree truncate',
		'/\bDROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?resource_tree`?\b/i' => 'raw resource_tree drop',
		'/ResourceTreeHandler::deleteResourceEntr(?:y|ies)Recursive\s*\(/' => 'recursive resource deletion',
		'/ResourceTreeHandler::deleteResourceEntry\s*\(/' => 'resource deletion',
		'/NestedSet::deleteNode\s*\(\s*[\'"]resource_tree[\'"]/' => 'raw nested-set resource deletion',
	];

	public static function assertMigrationSourceAllowed(string $filepath): void
	{
		if (!is_file($filepath) || !is_readable($filepath)) {
			return;
		}

		$source = file_get_contents($filepath);

		if (!is_string($source)) {
			return;
		}

		foreach (self::FORBIDDEN_SOURCE_PATTERNS as $pattern => $reason) {
			if (preg_match($pattern, $source) === 1) {
				throw new RuntimeException("Migration is not allowed to delete CMS resources ({$reason}): " . basename($filepath));
			}
		}
	}

	/**
	 * Snapshots node ids only. This is intentionally cheap enough for normal CMS trees
	 * while still detecting any migration that deletes resource_tree content.
	 *
	 * @return array<int, true>|null
	 */
	public static function snapshotResourceTreeNodeIds(): ?array
	{
		if (!self::tableExists('resource_tree')) {
			return null;
		}

		$stmt = Db::instance()->query('SELECT node_id FROM resource_tree');
		$ids = [];

		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$ids[(int) $id] = true;
		}

		return $ids;
	}

	/**
	 * @param array<int, true>|null $before
	 */
	public static function assertNoResourceTreeRowsDeleted(?array $before, string $filename): void
	{
		if ($before === null || !self::tableExists('resource_tree')) {
			return;
		}

		$after = self::snapshotResourceTreeNodeIds() ?? [];
		$deleted = array_keys(array_diff_key($before, $after));

		if ($deleted === []) {
			return;
		}

		sort($deleted);

		throw new RuntimeException(
			'Migration deleted CMS resource_tree rows, which is forbidden: '
			. $filename
			. ' deleted node_id(s) '
			. implode(', ', array_map('strval', $deleted))
		);
	}

	private static function tableExists(string $table): bool
	{
		$stmt = Db::instance()->prepare(
			'SELECT COUNT(*)
			FROM information_schema.tables
			WHERE table_schema = DATABASE()
				AND table_name = ?'
		);
		$stmt->execute([$table]);

		return (int) $stmt->fetchColumn() > 0;
	}
}
