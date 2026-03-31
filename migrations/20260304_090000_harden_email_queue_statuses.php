<?php

class Migration_20260304_090000_harden_email_queue_statuses
{
	public function run(): void
	{
		$pdo = Db::instance();
		$statusColumn = DbHelper::selectOneFromQuery(
			"SELECT COLUMN_TYPE
			 FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME = 'email_outbox'
			   AND COLUMN_NAME = 'status'"
		);

		if (is_array($statusColumn)) {
			$columnType = (string) ($statusColumn['COLUMN_TYPE'] ?? '');

			if (strpos($columnType, "'processing'") === false || strpos($columnType, "'partial_failed'") === false) {
				$pdo->exec("ALTER TABLE `email_outbox`
					MODIFY COLUMN `status`
					ENUM('queued','processing','rendered','sent','partial_failed','failed')
					NOT NULL DEFAULT 'queued'");
			}
		}
	}
}
