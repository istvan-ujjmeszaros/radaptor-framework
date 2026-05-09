<?php

declare(strict_types=1);

class Migration_20260509_121000_cms_mutation_audit_metadata_columns
{
	public function getDescription(): string
	{
		return 'Add searchable CMS mutation audit metadata columns.';
	}

	public function run(): void
	{
		$pdo = Db::instance();

		if (!$this->columnExists('cms_mutation_audit', 'affected_count')) {
			$pdo->exec("ALTER TABLE `cms_mutation_audit` ADD COLUMN `affected_count` INT NOT NULL DEFAULT 0 AFTER `result_status`");
		}

		if (!$this->columnExists('cms_mutation_audit', 'error_code')) {
			$pdo->exec("ALTER TABLE `cms_mutation_audit` ADD COLUMN `error_code` VARCHAR(190) NULL AFTER `affected_count`");
		}

		$pdo->exec("ALTER TABLE `cms_mutation_audit` COMMENT='__noaudit'");
	}

	private function columnExists(string $table, string $column): bool
	{
		$stmt = Db::instance()->prepare(
			'SELECT COUNT(*) FROM `INFORMATION_SCHEMA`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `COLUMN_NAME` = ?'
		);
		$stmt->execute([$table, $column]);

		return (int) $stmt->fetchColumn() > 0;
	}
}
