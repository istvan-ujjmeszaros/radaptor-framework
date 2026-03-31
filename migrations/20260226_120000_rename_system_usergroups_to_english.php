<?php

/**
 * Rename system usergroup titles from Hungarian to English.
 *
 * Affected groups (identified by is_system_group = 1):
 *   node_id 1: "Mindenki"                    → "Everyone"
 *   node_id 2: "Bejelentkezett felhasználók" → "Logged in users"
 *
 * The SYSTEMUSERGROUP_EVERYBODY and SYSTEMUSERGROUP_LOGGEDIN constants
 * (hardcoded node_id values) are unaffected by this rename.
 */
class Migration_20260226_120000_rename_system_usergroups_to_english
{
	public function run(): void
	{
		$pdo = Db::instance();

		$pdo->exec("
			UPDATE usergroups_tree
			SET title = 'Everyone'
			WHERE node_id = 1
			  AND is_system_group = 1
			  AND title = 'Mindenki'
		");

		$pdo->exec("
			UPDATE usergroups_tree
			SET title = 'Logged in users'
			WHERE node_id = 2
			  AND is_system_group = 1
			  AND title = 'Bejelentkezett felhasználók'
		");
	}
}
