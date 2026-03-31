<?php

class Migration_20260306_120000_add_users_timezone
{
	public function run(): void
	{
		$pdo = Db::instance();

		$pdo->exec("ALTER TABLE `users`
			ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(64) NULL
			COMMENT 'IANA timezone identifier (for example Europe/Budapest, America/New_York)'
			AFTER `last_seen`");
	}
}
