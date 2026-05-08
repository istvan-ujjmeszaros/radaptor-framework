<?php

class Migration_20260508_111000_drop_richtext_legacy_name_unique
{
	public function run(): void
	{
		$pdo = Db::instance();

		if (!$this->tableExists($pdo, 'richtext')) {
			return;
		}

		foreach ($this->getSingleColumnUniqueIndexes($pdo, 'richtext', 'name') as $index) {
			$pdo->exec('ALTER TABLE `richtext` DROP INDEX `' . str_replace('`', '``', $index) . '`');
		}
	}

	private function tableExists(PDO $pdo, string $table): bool
	{
		$stmt = $pdo->prepare(
			"SELECT 1
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = ?"
		);
		$stmt->execute([$table]);

		return (bool) $stmt->fetchColumn();
	}

	/**
	 * @return list<string>
	 */
	private function getSingleColumnUniqueIndexes(PDO $pdo, string $table, string $column): array
	{
		$stmt = $pdo->prepare(
			"SELECT `INDEX_NAME`
			FROM information_schema.STATISTICS
			WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = ?
				AND NON_UNIQUE = 0
				AND INDEX_NAME <> 'PRIMARY'
			GROUP BY `INDEX_NAME`
			HAVING COUNT(*) = 1
				AND MAX(`COLUMN_NAME` = ?) = 1"
		);
		$stmt->execute([$table, $column]);

		return array_map(
			static fn (array $row): string => (string) ($row['INDEX_NAME'] ?? ''),
			$stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
		);
	}
}
