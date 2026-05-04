<?php

class TestDatabaseSchemaSyncService
{
	private static ?int $_runtimeSchemaToken = null;
	private static bool $_runtimeSchemaShutdownRegistered = false;

	/**
	 * @return array{
	 *     drift_detected: bool,
	 *     schema_rebuilt: bool,
	 *     fixtures_loaded: bool,
	 *     fixtures_missing: bool,
	 *     runtime_schema_refreshed: bool,
	 *     schema_errors: array<string, array<string>>
	 * }
	 */
	public static function bootstrap(): array
	{
		$result = self::sync(false);

		self::refreshRuntimeSchemaOverride();
		$result['runtime_schema_refreshed'] = true;

		return $result;
	}

	/**
	 * @return array{
	 *     drift_detected: bool,
	 *     schema_rebuilt: bool,
	 *     fixtures_loaded: bool,
	 *     fixtures_missing: bool,
	 *     runtime_schema_refreshed: bool,
	 *     schema_errors: array<string, array<string>>
	 * }
	 */
	public static function sync(bool $dry_run = false): array
	{
		self::assertTestDatabaseTargets();
		$schema_errors = self::getSchemaErrors();
		$drift_detected = $schema_errors !== [];
		$schema_rebuilt = false;
		$fixtures_loaded = false;
		$fixtures_missing = false;

		if ($drift_detected && !$dry_run) {
			self::recreateSchema();
			self::rebuildTestDatabaseHooks();
			Fixtures::loadAll();
			$schema_rebuilt = true;
			$fixtures_loaded = true;
		}

		if (!$schema_rebuilt) {
			$fixtures_missing = !self::hasFixtureBaseline();

			if ($fixtures_missing && !$dry_run) {
				Fixtures::loadAll();
				$fixtures_loaded = true;
			}
		}

		return [
			'drift_detected' => $drift_detected,
			'schema_rebuilt' => $schema_rebuilt,
			'fixtures_loaded' => $fixtures_loaded,
			'fixtures_missing' => $fixtures_missing,
			'runtime_schema_refreshed' => false,
			'schema_errors' => $schema_errors,
		];
	}

	public static function assertTestDatabaseTargets(): void
	{
		self::assertSafeTestDsn(self::getTestDsn(), 'default');

		if (self::isAuditRuntimeAvailable()) {
			self::assertSafeTestDsn(self::getTestAuditDsn(), 'audit');
		}
	}

	/**
	 * @return array<string, array<string>>
	 */
	public static function getSchemaErrors(): array
	{
		$errors = [];

		self::collectSchemaErrors(
			$errors,
			self::getDevPdo(),
			self::extractDbNameFromDsn(self::getDevDsn()),
			Db::instance(self::getTestDsn()),
			self::extractDbNameFromDsn(self::getTestDsn())
		);

		if (self::isAuditRuntimeAvailable()) {
			self::collectSchemaErrors(
				$errors,
				self::getDevAuditPdo(),
				self::extractDbNameFromDsn(self::getDevAuditDsn()),
				Db::instance(self::getTestAuditDsn()),
				self::extractDbNameFromDsn(self::getTestAuditDsn()),
				'audit_'
			);
		}

		return array_filter($errors, static fn (array $items): bool => $items !== []);
	}

	public static function recreateSchema(): void
	{
		self::recreateDatabaseSchema(
			self::getDevPdo(),
			self::extractDbNameFromDsn(self::getDevDsn()),
			Db::instance(self::getTestDsn()),
			self::extractDbNameFromDsn(self::getTestDsn())
		);

		if (self::isAuditRuntimeAvailable()) {
			self::recreateDatabaseSchema(
				self::getDevAuditPdo(),
				self::extractDbNameFromDsn(self::getDevAuditDsn()),
				Db::instance(self::getTestAuditDsn()),
				self::extractDbNameFromDsn(self::getTestAuditDsn())
			);
		}
	}

