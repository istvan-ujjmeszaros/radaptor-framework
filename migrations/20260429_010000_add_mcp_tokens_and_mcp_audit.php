<?php

declare(strict_types=1);

class Migration_20260429_010000_add_mcp_tokens_and_mcp_audit
{
	public function getDescription(): string
	{
		return 'Create MCP token and request log tables.';
	}

	public function run(): void
	{
		$pdo = Db::instance();

		$pdo->exec("CREATE TABLE IF NOT EXISTS `mcp_tokens` (
			`mcp_token_id` INT NOT NULL AUTO_INCREMENT,
			`user_id` INT NOT NULL,
			`name` VARCHAR(190) NOT NULL,
			`prefix` VARCHAR(16) NOT NULL,
			`token_hash` CHAR(64) NOT NULL,
			`expires_at` DATETIME NULL,
			`revoked_at` DATETIME NULL,
			`last_used_at` DATETIME NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`created_by_user_id` INT NULL,
			PRIMARY KEY (`mcp_token_id`),
			UNIQUE KEY `uniq_mcp_tokens_prefix` (`prefix`),
			KEY `idx_mcp_tokens_user` (`user_id`),
			KEY `idx_mcp_tokens_hash` (`token_hash`),
			KEY `idx_mcp_tokens_expires` (`expires_at`),
			KEY `idx_mcp_tokens_revoked` (`revoked_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");

		$pdo->exec("ALTER TABLE `mcp_tokens` COMMENT='__noaudit'");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `mcp_audit` (
			`mcp_audit_id` BIGINT NOT NULL AUTO_INCREMENT,
			`request_id` CHAR(36) NOT NULL,
			`user_id` INT NULL,
			`token_id` INT NULL,
			`tool_name` VARCHAR(190) NULL,
			`args_hash` CHAR(64) NULL,
			`args_redacted_json` LONGTEXT NULL,
			`result_status` VARCHAR(32) NOT NULL,
			`error_code` VARCHAR(120) NULL,
			`duration_ms` INT NOT NULL DEFAULT 0,
			`ip_address` VARCHAR(64) NULL,
			`user_agent` VARCHAR(512) NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`mcp_audit_id`),
			KEY `idx_mcp_audit_request` (`request_id`),
			KEY `idx_mcp_audit_user_created` (`user_id`, `created_at`),
			KEY `idx_mcp_audit_tool_created` (`tool_name`, `created_at`),
			KEY `idx_mcp_audit_status_created` (`result_status`, `created_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'");

		$pdo->exec("ALTER TABLE `mcp_audit` COMMENT='__noaudit'");
	}
}
