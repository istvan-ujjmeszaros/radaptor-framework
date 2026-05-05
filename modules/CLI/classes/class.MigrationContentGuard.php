<?php

declare(strict_types=1);

final class MigrationContentGuard
{
	/**
	 * These source checks are intentionally conservative. The before/after snapshot is
	 * the runtime guard; this scan fails early when executable migration code appears
	 * to call a destructive resource_tree API, even before that code can run.
	 */
	private const array FORBIDDEN_SOURCE_PATTERNS = [
		'/ResourceTreeHandler::deleteResourceEntr(?:y|ies)Recursive\s*\(/' => 'recursive resource deletion',
		'/ResourceTreeHandler::deleteResourceEntry\s*\(/' => 'resource deletion',
		'/NestedSet::deleteNode\s*\(/' => 'raw nested-set deletion',
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

		foreach (self::FORBIDDEN_SOURCE_PATTERNS as $pattern => $reason) {
			if (preg_match($pattern, $executable_source) === 1) {
				throw new RuntimeException("Migration is not allowed to delete CMS resources ({$reason}): " . basename($filepath));
			}
		}
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
