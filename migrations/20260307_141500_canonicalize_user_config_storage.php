<?php

/**
 * Migration: canonicalize user config storage to config_user.
 *
 * The old userconfig table was renamed to config_user when config_app was
 * introduced. If a stray userconfig table exists, merge any rows into
 * config_user and remove the legacy table.
 */
class Migration_20260307_141500_canonicalize_user_config_storage
{
	public function run(): void
	{
		$pdo = Db::instance();

		$has_config_user = $pdo->query("SHOW TABLES LIKE 'config_user'")->rowCount() > 0;
		$has_userconfig = $pdo->query("SHOW TABLES LIKE 'userconfig'")->rowCount() > 0;

		if ($has_config_user && $has_userconfig) {
			$legacy_key_column = $pdo->query("SHOW COLUMNS FROM `userconfig` LIKE 'config_key'")->rowCount() > 0
				? 'config_key'
				: 'setting_key';

			$pdo->exec(
				"INSERT INTO `config_user` (`user_id`, `config_key`, `value`)
				SELECT `user_id`, `{$legacy_key_column}`, `value`
				FROM `userconfig`
				ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
			);

			$pdo->exec("DROP TABLE `userconfig`");

			return;
		}

		if (!$has_config_user && $has_userconfig) {
			$pdo->exec("RENAME TABLE `userconfig` TO `config_user`");

			if ($pdo->query("SHOW COLUMNS FROM `config_user` LIKE 'config_key'")->rowCount() === 0
				&& $pdo->query("SHOW COLUMNS FROM `config_user` LIKE 'setting_key'")->rowCount() > 0
			) {
				$pdo->exec("ALTER TABLE `config_user` CHANGE COLUMN `setting_key` `config_key` varchar(255) NOT NULL");
			}
		}
	}

	public function getDescription(): string
	{
		return 'Canonicalize user config storage to config_user and remove stray userconfig';
	}
}
