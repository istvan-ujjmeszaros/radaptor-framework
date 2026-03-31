<?php

class Migration_20260311_000004_add_source_key_to_i18n_tm_entries
{
	public function run(): void
	{
		$pdo = Db::instance();
		$hasStatus = (bool) $pdo->query("SHOW COLUMNS FROM `i18n_translations` LIKE 'status'")->fetch();
		$hasHumanReviewed = (bool) $pdo->query("SHOW COLUMNS FROM `i18n_translations` LIKE 'human_reviewed'")->fetch();

		$hasSourceKey = (bool) $pdo->query("SHOW COLUMNS FROM `i18n_tm_entries` LIKE 'source_key'")->fetch();

		if (!$hasSourceKey) {
			$pdo->exec(
				"ALTER TABLE `i18n_tm_entries`
				ADD COLUMN `source_key` VARCHAR(255) NOT NULL DEFAULT '' AFTER `domain`"
			);
		}

		$hasIndex = (bool) $pdo->query("SHOW INDEX FROM `i18n_tm_entries` WHERE Key_name = 'idx_tm_signature'")->fetch();

		if (!$hasIndex) {
			$pdo->exec(
				"ALTER TABLE `i18n_tm_entries`
				ADD INDEX `idx_tm_signature` (`source_locale`, `target_locale`, `source_hash`, `domain`, `source_key`, `context`)"
			);
		}

		$pdo->exec("DELETE FROM `i18n_tm_entries`");
		if ($hasStatus) {
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
						WHEN t.`status` = 'approved' THEN 'approved'
						WHEN t.`status` = 'ai_generated' THEN 'mt'
						ELSE 'manual'
					END AS `quality_score`,
					NOW() AS `created_at`,
					NOW() AS `updated_at`
				FROM `i18n_messages` m
				JOIN `i18n_translations` t
					ON t.`domain` = m.`domain`
					AND t.`key` = m.`key`
					AND t.`context` = m.`context`
				WHERE t.`locale` <> 'en_US'
					AND m.`source_text` <> ''
					AND t.`text` <> ''"
			);

			return;
		}

		if (!$hasHumanReviewed) {
			return;
		}

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
					ELSE 'manual'
				END AS `quality_score`,
				NOW() AS `created_at`,
				NOW() AS `updated_at`
			FROM `i18n_messages` m
			JOIN `i18n_translations` t
				ON t.`domain` = m.`domain`
				AND t.`key` = m.`key`
				AND t.`context` = m.`context`
			WHERE t.`locale` <> 'en_US'
				AND m.`source_text` <> ''
				AND t.`text` <> ''"
		);
	}
}
