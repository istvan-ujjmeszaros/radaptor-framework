<?php

/**
 * Add email queue and generic queued job foundation tables.
 *
 * All tables are marked with comment '__noaudit' to exclude them from audit trigger generation.
 */
class Migration_20260228_120000_add_email_queue_foundation
{
	public function run(): void
	{
		$pdo = Db::instance();

		$pdo->exec("CREATE TABLE IF NOT EXISTS `email_queue_transactional` (
			`queue_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`job_id` VARCHAR(64) NOT NULL,
			`job_type` VARCHAR(128) NOT NULL,
			`payload_json` LONGTEXT NOT NULL,
			`requested_by_type` VARCHAR(32) NOT NULL,
			`requested_by_id` INT NULL,
			`priority` ENUM('instant','bulk') NOT NULL DEFAULT 'instant',
			`status` ENUM('pending','reserved','retry_wait','completed','failed_auth','failed','dead') NOT NULL DEFAULT 'pending',
			`attempts` INT NOT NULL DEFAULT 0,
			`run_after_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`reserved_at` DATETIME NULL,
			`completed_at` DATETIME NULL,
			`last_error_code` VARCHAR(64) NULL,
			`last_error_message` TEXT NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`queue_id`),
			UNIQUE KEY `uq_email_queue_transactional_job_id` (`job_id`),
			KEY `idx_email_queue_transactional_poll` (`status`, `run_after_utc`, `priority`, `queue_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `email_queue_bulk` (
			`queue_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`job_id` VARCHAR(64) NOT NULL,
			`job_type` VARCHAR(128) NOT NULL,
			`payload_json` LONGTEXT NOT NULL,
			`requested_by_type` VARCHAR(32) NOT NULL,
			`requested_by_id` INT NULL,
			`priority` ENUM('instant','bulk') NOT NULL DEFAULT 'bulk',
			`status` ENUM('pending','reserved','retry_wait','completed','failed_auth','failed','dead') NOT NULL DEFAULT 'pending',
			`attempts` INT NOT NULL DEFAULT 0,
			`run_after_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`reserved_at` DATETIME NULL,
			`completed_at` DATETIME NULL,
			`last_error_code` VARCHAR(64) NULL,
			`last_error_message` TEXT NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`queue_id`),
			UNIQUE KEY `uq_email_queue_bulk_job_id` (`job_id`),
			KEY `idx_email_queue_bulk_poll` (`status`, `run_after_utc`, `priority`, `queue_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `queued_jobs` (
			`queue_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`job_id` VARCHAR(64) NOT NULL,
			`job_type` VARCHAR(128) NOT NULL,
			`payload_json` LONGTEXT NOT NULL,
			`requested_by_type` VARCHAR(32) NOT NULL,
			`requested_by_id` INT NULL,
			`status` ENUM('pending','reserved','retry_wait','completed','failed_auth','failed','dead') NOT NULL DEFAULT 'pending',
			`attempts` INT NOT NULL DEFAULT 0,
			`run_after_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`reserved_at` DATETIME NULL,
			`completed_at` DATETIME NULL,
			`last_error_code` VARCHAR(64) NULL,
			`last_error_message` TEXT NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`queue_id`),
			UNIQUE KEY `uq_queued_jobs_job_id` (`job_id`),
			KEY `idx_queued_jobs_poll` (`status`, `run_after_utc`, `queue_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `email_queue_archive` (
			`archive_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`source_table` VARCHAR(64) NOT NULL,
			`job_id` VARCHAR(64) NOT NULL,
			`job_type` VARCHAR(128) NOT NULL,
			`payload_json` LONGTEXT NOT NULL,
			`requested_by_type` VARCHAR(32) NOT NULL,
			`requested_by_id` INT NULL,
			`priority` VARCHAR(16) NULL,
			`attempts` INT NOT NULL DEFAULT 0,
			`completed_at` DATETIME NOT NULL,
			`created_at` DATETIME NOT NULL,
			`archived_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`archive_id`),
			KEY `idx_email_queue_archive_ttl` (`archived_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `email_queue_dead_letter` (
			`dead_letter_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`source_table` VARCHAR(64) NOT NULL,
			`job_id` VARCHAR(64) NOT NULL,
			`job_type` VARCHAR(128) NOT NULL,
			`payload_json` LONGTEXT NOT NULL,
			`requested_by_type` VARCHAR(32) NOT NULL,
			`requested_by_id` INT NULL,
			`priority` VARCHAR(16) NULL,
			`attempts` INT NOT NULL DEFAULT 0,
			`error_code` VARCHAR(64) NULL,
			`error_message` TEXT NULL,
			`created_at` DATETIME NOT NULL,
			`dead_lettered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`dead_letter_id`),
			KEY `idx_email_queue_dead_letter_ttl` (`dead_lettered_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `queued_jobs_archive` (
			`archive_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`job_id` VARCHAR(64) NOT NULL,
			`job_type` VARCHAR(128) NOT NULL,
			`payload_json` LONGTEXT NOT NULL,
			`requested_by_type` VARCHAR(32) NOT NULL,
			`requested_by_id` INT NULL,
			`attempts` INT NOT NULL DEFAULT 0,
			`completed_at` DATETIME NOT NULL,
			`created_at` DATETIME NOT NULL,
			`archived_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`archive_id`),
			KEY `idx_queued_jobs_archive_ttl` (`archived_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `queued_jobs_dead_letter` (
			`dead_letter_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`job_id` VARCHAR(64) NOT NULL,
			`job_type` VARCHAR(128) NOT NULL,
			`payload_json` LONGTEXT NOT NULL,
			`requested_by_type` VARCHAR(32) NOT NULL,
			`requested_by_id` INT NULL,
			`attempts` INT NOT NULL DEFAULT 0,
			`error_code` VARCHAR(64) NULL,
			`error_message` TEXT NULL,
			`created_at` DATETIME NOT NULL,
			`dead_lettered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`dead_letter_id`),
			KEY `idx_queued_jobs_dead_letter_ttl` (`dead_lettered_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `email_templates` (
			`template_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`name` VARCHAR(255) NOT NULL,
			`slug` VARCHAR(255) NOT NULL,
			`description` TEXT NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`template_id`),
			UNIQUE KEY `uq_email_templates_slug` (`slug`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `email_template_versions` (
			`template_version_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`template_id` BIGINT UNSIGNED NOT NULL,
			`engine_type` ENUM('php','blade','twig') NOT NULL,
			`compiled_source` LONGTEXT NOT NULL,
			`compiled_plain_source` LONGTEXT NULL,
			`is_active` TINYINT(1) NOT NULL DEFAULT 1,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`template_version_id`),
			KEY `idx_email_template_versions_template` (`template_id`, `is_active`),
			CONSTRAINT `fk_email_template_versions_template` FOREIGN KEY (`template_id`) REFERENCES `email_templates` (`template_id`) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `email_outbox` (
			`outbox_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`message_uid` VARCHAR(64) NOT NULL,
			`send_mode` ENUM('transactional','bulk') NOT NULL,
			`template_version_id` BIGINT UNSIGNED NULL,
			`subject` TEXT NULL,
			`html_body` LONGTEXT NULL,
			`text_body` LONGTEXT NULL,
			`status` ENUM('queued','rendered','sent','failed') NOT NULL DEFAULT 'queued',
			`requested_by_type` VARCHAR(32) NOT NULL,
			`requested_by_id` INT NULL,
			`metadata_json` LONGTEXT NULL,
			`scheduled_at` DATETIME NULL,
			`sent_at` DATETIME NULL,
			`last_error_code` VARCHAR(64) NULL,
			`last_error_message` TEXT NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`outbox_id`),
			UNIQUE KEY `uq_email_outbox_message_uid` (`message_uid`),
			KEY `idx_email_outbox_status_created` (`status`, `created_at`),
			CONSTRAINT `fk_email_outbox_template_version` FOREIGN KEY (`template_version_id`) REFERENCES `email_template_versions` (`template_version_id`) ON DELETE SET NULL
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `email_outbox_recipients` (
			`recipient_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`outbox_id` BIGINT UNSIGNED NOT NULL,
			`recipient_type` ENUM('to','cc','bcc') NOT NULL DEFAULT 'to',
			`recipient_email` VARCHAR(320) NOT NULL,
			`recipient_name` VARCHAR(255) NULL,
			`context_json` LONGTEXT NULL,
			`status` ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
			`sent_at` DATETIME NULL,
			`last_error_code` VARCHAR(64) NULL,
			`last_error_message` TEXT NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`recipient_id`),
			KEY `idx_email_outbox_recipients_outbox` (`outbox_id`, `status`),
			CONSTRAINT `fk_email_outbox_recipients_outbox` FOREIGN KEY (`outbox_id`) REFERENCES `email_outbox` (`outbox_id`) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `email_attachments` (
			`attachment_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`file_name` VARCHAR(255) NOT NULL,
			`mime_type` VARCHAR(128) NOT NULL,
			`storage_path` VARCHAR(1024) NOT NULL,
			`size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`checksum_sha256` VARCHAR(64) NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`attachment_id`),
			KEY `idx_email_attachments_checksum` (`checksum_sha256`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `email_outbox_attachments` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`outbox_id` BIGINT UNSIGNED NOT NULL,
			`attachment_id` BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `uq_email_outbox_attachments` (`outbox_id`, `attachment_id`),
			CONSTRAINT `fk_email_outbox_attachments_outbox` FOREIGN KEY (`outbox_id`) REFERENCES `email_outbox` (`outbox_id`) ON DELETE CASCADE,
			CONSTRAINT `fk_email_outbox_attachments_attachment` FOREIGN KEY (`attachment_id`) REFERENCES `email_attachments` (`attachment_id`) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");
	}
}
