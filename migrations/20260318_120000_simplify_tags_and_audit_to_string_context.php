<?php

class Migration_20260318_120000_simplify_tags_and_audit_to_string_context
{
	public function getDescription(): string
	{
		return 'Simplify tags context from ENUM+int to VARCHAR(64) string';
	}

	public function run(): void
	{
		$pdo = Db::instance();

		// ============================================================================
		// 1. Tags table: context ENUM → VARCHAR(64), drop context_id
		// ============================================================================

		// Check if context column needs conversion (is it still ENUM?)
		$stmt = $pdo->query("SHOW COLUMNS FROM tags LIKE 'context'");
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($row && str_starts_with(strtolower($row['Type']), 'enum')) {
			$pdo->exec("ALTER TABLE tags MODIFY COLUMN context VARCHAR(64) NOT NULL DEFAULT ''");
			$pdo->exec("UPDATE tags SET context = CONCAT('tracker_', context) WHERE context IN ('project', 'ticket')");
		}

		// Drop context_id if it exists
		$stmt = $pdo->query("SHOW COLUMNS FROM tags LIKE 'context_id'");

		if ($stmt->rowCount() > 0) {
			$pdo->exec("ALTER TABLE tags DROP COLUMN context_id");
		}

		// Add index on context (idempotent)
		$stmt = $pdo->query("SHOW INDEX FROM tags WHERE Key_name = 'idx_context'");

		if ($stmt->rowCount() === 0) {
			$pdo->exec("ALTER TABLE tags ADD INDEX idx_context (context)");
		}

		// ============================================================================
		// 2. Tag_connections table: same treatment
		// ============================================================================

		$stmt = $pdo->query("SHOW COLUMNS FROM tag_connections LIKE 'context'");
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($row && str_starts_with(strtolower($row['Type']), 'enum')) {
			$pdo->exec("ALTER TABLE tag_connections MODIFY COLUMN context VARCHAR(64) NOT NULL DEFAULT ''");
			$pdo->exec("UPDATE tag_connections SET context = CONCAT('tracker_', context) WHERE context IN ('project', 'ticket')");
		}

		// Drop context_id if it exists
		$stmt = $pdo->query("SHOW COLUMNS FROM tag_connections LIKE 'context_id'");

		if ($stmt->rowCount() > 0) {
			$pdo->exec("ALTER TABLE tag_connections DROP COLUMN context_id");
		}

		// Add composite index (idempotent)
		$stmt = $pdo->query("SHOW INDEX FROM tag_connections WHERE Key_name = 'idx_context_connected'");

		if ($stmt->rowCount() === 0) {
			$pdo->exec("ALTER TABLE tag_connections ADD INDEX idx_context_connected (context, connected_id)");
		}
	}
}
