<?php

class Migration_20260319_090000_add_module_tracking_to_migrations
{
	public function getDescription(): string
	{
		return 'Add module-aware tracking to the migrations table.';
	}

	public function run(): void
	{
		MigrationRunner::ensureMigrationsTable();
	}
}
