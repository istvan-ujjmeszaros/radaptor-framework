<?php

declare(strict_types=1);

final class MigrationContentGuard
{
	/**
	 * These source checks are intentionally conservative. The before/after snapshot is
	 * the runtime guard; this scan fails early when executable migration code appears
	 * to call a destructive resource_tree API, even before that code can run.
	 */
	private const array FORBIDDEN_EXECUTABLE_SOURCE_PATTERNS = [
		'/ResourceTreeHandler::deleteResourceEntr(?:y|ies)Recursive\s*\(/' => 'recursive resource deletion',
		'/ResourceTreeHandler::deleteResourceEntry\s*\(/' => 'resource deletion',
		'/NestedSet::deleteNode\s*\(/' => 'raw nested-set deletion',
	];

	private const array FORBIDDEN_SQL_SOURCE_PATTERNS = [
		'/\bDELETE\s+FROM\s+`?resource_tree`?\b/i' => 'raw resource_tree deletion SQL',
		'/\bTRUNCATE\s+(?:TABLE\s+)?`?resource_tree`?\b/i' => 'raw resource_tree truncation SQL',
		'/\bDROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?resource_tree`?\b/i' => 'raw resource_tree drop SQL',
	];

	private const array IMPLICIT_COMMIT_SQL_PATTERNS = [
		'/\bALTER\s+TABLE\b/i',
		'/\bCREATE\s+TABLE\b/i',
		'/\bDROP\s+TABLE\b/i',
		'/\bRENAME\s+TABLE\b/i',
		'/\bTRUNCATE\s+(?:TABLE\s+)?\b/i',
	];

	private const array DYNAMIC_DELETE_SQL_PATTERNS = [
		'/\bDELETE\s+FROM\s+\{\$/i',
		'/\bDELETE\s+FROM\s+\$/i',
		'/\bDELETE\s+FROM\s*[\'"]\s*\.\s*\$/i',
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

		$executable_source = self::stripCommentsAndStrings($source);

		foreach (self::FORBIDDEN_EXECUTABLE_SOURCE_PATTERNS as $pattern => $reason) {
			if (preg_match($pattern, $executable_source) === 1) {
				throw new RuntimeException("Migration is not allowed to delete CMS resources ({$reason}): " . basename($filepath));
			}
		}

		$source_without_comments = self::stripComments($source);

		foreach (self::FORBIDDEN_SQL_SOURCE_PATTERNS as $pattern => $reason) {
			if (preg_match($pattern, $source_without_comments) === 1) {
				throw new RuntimeException("Migration is not allowed to delete CMS resources ({$reason}): " . basename($filepath));
			}
		}

		if (self::containsImplicitCommitSql($source_without_comments)) {
			foreach (self::DYNAMIC_DELETE_SQL_PATTERNS as $pattern) {
				if (preg_match($pattern, $source_without_comments) === 1) {
					throw new RuntimeException(
						'Migration combines implicit-commit DDL with dynamic raw DELETE SQL, which cannot be verified against CMS resources: '
						. basename($filepath)
					);
				}
			}
		}
	}

	private static function stripComments(string $source): string
	{
		$result = '';

		foreach (token_get_all($source) as $token) {
			if (!is_array($token)) {
				$result .= $token;

				continue;
			}

			if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				$result .= str_repeat("\n", substr_count($token[1], "\n"));

				continue;
			}

			$result .= $token[1];
		}

		return $result;
	}

	private static function stripCommentsAndStrings(string $source): string
	{
		$result = '';

		foreach (token_get_all($source) as $token) {
			if (!is_array($token)) {
				$result .= $token;

				continue;
			}

			if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
				$result .= str_repeat("\n", substr_count($token[1], "\n"));

				continue;
			}

			$result .= $token[1];
		}

		return $result;
	}

	private static function containsImplicitCommitSql(string $source_without_comments): bool
	{
		foreach (self::IMPLICIT_COMMIT_SQL_PATTERNS as $pattern) {
			if (preg_match($pattern, $source_without_comments) === 1) {
				return true;
			}
		}

		return false;
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
		if ($before === null) {
			return;
		}

		if (!self::tableExists('resource_tree')) {
			throw new RuntimeException(
				'Migration removed the CMS resource_tree table, which is forbidden: ' . $filename
			);
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
		$quoted_table_name = Db::instance()->quote($table);

		return Db::instance()->query("SHOW TABLES LIKE {$quoted_table_name}")?->rowCount() > 0;
	}
}
