<?php

/**
 * Rebuild source_text_normalized in i18n_tm_entries.
 *
 * Normalizes: mb_strtolower() + whitespace collapse + unicode NFC.
 *
 * Usage: radaptor i18n:tm-reindex
 */
class CLICommandI18nTmReindex extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Reindex translation memory';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Rebuild source_text_normalized in i18n_tm_entries.

			Usage: radaptor i18n:tm-reindex

			Examples:
			  radaptor i18n:tm-reindex
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function getWebTimeout(): int
	{
		return 60;
	}

	public function run(): void
	{
		$pdo = Db::instance();

		$stmt = $pdo->query("SELECT tm_id, source_text_raw FROM i18n_tm_entries");
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		if (empty($rows)) {
			echo "No TM entries found.\n";

			return;
		}

		$update = $pdo->prepare(
			"UPDATE i18n_tm_entries SET source_text_normalized = :normalized WHERE tm_id = :tm_id"
		);

		$count = 0;

		foreach ($rows as $row) {
			$normalized = I18nTm::_normalize($row['source_text_raw']);
			$update->execute([':normalized' => $normalized, ':tm_id' => $row['tm_id']]);
			$count++;
		}

		echo "Reindexed {$count} TM entries.\n";
	}
}
