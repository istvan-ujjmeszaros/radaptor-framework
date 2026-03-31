<?php

class Migration_20260310_000002_add_ai_generated_i18n_status
{
	public function run(): void
	{
		$pdo = Db::instance();

		$stmt = $pdo->query("SHOW COLUMNS FROM i18n_translations LIKE 'status'");
		$row  = $stmt->fetch(\PDO::FETCH_ASSOC);

		if ($row === false) {
			return;
		}

		if ($row && str_contains((string) ($row['Type'] ?? ''), 'ai_generated')) {
			return;
		}

		$pdo->exec(
			"ALTER TABLE i18n_translations
			 MODIFY COLUMN `status`
			 ENUM('missing','translated','needs_review','approved','ai_generated')
			 NOT NULL DEFAULT 'missing'"
		);
	}
}
