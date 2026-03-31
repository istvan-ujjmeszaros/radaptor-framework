<?php

/**
 * Migration: Rename sections to widget_connections for terminology cleanup.
 *
 * This migration renames the CMS page composition tables and columns to use
 * clearer terminology aligned with modern frameworks like Astro:
 *
 * - webpage_section_connections -> widget_connections
 * - layout_element_name -> slot_name
 * - section_type_name -> widget_name
 *
 * The connection_id primary key is intentionally kept unchanged.
 */
class Migration_20260203_120000_rename_sections_to_widget_connections
{
	public function run(): void
	{
		$pdo = Db::instance();

		// Check if already migrated (widget_connections exists = already done)
		$stmt = $pdo->query("SHOW TABLES LIKE 'widget_connections'");

		if ($stmt->rowCount() > 0) {
			// Already migrated, just update attributes if needed
			DbHelper::runCustomQuery(
				"UPDATE attributes SET resource_name = ? WHERE resource_name = ?",
				['widget_connection', 'webpage_section']
			);

			return;
		}

		// Check if old table exists
		$stmt = $pdo->query("SHOW TABLES LIKE 'webpage_section_connections'");

		if ($stmt->rowCount() === 0) {
			// Neither table exists - nothing to migrate
			return;
		}

		// 1. Rename the table
		$pdo->exec("RENAME TABLE webpage_section_connections TO widget_connections");

		// 2. Rename columns (connection_id stays unchanged!)
		$pdo->exec("ALTER TABLE widget_connections
			CHANGE layout_element_name slot_name VARCHAR(64) NOT NULL,
			CHANGE section_type_name widget_name VARCHAR(64) NOT NULL");

		// 3. Update attributes table resource_name references
		// This updates any attribute records that reference the old resource name
		DbHelper::runCustomQuery(
			"UPDATE attributes SET resource_name = ? WHERE resource_name = ?",
			['widget_connection', 'webpage_section']
		);
	}

	public function getDescription(): string
	{
		return 'Rename sections to widget_connections for terminology cleanup';
	}
}
