<?php

class Migration_20260309_000002_add_locale_to_users
{
	public function run(): void
	{
		$pdo = Db::instance();

		$stmt = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'locale'");

		if ($stmt->rowCount() > 0) {
			return;
		}

		$pdo->exec("ALTER TABLE `users`
			ADD COLUMN `locale` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'en-US'
			COMMENT 'Preferred BCP 47 locale for UI (e.g. en-US, hu-HU)'
			AFTER `timezone`");
	}
}