	public static function refreshRuntimeSchemaOverride(): void
	{
		$target_dsns = [self::getTestDsn()];

		if (self::isAuditRuntimeAvailable()) {
			$target_dsns[] = self::getTestAuditDsn();
		}

		$schema = DbSchemaDataBuilder::buildSchemaArray($target_dsns);

		if (self::$_runtimeSchemaToken !== null) {
			DbSchemaData::popRuntimeSchema(self::$_runtimeSchemaToken);
			self::$_runtimeSchemaToken = null;
		}

		self::$_runtimeSchemaToken = DbSchemaData::pushRuntimeSchema($schema);

		if (!self::$_runtimeSchemaShutdownRegistered) {
			self::$_runtimeSchemaShutdownRegistered = true;
			register_shutdown_function(static function (): void {
				if (self::$_runtimeSchemaToken !== null) {
					DbSchemaData::popRuntimeSchema(self::$_runtimeSchemaToken);
					self::$_runtimeSchemaToken = null;
				}
			});
		}
	}

	private static function hasFixtureBaseline(): bool
	{
		$pdo = Db::instance(self::getTestDsn());

		try {
			$stmt = $pdo->prepare(
				'SELECT COUNT(*) FROM users WHERE username IN (?, ?)'
			);
			$stmt->execute(['admin_developer', 'user_noroles']);

			if ((int) $stmt->fetchColumn() !== 2) {
				return false;
			}

			$domain_root_stmt = $pdo->prepare(
				"SELECT node_id
				FROM resource_tree
				WHERE node_type = 'root' AND resource_name = ?
				LIMIT 1"
			);
			$site_context = class_exists('CmsSiteContext') && method_exists('CmsSiteContext', 'getConfiguredSiteKey')
				? CmsSiteContext::getConfiguredSiteKey()
				: Config::APP_DOMAIN_CONTEXT->value();
			$domain_root_stmt->execute([$site_context]);
			$domain_root_id = $domain_root_stmt->fetchColumn();

			if (!is_numeric($domain_root_id) || (int) $domain_root_id <= 0) {
				return false;
			}

			$required_pages_stmt = $pdo->prepare(
				"SELECT COUNT(*)
				FROM resource_tree
				WHERE parent_id = ?
					AND node_type = 'webpage'
					AND path = '/'
					AND resource_name IN ('index.html', 'login.html')"
			);
			$required_pages_stmt->execute([(int) $domain_root_id]);
		} catch (Throwable) {
			return false;
		}

		return (int) $required_pages_stmt->fetchColumn() === 2;
	}

	private static function rebuildTestDatabaseHooks(): void
	{
		$target_dsns = [self::getTestDsn()];

		if (self::isAuditRuntimeAvailable()) {
			$target_dsns[] = self::getTestAuditDsn();
		}

		DbSchemaDataBuilder::buildSchemaArray(
			$target_dsns,
			run_plugin_hooks: true
		);
	}

	private static function isAuditRuntimeAvailable(): bool
	{
		return class_exists('AuditTriggerManager');
	}

	private static function getDevDsn(): string
	{
		return Config::DB_DEFAULT_DSN->value();
	}

	private static function getTestDsn(): string
	{
		return Db::rewriteDsnToTesting(self::getDevDsn());
	}

	private static function getDevAuditDsn(): string
	{
		return self::rewriteRawDsnToAudit(self::getDevDsn());
	}

	private static function getTestAuditDsn(): string
	{
		return self::rewriteRawDsnToAudit(self::getTestDsn());
	}

	private static function assertSafeTestDsn(string $dsn, string $label): void
	{
		$db_name = self::extractDbNameFromDsn($dsn);
		$allowed_suffixes = ['_test', '_test_audit'];
		$safe = false;

		foreach ($allowed_suffixes as $suffix) {
			if (str_ends_with($db_name, $suffix)) {
				$safe = true;

				break;
			}
		}

		if (!$safe) {
			throw new RuntimeException(
				"Test schema sync safety check failed for {$label} DSN: expected a _test or _test_audit database, got '{$db_name}'."
			);
		}
	}

