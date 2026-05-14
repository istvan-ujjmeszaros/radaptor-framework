<?php

class Migration_20260514_120000_add_i18n_allow_source_match
{
	public function run(): void
	{
		$pdo = Db::instance();
		$hasAllowSourceMatch = (bool) $pdo->query("SHOW COLUMNS FROM `i18n_translations` LIKE 'allow_source_match'")->fetch();

		if ($hasAllowSourceMatch) {
			return;
		}

		$afterColumn = (bool) $pdo->query("SHOW COLUMNS FROM `i18n_translations` LIKE 'human_reviewed'")->fetch()
			? 'human_reviewed'
			: 'text';

		$pdo->exec(
			"ALTER TABLE `i18n_translations`
			ADD COLUMN `allow_source_match` TINYINT(1) NOT NULL DEFAULT 0 AFTER `{$afterColumn}`"
		);
	}
}
