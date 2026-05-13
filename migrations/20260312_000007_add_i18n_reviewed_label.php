<?php

/**
 * Seed reviewed-column labels for the i18n workbench.
 */
class Migration_20260312_000007_add_i18n_reviewed_label
{
	public function run(): void
	{
		$translations = [
			'en-US' => 'Reviewed',
			'hu-HU' => 'Ellenőrizve',
			'de-DE' => 'Geprüft',
		];

		$sourceText = $translations['en-US'];
		$sourceHash = md5($sourceText);
		$pdo = Db::instance();
		$existing_locales = $this->getExistingLocales($pdo);
		$should_filter_locales = $existing_locales !== null;

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
			if ($should_filter_locales && !isset($existing_locales[$locale])) {
				continue;
			}

			$stmt->execute(['admin', 'i18n.col.reviewed', $locale, $text, $sourceHash]);
		}
	}

	/**
	 * @return array<string, bool>|null
	 */
	private function getExistingLocales(PDO $pdo): ?array
	{
		try {
			$rows = $pdo->query("SELECT `locale` FROM `locales`")->fetchAll(PDO::FETCH_ASSOC);
		} catch (Throwable) {
			return null;
		}

		$locales = [];

		foreach ($rows as $row) {
			$locale = (string) ($row['locale'] ?? '');

			if ($locale === '') {
				continue;
			}

			$locales[$locale] = true;
		}

		return $locales;
	}
}
