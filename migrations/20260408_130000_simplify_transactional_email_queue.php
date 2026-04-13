<?php

declare(strict_types=1);

class Migration_20260408_130000_simplify_transactional_email_queue
{
	public function run(): void
	{
		$pdo = Db::instance();

		// Intentionally destructive: transitional live queue rows are treated as disposable while
		// moving from the old queue_id-based schema to the lean job_id-based transactional queue.
		$pdo->exec("DROP TABLE IF EXISTS `email_queue_transactional`");

		$pdo->exec("CREATE TABLE `email_queue_transactional` (
			`job_id` VARCHAR(64) NOT NULL,
			`job_type` VARCHAR(128) NOT NULL,
			`payload_json` LONGTEXT NOT NULL,
			`requested_by_type` VARCHAR(32) NOT NULL,
			`requested_by_id` INT NULL,
			`status` ENUM('pending','reserved','retry_wait') NOT NULL DEFAULT 'pending',
			`attempts` INT NOT NULL DEFAULT 0,
			`run_after_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`reserved_at` DATETIME NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`job_id`),
			KEY `idx_email_queue_transactional_ready` (`status`, `run_after_utc`, `job_id`),
			KEY `idx_email_queue_transactional_reserved` (`status`, `reserved_at`, `job_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");
	}
}
