<?php

class Migration_20260319_100000_rename_comments_owner_columns_to_subject
{
	public function getDescription(): string
	{
		return 'Rename comments.owner_table/owner_id to subject_type/subject_id.';
	}

	public function run(): void
	{
		$pdo = Db::instance();
		$owner_table_stmt = $pdo->query("SHOW COLUMNS FROM comments LIKE 'owner_table'");
		$owner_id_stmt = $pdo->query("SHOW COLUMNS FROM comments LIKE 'owner_id'");
		$has_owner_table = $owner_table_stmt !== false && $owner_table_stmt->fetch(PDO::FETCH_ASSOC) !== false;
		$has_owner_id = $owner_id_stmt !== false && $owner_id_stmt->fetch(PDO::FETCH_ASSOC) !== false;

		if ($has_owner_table) {
			$pdo->exec(
				"ALTER TABLE comments CHANGE COLUMN owner_table subject_type VARCHAR(64) DEFAULT NULL"
			);
		}

		if ($has_owner_id) {
			$pdo->exec(
				"ALTER TABLE comments CHANGE COLUMN owner_id subject_id INT(10) UNSIGNED DEFAULT NULL"
			);
		}
	}
}
