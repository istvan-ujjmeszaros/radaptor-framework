<?php

class Migration_20260309_000001_add_i18n_tables
{
	public function run(): void
	{
		$pdo = Db::instance();

		$pdo->exec("CREATE TABLE IF NOT EXISTS `i18n_messages` (
			`domain`       VARCHAR(100) NOT NULL,
			`key`          VARCHAR(255) NOT NULL,
			`context`      VARCHAR(100) NOT NULL DEFAULT '',
			`source_text`  TEXT NOT NULL DEFAULT '',
			`source_hash`  CHAR(32) NOT NULL DEFAULT '',
			`metadata`     JSON NULL,
			PRIMARY KEY (`domain`, `key`, `context`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `i18n_translations` (
			`domain`               VARCHAR(100) NOT NULL,
			`key`                  VARCHAR(255) NOT NULL,
			`context`              VARCHAR(100) NOT NULL DEFAULT '',
			`locale`               VARCHAR(10) NOT NULL,
			`text`                 TEXT NOT NULL DEFAULT '',
			`status`               ENUM('missing','translated','needs_review','approved') NOT NULL DEFAULT 'missing',
			`source_hash_snapshot` CHAR(32) NOT NULL DEFAULT '',
			PRIMARY KEY (`domain`, `key`, `context`, `locale`),
			CONSTRAINT `fk_i18n_translations_messages`
				FOREIGN KEY (`domain`, `key`, `context`)
				REFERENCES `i18n_messages` (`domain`, `key`, `context`)
				ON DELETE CASCADE ON UPDATE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `i18n_build_state` (
			`locale`       VARCHAR(10) NOT NULL,
			`catalog_hash` CHAR(32) NOT NULL,
			`built_at`     DATETIME NOT NULL,
			PRIMARY KEY (`locale`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$pdo->exec("CREATE TABLE IF NOT EXISTS `i18n_tm_entries` (
			`tm_id`                  INT NOT NULL AUTO_INCREMENT,
			`source_locale`          VARCHAR(10) NOT NULL,
			`target_locale`          VARCHAR(10) NOT NULL,
			`source_text_normalized` TEXT NOT NULL,
			`source_text_raw`        TEXT NOT NULL,
			`target_text`            TEXT NOT NULL,
			`domain`                 VARCHAR(100) NOT NULL DEFAULT '',
			`context`                VARCHAR(100) NOT NULL DEFAULT '',
			`source_hash`            CHAR(32) NOT NULL,
			`usage_count`            INT NOT NULL DEFAULT 0,
			`quality_score`          ENUM('manual','approved','imported','mt') NOT NULL DEFAULT 'mt',
			`created_by`             INT NULL,
			`updated_by`             INT NULL,
			`created_at`             DATETIME NOT NULL,
			`updated_at`             DATETIME NOT NULL,
			PRIMARY KEY (`tm_id`),
			INDEX `idx_tm_lookup` (`source_locale`, `target_locale`, `source_hash`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	}
}
