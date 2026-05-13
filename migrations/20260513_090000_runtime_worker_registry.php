<?php

declare(strict_types=1);

class Migration_20260513_090000_runtime_worker_registry
{
	public function run(): void
	{
		$pdo = Db::instance();

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS `runtime_worker_instances` (
				`worker_instance_id` VARCHAR(80) NOT NULL,
				`worker_type` VARCHAR(64) NOT NULL,
				`queue_name` VARCHAR(128) NOT NULL,
				`hostname` VARCHAR(255) NOT NULL,
				`process_id` INT UNSIGNED NULL,
				`state` ENUM('starting','idle','busy','paused','stopping') NOT NULL DEFAULT 'starting',
				`current_job_id` VARCHAR(128) NULL,
				`current_job_type` VARCHAR(128) NULL,
				`confirmed_pause_request_id` VARCHAR(80) NULL,
				`confirmed_pause_at` DATETIME NULL,
				`metadata_json` LONGTEXT NULL,
				`started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`stopped_at` DATETIME NULL,
				PRIMARY KEY (`worker_instance_id`),
				KEY `idx_runtime_worker_scope` (`worker_type`, `queue_name`, `state`, `last_seen_at`),
				KEY `idx_runtime_worker_pause` (`worker_type`, `queue_name`, `confirmed_pause_request_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit, __noexport'"
		);

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS `runtime_worker_pause_requests` (
				`pause_request_id` VARCHAR(80) NOT NULL,
				`worker_type` VARCHAR(64) NOT NULL,
				`queue_name` VARCHAR(128) NOT NULL,
				`status` ENUM('requested','confirmed','released','expired') NOT NULL DEFAULT 'requested',
				`reason` VARCHAR(128) NOT NULL,
				`context` VARCHAR(255) NOT NULL DEFAULT '',
				`requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`requested_by_user_id` INT UNSIGNED NULL,
				`confirmed_at` DATETIME NULL,
				`released_at` DATETIME NULL,
				`metadata_json` LONGTEXT NULL,
				PRIMARY KEY (`pause_request_id`),
				KEY `idx_runtime_worker_pause_scope` (`worker_type`, `queue_name`, `status`, `requested_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit, __noexport'"
		);

		foreach ([
			'email_queue_transactional',
			'email_queue_bulk',
			'queued_jobs',
			'email_queue_archive',
			'email_queue_dead_letter',
			'queued_jobs_archive',
			'queued_jobs_dead_letter',
			'email_outbox',
			'email_outbox_recipients',
			'email_attachments',
			'email_outbox_attachments',
		] as $table) {
			$this->appendTableCommentToken($pdo, $table, '__noexport:disaster_recovery');
		}

		foreach ([
			'mcp_tokens',
			'mcp_audit',
			'i18n_build_state',
			'i18n_tm_entries',
		] as $table) {
			$this->appendTableCommentToken($pdo, $table, '__noexport');
		}
	}

	public function getDescription(): string
	{
		return 'Add runtime worker registry and profile-aware snapshot export comments.';
	}

	private function appendTableCommentToken(PDO $pdo, string $table, string $token): void
	{
		$current_comment = $this->getTableComment($pdo, $table);

		if ($current_comment === null) {
			return;
		}

		$tokens = [];

		foreach (explode(',', $current_comment) as $existing_token) {
			$existing_token = trim($existing_token);

			if ($existing_token !== '') {
				$tokens[strtolower($existing_token)] = $existing_token;
			}
		}

		$normalized_token = strtolower(trim($token));

		if (isset($tokens[$normalized_token])) {
			return;
		}

		$tokens[$normalized_token] = $token;
		$comment = implode(', ', array_values($tokens));
		$pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` COMMENT = ' . $pdo->quote($comment));
	}

	private function getTableComment(PDO $pdo, string $table): ?string
	{
		$stmt = $pdo->prepare(
			"SELECT TABLE_COMMENT
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = DATABASE()
			  AND TABLE_TYPE = 'BASE TABLE'
			  AND TABLE_NAME = ?"
		);
		$stmt->execute([$table]);
		$value = $stmt->fetchColumn();

		return is_string($value) ? $value : null;
	}
}
