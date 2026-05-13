<?php

declare(strict_types=1);

class Migration_20260513_100000_runtime_site_cutover_locks
{
	public function run(): void
	{
		Db::instance()->exec(
			"CREATE TABLE IF NOT EXISTS `runtime_site_locks` (
				`lock_id` VARCHAR(80) NOT NULL,
				`lock_type` VARCHAR(80) NOT NULL,
				`status` ENUM('active','released') NOT NULL DEFAULT 'active',
				`reason` VARCHAR(128) NOT NULL,
				`context` VARCHAR(255) NOT NULL DEFAULT '',
				`message` VARCHAR(512) NOT NULL DEFAULT '',
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`created_by_user_id` INT UNSIGNED NULL,
				`released_at` DATETIME NULL,
				`released_by_user_id` INT UNSIGNED NULL,
				`release_note` VARCHAR(512) NOT NULL DEFAULT '',
				`metadata_json` LONGTEXT NULL,
				PRIMARY KEY (`lock_id`),
				KEY `idx_runtime_site_locks_scope` (`lock_type`, `status`, `created_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit, __noexport'"
		);
	}

	public function getDescription(): string
	{
		return 'Add runtime site cutover locks.';
	}
}
