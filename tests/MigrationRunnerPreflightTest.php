<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.testing.php';

final class MigrationRunnerPreflightTest extends TestCase
{
	private PDO $admin_pdo;
	private string $temporary_database = '';
	private array $original_pdo_instances = [];
	private string|false $original_env = false;
	private bool $original_env_exists = false;

	protected function setUp(): void
	{
		$this->admin_pdo = Db::createIndependentPdoConnection(Config::DB_DEFAULT_DSN->value());
		$this->temporary_database = 'radaptor_migration_preflight_' . bin2hex(random_bytes(4)) . '_test';
		$this->admin_pdo->exec(
			'CREATE DATABASE `' . str_replace('`', '``', $this->temporary_database) . '`'
				. ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);
		$this->original_pdo_instances = Db::$pdoInstances;
		$this->original_env = getenv('DB_DEFAULT_DSN');
		$this->original_env_exists = $this->original_env !== false;
	}

	protected function tearDown(): void
	{
		$this->restoreDefaultDsn();
		Db::$pdoInstances = $this->original_pdo_instances;

		if ($this->temporary_database !== '') {
			$this->admin_pdo->exec(
				'DROP DATABASE IF EXISTS `' . str_replace('`', '``', $this->temporary_database) . '`'
			);
		}
	}

	public function testPreflightRejectsMetadataOnlyDatabase(): void
	{
		$this->switchDefaultDsnToTemporaryDatabase();
		MigrationRunner::ensureMigrationsTable();

		$result = MigrationRunner::checkPendingMigrations([$this->fakeMigrationDescriptor()]);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Database schema is not initialized', $result['message']);
	}

	public function testPreflightAllowsDatabaseWithApplicationTables(): void
	{
		$this->switchDefaultDsnToTemporaryDatabase();
		MigrationRunner::ensureMigrationsTable();
		Db::instance()->exec('CREATE TABLE users (user_id INT UNSIGNED NOT NULL PRIMARY KEY)');

		$result = MigrationRunner::checkPendingMigrations([$this->fakeMigrationDescriptor()]);

		$this->assertTrue($result['success']);
	}

	private function switchDefaultDsnToTemporaryDatabase(): void
	{
		$dsn = $this->rewriteDsnDatabaseName(Config::DB_DEFAULT_DSN->value(), $this->temporary_database);
		putenv('DB_DEFAULT_DSN=' . $dsn);
		$_ENV['DB_DEFAULT_DSN'] = $dsn;
		$_SERVER['DB_DEFAULT_DSN'] = $dsn;
		Db::$pdoInstances = [];
	}

	private function restoreDefaultDsn(): void
	{
		if ($this->original_env_exists) {
			putenv('DB_DEFAULT_DSN=' . (string) $this->original_env);
			$_ENV['DB_DEFAULT_DSN'] = (string) $this->original_env;
			$_SERVER['DB_DEFAULT_DSN'] = (string) $this->original_env;

			return;
		}

		putenv('DB_DEFAULT_DSN');
		unset($_ENV['DB_DEFAULT_DSN'], $_SERVER['DB_DEFAULT_DSN']);
	}

	private function rewriteDsnDatabaseName(string $dsn, string $database): string
	{
		$parts = explode(';', $dsn);

		foreach ($parts as &$part) {
			if (str_starts_with($part, 'dbname=')) {
				$part = 'dbname=' . $database;

				return implode(';', $parts);
			}
		}

		$this->fail('Test DSN does not contain dbname.');
	}

	/**
	 * @return array{
	 *     key: string,
	 *     module: string,
	 *     filename: string,
	 *     filepath: string,
	 *     hash: string,
	 *     base_class_name: string,
	 *     runtime_class_name: string
	 * }
	 */
	private function fakeMigrationDescriptor(): array
	{
		return [
			'key' => 'app:20260513_120000_preflight_fixture.php',
			'module' => 'app',
			'filename' => '20260513_120000_preflight_fixture.php',
			'filepath' => __FILE__,
			'hash' => md5('app:20260513_120000_preflight_fixture.php'),
			'base_class_name' => 'Migration_20260513_120000_preflight_fixture',
			'runtime_class_name' => 'RuntimeMigration_app_20260513_120000_preflight_fixture',
		];
	}
}
