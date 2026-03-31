<?php

class Migration_20260331_120000_create_seeds_table
{
	public function getDescription(): string
	{
		return 'Create the seeds tracking table.';
	}

	public function run(): void
	{
		Db::instance()->exec(
			"CREATE TABLE IF NOT EXISTS seeds (
				module VARCHAR(120) NOT NULL,
				seed_class VARCHAR(255) NOT NULL,
				kind VARCHAR(20) NOT NULL,
				version VARCHAR(100) NOT NULL,
				applied_at DATETIME NOT NULL,
				PRIMARY KEY (module, seed_class)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'"
		);
	}
}
