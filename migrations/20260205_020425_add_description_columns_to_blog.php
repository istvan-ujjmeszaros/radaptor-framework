<?php

/**
 * Migration: Add description columns to blog table.
 *
 * Adds the missing description column pair for short blog descriptions:
 * - __description: Raw editor content with internal links (e.g., ?direction=in&id=123)
 * - description: Pre-rendered content with resolved URLs
 *
 * The __ prefix columns are automatically detected as "processable fields" by
 * the framework. On save, HtmlProcessor::processHtmlContent() is called on
 * __description to generate the description column with resolved URLs.
 */
class Migration_20260205_020425_add_description_columns_to_blog
{
	public function run(): void
	{
		$pdo = Db::instance();

		// Check if columns already exist
		$stmt = $pdo->query("SHOW COLUMNS FROM blog LIKE 'description'");

		if ($stmt->rowCount() > 0) {
			// Already migrated
			return;
		}

		// Add both columns - order matters for the processable fields detection
		// __description is the source (editor content), description is the target (processed)
		$pdo->exec("ALTER TABLE blog
			ADD COLUMN `description` TEXT DEFAULT NULL AFTER `__content`,
			ADD COLUMN `__description` TEXT DEFAULT NULL AFTER `description`");
	}

	public function getDescription(): string
	{
		return 'Add description and __description columns to blog table for short descriptions';
	}
}
