<?php

/**
 * Seed reviewed-column labels for the i18n workbench.
 */
class Migration_20260312_000007_add_i18n_reviewed_label
{
	public function run(): void
	{
		$translations = [
			'en_US' => 'Reviewed',
			'hu_HU' => 'Ellenőrizve',
			'de_DE' => 'Geprüft',
		];

		$sourceText = $translations['en_US'];
		$sourceHash = md5($sourceText);
		$pdo = Db::instance();

		$pdo->prepare(
			"INSERT INTO `i18n_messages` (`domain`, `key`, `context`, `source_text`, `source_hash`)
			VALUES (?, ?, '', ?, ?)
			ON DUPLICATE KEY UPDATE
				`source_text` = VALUES(`source_text`),
				`source_hash` = VALUES(`source_hash`)"
		)->execute(['admin', 'i18n.col.reviewed', $sourceText, $sourceHash]);

		$stmt = $pdo->prepare(
			"INSERT INTO `i18n_translations` (`domain`, `key`, `context`, `locale`, `text`, `human_reviewed`, `source_hash_snapshot`)
			VALUES (?, ?, '', ?, ?, 1, ?)
			ON DUPLICATE KEY UPDATE
				`text` = VALUES(`text`),
				`human_reviewed` = VALUES(`human_reviewed`),
				`source_hash_snapshot` = VALUES(`source_hash_snapshot`)"
		);

		foreach ($translations as $locale => $text) {
			$stmt->execute(['admin', 'i18n.col.reviewed', $locale, $text, $sourceHash]);
		}
	}
}
