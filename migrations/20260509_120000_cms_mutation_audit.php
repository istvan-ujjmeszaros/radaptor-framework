<?php

declare(strict_types=1);

class Migration_20260509_120000_cms_mutation_audit
{
	public function getDescription(): string
	{
		return 'Create CMS mutation audit log table.';
	}

	public function run(): void
	{
		$pdo = Db::instance();

		$pdo->exec("CREATE TABLE IF NOT EXISTS `cms_mutation_audit` (
			`cms_mutation_audit_id` BIGINT NOT NULL AUTO_INCREMENT,
			`correlation_id` CHAR(36) NOT NULL,
			`parent_correlation_id` CHAR(36) NULL,
			`phase` VARCHAR(32) NOT NULL,
			`operation` VARCHAR(190) NOT NULL,
			`actor_type` VARCHAR(32) NOT NULL DEFAULT 'internal',
			`actor_user_id` INT NULL,
			`cli_command` VARCHAR(190) NULL,
			`args_hash` CHAR(64) NULL,
			`args_redacted_json` LONGTEXT NULL,
			`resource_id` INT NULL,
			`page_id` INT NULL,
			`widget_connection_id` INT NULL,
			`resource_path` VARCHAR(1024) NULL,
			`slot_name` VARCHAR(190) NULL,
			`widget_name` VARCHAR(190) NULL,
			`result_status` VARCHAR(32) NOT NULL DEFAULT 'success',
			`affected_count` INT NOT NULL DEFAULT 0,
			`error_code` VARCHAR(190) NULL,
			`error_class` VARCHAR(190) NULL,
			`error_message` VARCHAR(512) NULL,
			`before_json` LONGTEXT NULL,
			`after_json` LONGTEXT NULL,
			`summary_json` LONGTEXT NULL,
			`metadata_json` LONGTEXT NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`cms_mutation_audit_id`),
			KEY `idx_cms_mutation_audit_correlation` (`correlation_id`),
			KEY `idx_cms_mutation_audit_created` (`created_at`),
			KEY `idx_cms_mutation_audit_resource_created` (`resource_id`, `created_at`),
			KEY `idx_cms_mutation_audit_page_created` (`page_id`, `created_at`),
			KEY `idx_cms_mutation_audit_widget_created` (`widget_connection_id`, `created_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");

		$pdo->exec("ALTER TABLE `cms_mutation_audit` COMMENT='__noaudit'");
	}
}
