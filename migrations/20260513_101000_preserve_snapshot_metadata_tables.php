<?php

declare(strict_types=1);

class Migration_20260513_101000_preserve_snapshot_metadata_tables
{
	public function run(): void
	{
		$pdo = Db::instance();

		foreach (['migrations', 'seeds'] as $table) {
			$this->removeTableCommentToken($pdo, $table, '__noexport');
		}
	}

	public function getDescription(): string
	{
		return 'Keep migration and seed metadata in site snapshots.';
	}

	private function removeTableCommentToken(PDO $pdo, string $table, string $token): void
	{
		$current_comment = $this->getTableComment($pdo, $table);

		if ($current_comment === null) {
			return;
		}

		$normalized_token = strtolower(trim($token));
		$tokens = [];

		foreach (explode(',', $current_comment) as $existing_token) {
			$existing_token = trim($existing_token);

			if ($existing_token === '' || strtolower($existing_token) === $normalized_token) {
				continue;
			}

			$tokens[strtolower($existing_token)] = $existing_token;
		}

		$comment = implode(', ', array_values($tokens));
		$pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` COMMENT = ' . $pdo->quote($comment));
	}

	private function getTableComment(PDO $pdo, string $table): ?string
	{
		$stmt = $pdo->prepare(
			"SELECT TABLE_COMMENT
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = DATABASE()
			  AND TABLE_TYPE = 'BASE TABLE'
			  AND TABLE_NAME = ?"
		);
		$stmt->execute([$table]);
		$value = $stmt->fetchColumn();

		return is_string($value) ? $value : null;
	}
}
