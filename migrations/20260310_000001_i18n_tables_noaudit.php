<?php

class Migration_20260310_000001_i18n_tables_noaudit
{
	public function run(): void
	{
		$pdo = Db::instance();

		// Mark all i18n tables as __noaudit — audit triggers expect integer PKs
		// but i18n tables use composite or string PKs.
		$pdo->exec("ALTER TABLE `i18n_messages`     COMMENT = '__noaudit'");
		$pdo->exec("ALTER TABLE `i18n_translations` COMMENT = '__noaudit'");
		$pdo->exec("ALTER TABLE `i18n_build_state`  COMMENT = '__noaudit'");
		$pdo->exec("ALTER TABLE `i18n_tm_entries`   COMMENT = '__noaudit'");
	}
}
