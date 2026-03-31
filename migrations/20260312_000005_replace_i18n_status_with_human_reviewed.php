<?php

/**
 * Replace translation workflow status with a lightweight human_reviewed flag.
 */
class Migration_20260312_000005_replace_i18n_status_with_human_reviewed
{
	public function run(): void
	{
		$pdo = Db::instance();

		$hasHumanReviewed = (bool) $pdo->query("SHOW COLUMNS FROM `i18n_translations` LIKE 'human_reviewed'")->fetch();

		if (!$hasHumanReviewed) {
			$pdo->exec(
				"ALTER TABLE `i18n_translations`
				ADD COLUMN `human_reviewed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `text`"
			);
		}

		$hasStatus = (bool) $pdo->query("SHOW COLUMNS FROM `i18n_translations` LIKE 'status'")->fetch();

		if ($hasStatus) {
			$pdo->exec(
				"UPDATE `i18n_translations`
				SET `human_reviewed` = CASE
					WHEN `status` = 'approved' THEN 1
					ELSE 0
				END"
			);

			$pdo->exec("ALTER TABLE `i18n_translations` DROP COLUMN `status`");
		}

		$pdo->exec(
			"DELETE FROM `i18n_tm_entries`"
		);

		$pdo->exec(
			"INSERT INTO `i18n_tm_entries` (
				`source_locale`,
				`target_locale`,
				`source_text_normalized`,
				`source_text_raw`,
				`target_text`,
				`domain`,
				`source_key`,
				`context`,
				`source_hash`,
				`usage_count`,
				`quality_score`,
				`created_at`,
				`updated_at`
			)
			SELECT
				'en_US' AS `source_locale`,
				t.`locale` AS `target_locale`,
				LOWER(TRIM(m.`source_text`)) AS `source_text_normalized`,
				m.`source_text` AS `source_text_raw`,
				t.`text` AS `target_text`,
				m.`domain`,
				m.`key` AS `source_key`,
				m.`context`,
				m.`source_hash`,
				1 AS `usage_count`,
				CASE
					WHEN t.`human_reviewed` = 1 THEN 'approved'
					ELSE 'mt'
				END AS `quality_score`,
				NOW() AS `created_at`,
				NOW() AS `updated_at`
			FROM `i18n_messages` m
			JOIN `i18n_translations` t
				ON t.`domain` = m.`domain`
				AND t.`key` = m.`key`
				AND t.`context` = m.`context`
			WHERE m.`source_text` <> ''
				AND TRIM(t.`text`) <> ''"
		);
	}
}
