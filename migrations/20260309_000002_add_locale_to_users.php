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
			ADD COLUMN `locale` VARCHAR(10) NOT NULL DEFAULT 'en_US'
			COMMENT 'Preferred locale for UI (e.g. en_US, hu_HU)'
			AFTER `timezone`");
	}
}