	private static function getDevPdo(): PDO
	{
		static $dev_pdo = null;

		if (!$dev_pdo instanceof PDO) {
			$dev_pdo = new PDO(self::getDevDsn());
			$dev_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$dev_pdo->exec('SET NAMES utf8mb4');
		}

		return $dev_pdo;
	}

	private static function getDevAuditPdo(): PDO
	{
		static $dev_audit_pdo = null;

		if (!$dev_audit_pdo instanceof PDO) {
			$dev_audit_pdo = new PDO(self::getDevAuditDsn());
			$dev_audit_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$dev_audit_pdo->exec('SET NAMES utf8mb4');
		}

		return $dev_audit_pdo;
	}

	/**
	 * @param array<string, array<string>> $errors
	 */
	private static function collectSchemaErrors(
		array &$errors,
		PDO $source_pdo,
		string $source_db_name,
		PDO $target_pdo,
		string $target_db_name,
		string $prefix = ''
	): void {
		$errors[$prefix . 'missing_tables'] = [];
		$errors[$prefix . 'extra_tables'] = [];
		$errors[$prefix . 'column_mismatches'] = [];

		$source_tables = self::getTables($source_pdo, $source_db_name);
		$target_tables = self::getTables($target_pdo, $target_db_name);

		$errors[$prefix . 'missing_tables'] = array_values(array_diff($source_tables, $target_tables));
		$errors[$prefix . 'extra_tables'] = array_values(array_diff($target_tables, $source_tables));

		foreach (array_intersect($source_tables, $target_tables) as $table) {
			$source_create = self::normalizeCreateTable(self::getCreateTable($source_pdo, $table));
			$target_create = self::normalizeCreateTable(self::getCreateTable($target_pdo, $table));

			if ($source_create !== $target_create) {
				$errors[$prefix . 'column_mismatches'][] = $table;
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private static function getTables(PDO $pdo, string $db_name): array
	{
		$stmt = $pdo->prepare(
			"SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'"
		);
		$stmt->execute([$db_name]);

		$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
		sort($tables);

		return $tables;
	}

	private static function recreateDatabaseSchema(
		PDO $source_pdo,
		string $source_db_name,
		PDO $target_pdo,
		string $target_db_name
	): void {
		$target_pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

		try {
			foreach (self::getTables($target_pdo, $target_db_name) as $table) {
				$target_pdo->exec("DROP TABLE IF EXISTS `{$table}`");
			}

			foreach (self::getTables($source_pdo, $source_db_name) as $table) {
				$target_pdo->exec(self::getCreateTable($source_pdo, $table));
			}
		} finally {
			$target_pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
		}
	}

	private static function getCreateTable(PDO $pdo, string $table): string
	{
		$stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return (string) ($row['Create Table'] ?? '');
	}

	private static function normalizeCreateTable(string $create_stmt): string
	{
		$normalized = preg_replace('/AUTO_INCREMENT=\d+\s*/i', '', $create_stmt);
		$normalized = preg_replace('/\s+/', ' ', (string) $normalized);

		return trim((string) $normalized);
	}

	private static function extractDbNameFromDsn(string $dsn): string
	{
		foreach (explode(';', $dsn) as $part) {
			if (str_starts_with($part, 'dbname=')) {
				return substr($part, strlen('dbname='));
			}
		}

		throw new RuntimeException("DSN does not contain a database name: {$dsn}");
	}

	private static function rewriteRawDsnToAudit(string $dsn): string
	{
		$dsn_components = [];

		foreach (explode(';', $dsn) as $part) {
			if (str_starts_with($part, 'dbname=')) {
				$db_name = substr($part, strlen('dbname='));

				if (!str_ends_with($db_name, '_audit')) {
					$part = 'dbname=' . $db_name . '_audit';
				}
			}

			$dsn_components[] = $part;
		}

		return implode(';', $dsn_components);
	}
}
