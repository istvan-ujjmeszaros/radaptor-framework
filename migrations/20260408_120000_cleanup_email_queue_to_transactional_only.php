<?php

declare(strict_types=1);

class Migration_20260408_120000_cleanup_email_queue_to_transactional_only
{
	public function run(): void
	{
		$pdo = Db::instance();

		$this->dropForeignKeyIfExists($pdo, 'email_outbox', 'fk_email_outbox_template_version');

		$pdo->exec('DROP TABLE IF EXISTS `email_outbox_attachments`');
		$pdo->exec('DROP TABLE IF EXISTS `email_attachments`');
		$pdo->exec('DROP TABLE IF EXISTS `email_template_versions`');
		$pdo->exec('DROP TABLE IF EXISTS `email_templates`');
		$pdo->exec('DROP TABLE IF EXISTS `email_queue_bulk`');
		$pdo->exec('DROP TABLE IF EXISTS `queued_jobs_archive`');
		$pdo->exec('DROP TABLE IF EXISTS `queued_jobs_dead_letter`');
		$pdo->exec('DROP TABLE IF EXISTS `queued_jobs`');
		$pdo->exec('DROP TABLE IF EXISTS `queue_ops_log`');
	}

	private function dropForeignKeyIfExists(PDO $pdo, string $table, string $constraint): void
	{
		$table_name = $pdo->quote($table);
		$constraint_name = $pdo->quote($constraint);

		$row = DbHelper::selectOneFromQuery(
			"SELECT CONSTRAINT_NAME
			FROM information_schema.TABLE_CONSTRAINTS
			WHERE TABLE_SCHEMA = DATABASE()
			  AND TABLE_NAME = {$table_name}
			  AND CONSTRAINT_NAME = {$constraint_name}
			  AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
		);

		if (!is_array($row)) {
			return;
		}

		$pdo->exec("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
	}
}
