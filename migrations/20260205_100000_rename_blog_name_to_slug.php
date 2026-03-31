<?php

/**
 * Migration: Rename blog.name column to blog.slug.
 *
 * The column stores the URL-friendly identifier for blog entries,
 * and "slug" is the standard terminology for this purpose.
 */
class Migration_20260205_100000_rename_blog_name_to_slug
{
	public function run(): void
	{
		$pdo = Db::instance();

		// Check if already migrated (slug column exists = already done)
		$stmt = $pdo->query("SHOW COLUMNS FROM blog LIKE 'slug'");

		if ($stmt->rowCount() > 0) {
			// Already migrated
			return;
		}

		// Check if name column exists
		$stmt = $pdo->query("SHOW COLUMNS FROM blog LIKE 'name'");

		if ($stmt->rowCount() === 0) {
			// name column doesn't exist - nothing to migrate
			return;
		}

		// Rename the column
		$pdo->exec("ALTER TABLE blog CHANGE COLUMN `name` `slug` VARCHAR(255)");
	}

	public function getDescription(): string
	{
		return 'Rename blog.name column to blog.slug';
	}
}
