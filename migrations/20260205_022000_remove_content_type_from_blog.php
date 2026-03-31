<?php

/**
 * Migration: Remove unused content_type column from blog table.
 *
 * The content_type enum column ('article','blog','info') was never used in
 * any business logic - the Blog form and class don't differentiate by type.
 * Removing it simplifies the schema and eliminates a NOT NULL constraint
 * that was causing form submission errors.
 */
class Migration_20260205_022000_remove_content_type_from_blog
{
	public function run(): void
	{
		$pdo = Db::instance();

		// Check if column exists before trying to remove
		$stmt = $pdo->query("SHOW COLUMNS FROM blog LIKE 'content_type'");

		if ($stmt->rowCount() === 0) {
			// Already removed
			return;
		}

		// Remove the column and its index
		$pdo->exec("ALTER TABLE blog DROP INDEX content_type");
		$pdo->exec("ALTER TABLE blog DROP COLUMN content_type");
	}

	public function getDescription(): string
	{
		return 'Remove unused content_type column from blog table';
	}
}
