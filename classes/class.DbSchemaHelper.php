<?php

declare(strict_types=1);

final class DbSchemaHelper
{
	public static function tableExists(string $table, ?PDO $pdo = null): bool
	{
		try {
			$pdo ??= Db::instance();
			$stmt = $pdo->prepare(
				"SELECT 1
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = DATABASE()
					AND TABLE_NAME = ?"
			);
			$stmt->execute([$table]);

			return (bool) $stmt->fetchColumn();
		} catch (Throwable) {
			return false;
		}
	}

	public static function columnExists(string $table, string $column, ?PDO $pdo = null): bool
	{
		try {
			$pdo ??= Db::instance();

			if (!self::tableExists($table, $pdo)) {
				return false;
			}

			$stmt = $pdo->prepare(
				"SELECT 1
				FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE()
					AND TABLE_NAME = ?
					AND COLUMN_NAME = ?"
			);
			$stmt->execute([$table, $column]);

			return (bool) $stmt->fetchColumn();
		} catch (Throwable) {
			return false;
		}
	}
}
