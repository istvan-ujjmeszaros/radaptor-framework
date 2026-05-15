<?php

/**
 * Handles database migrations for the Radaptor framework.
 *
 * Migrations are discovered from deterministic package-aware sources:
 * - framework migrations
 * - installed core/theme package migrations
 * - app migrations
 */
class MigrationRunner
{
	private const string PREFLIGHT_MODULE = 'framework';
	private const string PREFLIGHT_FILENAME = '__preflight__.php';

	/**
	 * Get the migration directories to scan.
	 *
	 * @return array<int, array{module: string, path: string}>
	 */
	private static function getMigrationDirs(): array
	{
		$dirs = [];
		$framework_root = PackagePathHelper::getFrameworkRoot();
		$framework_migrations = is_string($framework_root) ? rtrim($framework_root, '/') . '/migrations' : null;

		if (is_string($framework_migrations) && is_dir($framework_migrations)) {
			$dirs[] = [
				'module' => 'framework',
				'path' => $framework_migrations,
			];
		}

		$dirs = [...$dirs, ...self::getPackageMigrationDirs()];

		$app_migrations = DEPLOY_ROOT . 'app/migrations';

		if (is_dir($app_migrations)) {
			$dirs[] = [
				'module' => 'app',
				'path' => $app_migrations,
			];
		}

		return $dirs;
	}

	private static function getMigrationsFilePath(): string
	{
		return DEPLOY_ROOT . 'generated/__migrations__.php';
	}

	public static function ensureMigrationsTable(): void
	{
		$pdo = Db::instance();

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS migrations (
				migration_hash VARCHAR(32) NOT NULL,
				module VARCHAR(100) NOT NULL DEFAULT 'framework',
				migration_name VARCHAR(255) NOT NULL,
				applied_at DATETIME NOT NULL,
				PRIMARY KEY (migration_hash),
				UNIQUE KEY uq_module_filename (module, migration_name)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='__noaudit'"
		);

		if (!self::tableColumnExists('migrations', 'module')) {
			$pdo->exec("ALTER TABLE migrations ADD COLUMN module VARCHAR(100) NOT NULL DEFAULT '' AFTER migration_hash");
		}

		self::normalizeLegacyAppliedMigrations();

		if (!self::tableIndexExists('migrations', 'uq_module_filename')) {
			$pdo->exec("ALTER TABLE migrations ADD UNIQUE KEY uq_module_filename (module, migration_name)");
		}

		$pdo->exec("ALTER TABLE migrations MODIFY COLUMN module VARCHAR(100) NOT NULL DEFAULT 'framework'");
	}

	/**
	 * @return array<string, array{
	 *     module: string,
	 *     migration_name: string,
	 *     applied_at: string,
	 *     migration_hash: string
	 * }>
	 */
	public static function getAppliedMigrations(): array
	{
		self::ensureMigrationsTable();

		return self::readAppliedMigrations(true);
	}

	/**
	 * @return array<string, array{
	 *     module: string,
	 *     migration_name: string,
	 *     applied_at: string,
	 *     migration_hash: string
	 * }>
	 */
	private static function getAppliedMigrationsReadonly(): array
	{
		if (!self::tableExists('migrations') || !self::tableColumnExists('migrations', 'migration_name')) {
			return [];
		}

		return self::readAppliedMigrations(false);
	}

	/**
	 * @return array<string, array{
	 *     module: string,
	 *     migration_name: string,
	 *     applied_at: string,
	 *     migration_hash: string
	 * }>
	 */
	private static function readAppliedMigrations(bool $table_is_current): array
	{
		$has_module_column = $table_is_current || self::tableColumnExists('migrations', 'module');
		$has_hash_column = $table_is_current || self::tableColumnExists('migrations', 'migration_hash');
		$has_applied_at_column = $table_is_current || self::tableColumnExists('migrations', 'applied_at');
		$module_select = $has_module_column ? 'module' : "'' AS module";
		$hash_select = $has_hash_column ? 'migration_hash' : "'' AS migration_hash";
		$applied_at_select = $has_applied_at_column ? 'applied_at' : "'' AS applied_at";
		$known_modules_by_filename = $has_module_column ? [] : self::getKnownModulesByFilename();
		$stmt = Db::instance()->prepare(
			"SELECT {$hash_select}, {$module_select}, migration_name, {$applied_at_select}
			 FROM migrations
			 ORDER BY applied_at, module, migration_name"
		);
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$applied = [];

		foreach ($rows as $row) {
			$filename = (string) $row['migration_name'];
			$module = self::resolveAppliedMigrationModule($filename, (string) ($row['module'] ?? ''), $known_modules_by_filename);
			$key = self::buildMigrationKey($module, $filename);
			$migration_hash = (string) ($row['migration_hash'] ?? '');

			$applied[$key] = [
				'module' => $module,
				'migration_name' => $filename,
				'applied_at' => (string) $row['applied_at'],
				'migration_hash' => $migration_hash !== '' ? $migration_hash : self::buildMigrationHash($module, $filename),
			];
		}

		return $applied;
	}

	/**
	 * @return array<int, array{
	 *     key: string,
	 *     module: string,
	 *     filename: string,
	 *     filepath: string,
	 *     hash: string,
	 *     base_class_name: string,
	 *     runtime_class_name: string
	 * }>
	 */
	public static function getAllMigrationFiles(): array
	{
		$all_files = [];

		foreach (self::getMigrationDirs() as $dir) {
			if (!is_dir($dir['path'])) {
				continue;
			}

			$files = glob($dir['path'] . '/*.php');

			if ($files === false) {
				continue;
			}

			foreach ($files as $filepath) {
				$filename = basename($filepath);

				if (!preg_match('/^\d{8}_\d{6}_[a-zA-Z0-9_]+\.php$/', $filename)) {
					continue;
				}

				$all_files[] = self::buildMigrationDescriptor($dir['module'], $filepath);
			}
		}

		$installed_module_order = self::getInstalledModuleOrderMap();
		usort(
			$all_files,
			static fn (array $a, array $b): int => self::compareMigrations($a, $b, $installed_module_order)
		);

		return $all_files;
	}

	/**
	 * @return array<int, array{
	 *     key: string,
	 *     module: string,
	 *     filename: string,
	 *     filepath: string,
	 *     hash: string,
	 *     base_class_name: string,
	 *     runtime_class_name: string
	 * }>
	 */
	public static function getPendingMigrations(): array
	{
		return self::buildPendingMigrations(self::getAppliedMigrations());
	}

	/**
	 * @return array<int, array{
	 *     key: string,
	 *     module: string,
	 *     filename: string,
	 *     filepath: string,
	 *     hash: string,
	 *     base_class_name: string,
	 *     runtime_class_name: string
	 * }>
	 */
	public static function getPendingMigrationsForDryRun(): array
	{
		return self::buildPendingMigrations(self::getAppliedMigrationsReadonly());
	}

	/**
	 * @param array<string, array{
	 *     module: string,
	 *     migration_name: string,
	 *     applied_at: string,
	 *     migration_hash: string
	 * }> $applied
	 * @return array<int, array{
	 *     key: string,
	 *     module: string,
	 *     filename: string,
	 *     filepath: string,
	 *     hash: string,
	 *     base_class_name: string,
	 *     runtime_class_name: string
	 * }>
	 */
	private static function buildPendingMigrations(array $applied): array
	{
		$all_files = self::getAllMigrationFiles();

		return array_values(array_filter(
			$all_files,
			static fn (array $migration): bool => !isset($applied[$migration['key']])
		));
	}

	/**
	 * @param array<int, array{
	 *     key: string,
	 *     module: string,
	 *     filename: string,
	 *     filepath: string,
	 *     hash: string,
	 *     base_class_name: string,
	 *     runtime_class_name: string
	 * }>|null $pending
	 * @return array{success: bool, message: string, pending_count: int}
	 */
	public static function checkPendingMigrations(?array $pending = null): array
	{
		$pending ??= self::getPendingMigrations();

		if ($pending === []) {
			return [
				'success' => true,
				'message' => 'No pending migrations.',
				'pending_count' => 0,
			];
		}

		try {
			self::assertDatabaseReadyForPendingMigrations($pending);
		} catch (Throwable $e) {
			return [
				'success' => false,
				'message' => $e->getMessage(),
				'pending_count' => count($pending),
			];
		}

		return [
			'success' => true,
			'message' => 'Migration preflight passed.',
			'pending_count' => count($pending),
		];
	}

	/**
	 * @param array<int, array{
	 *     key: string,
	 *     module: string,
	 *     filename: string,
	 *     filepath: string,
	 *     hash: string,
	 *     base_class_name: string,
	 *     runtime_class_name: string
	 * }>|null $pending
	 * @return array{
	 *     success: bool,
	 *     message: string,
	 *     pending_count: int,
	 *     sandbox_database: string|null,
	 *     migrations: array<int, array<string, mixed>>
	 * }
	 */
	public static function provePendingMigrationsInSandbox(?array $pending = null): array
	{
		$pending ??= self::getPendingMigrations();
		$preflight = self::checkPendingMigrations($pending);

		if (!$preflight['success'] || $pending === []) {
			return [
				'success' => $preflight['success'],
				'message' => $preflight['message'],
				'pending_count' => $preflight['pending_count'],
				'sandbox_database' => null,
				'migrations' => [],
			];
		}

		$source_dsn = Config::DB_DEFAULT_DSN->value();
		$source_pdo = Db::instance();
		$source_database = self::getCurrentDatabaseName($source_pdo);
		$sandbox_database = self::buildSandboxDatabaseName($source_database);
		$sandbox_dsn = self::rewriteDsnDatabaseName($source_dsn, $sandbox_database);
		$original_pdo_instances = Db::$pdoInstances;
		$original_env = getenv('DB_DEFAULT_DSN');
		$original_env_exists = $original_env !== false;

		$source_pdo->exec(
			'CREATE DATABASE ' . self::quoteIdentifier($sandbox_database)
				. ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);

		try {
			self::cloneDatabaseForMigrationSandbox($source_pdo, $source_database, $sandbox_database, $sandbox_dsn);
			self::setDefaultDsnForCurrentProcess($sandbox_dsn);
			Db::$pdoInstances = [];

			$results = self::runMigrations($pending, false, false, false);
			$success = self::allMigrationResultsSucceeded($results);

			return [
				'success' => $success,
				'message' => $success
					? 'Sandbox migration proof passed.'
					: 'Sandbox migration proof failed.',
				'pending_count' => count($pending),
				'sandbox_database' => $sandbox_database,
				'migrations' => $results,
			];
		} finally {
			self::restoreDefaultDsnForCurrentProcess($original_env, $original_env_exists);
			Db::$pdoInstances = $original_pdo_instances;
			$source_pdo->exec('DROP DATABASE IF EXISTS ' . self::quoteIdentifier($sandbox_database));
		}
	}

	/**
	 * @param list<string> $modules
	 * @return array<int, array{
	 *     key: string,
	 *     module: string,
	 *     filename: string,
	 *     filepath: string,
	 *     hash: string,
	 *     base_class_name: string,
	 *     runtime_class_name: string
	 * }>
	 */
	public static function getPendingMigrationsForModules(array $modules): array
	{
		$modules = array_values(array_filter(
			array_unique(array_map(
				static fn (mixed $module): string => PackageModuleHelper::normalizeRequestedModule((string) $module),
				$modules
			)),
			static fn (string $module): bool => $module !== ''
		));

		if ($modules === []) {
			return [];
		}

		return array_values(array_filter(
			self::getPendingMigrations(),
			static fn (array $migration): bool => in_array($migration['module'], $modules, true)
		));
	}

	/**
	 * Run a single migration.
	 *
	 * @param string $filepath Full path to migration file
	 * @param string|null $module Optional explicit module id
	 * @return array{success: bool, message: string, module: string, filename: string, description?: string}
	 */
	public static function runMigration(string $filepath, ?string $module = null, bool $run_preflight = true): array
	{
		$migration = self::buildMigrationDescriptor(
			$module ?? self::detectModuleFromFilepath($filepath),
			$filepath
		);
		$applied = self::getAppliedMigrations();

		if (isset($applied[$migration['key']])) {
			return [
				'success' => false,
				'message' => "Migration already applied: {$migration['module']} / {$migration['filename']}",
				'module' => $migration['module'],
				'filename' => $migration['filename'],
			];
		}

		if ($run_preflight) {
			$preflight = self::checkPendingMigrations([$migration]);

			if (!$preflight['success']) {
				return self::buildPreflightFailure($preflight['message'], $migration);
			}
		}

		try {
			MigrationContentGuard::assertMigrationSourceAllowed($migration['filepath']);
			$runtime_class_name = self::loadMigrationClass($migration);
		} catch (RuntimeException $e) {
			return [
				'success' => false,
				'message' => $e->getMessage(),
				'module' => $migration['module'],
				'filename' => $migration['filename'],
			];
		}

		if (!class_exists($runtime_class_name, false)) {
			return [
				'success' => false,
				'message' => "Migration class not found: {$migration['base_class_name']}",
				'module' => $migration['module'],
				'filename' => $migration['filename'],
			];
		}

		$migration_instance = new $runtime_class_name();
		$description = '';

		if (method_exists($migration_instance, 'getDescription')) {
			$description = (string) $migration_instance->getDescription();
		}

		$pdo = Db::instance();
		$started_transaction = false;
		$savepoint_name = null;

		try {
			if ($pdo->inTransaction()) {
				$savepoint_name = self::createMigrationSavepoint($pdo, $migration['hash']);
			} else {
				$pdo->beginTransaction();
				$started_transaction = true;
			}

			$resource_tree_snapshot = MigrationContentGuard::snapshotResourceTreeNodeIds();

			try {
				$captured_output = self::captureBufferedOutput(static function () use ($migration_instance): void {
					$migration_instance->run();
				});
			} finally {
				// Applies to package and app migrations. CMS content deletion belongs to
				// explicit authoring/import tools, not to the migration pipeline.
				MigrationContentGuard::assertNoResourceTreeRowsDeleted($resource_tree_snapshot, $migration['filename']);
			}

			if (trim($captured_output) !== '') {
				CLIOutput::write($captured_output);
			}

			$stmt = $pdo->prepare(
				"INSERT INTO migrations (migration_hash, module, migration_name, applied_at)
				 VALUES (?, ?, ?, ?)"
			);
			$stmt->execute([
				$migration['hash'],
				$migration['module'],
				$migration['filename'],
				date('Y-m-d H:i:s'),
			]);

			self::commitMigrationTransaction($pdo, $started_transaction, $savepoint_name);
		} catch (Throwable $e) {
			self::rollbackMigrationTransaction($pdo, $started_transaction, $savepoint_name);

			return [
				'success' => false,
				'message' => "Migration failed: " . $e->getMessage(),
				'module' => $migration['module'],
				'filename' => $migration['filename'],
				'description' => $description,
			];
		}

		return [
			'success' => true,
			'message' => "Applied: {$migration['module']} / {$migration['filename']}",
			'module' => $migration['module'],
			'filename' => $migration['filename'],
			'description' => $description,
		];
	}

	private static function createMigrationSavepoint(PDO $pdo, string $migration_hash): string
	{
		$savepoint_name = 'migration_runner_' . (preg_replace('/[^A-Za-z0-9_]/', '_', $migration_hash) ?? $migration_hash);
		$pdo->exec("SAVEPOINT {$savepoint_name}");

		return $savepoint_name;
	}

	private static function commitMigrationTransaction(PDO $pdo, bool $started_transaction, ?string $savepoint_name): void
	{
		if ($savepoint_name !== null && $pdo->inTransaction()) {
			$pdo->exec("RELEASE SAVEPOINT {$savepoint_name}");

			return;
		}

		if ($started_transaction && $pdo->inTransaction()) {
			$pdo->commit();
		}
	}

	private static function rollbackMigrationTransaction(PDO $pdo, bool $started_transaction, ?string $savepoint_name): void
	{
		if ($savepoint_name !== null && $pdo->inTransaction()) {
			$pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint_name}");
			$pdo->exec("RELEASE SAVEPOINT {$savepoint_name}");

			return;
		}

		if ($started_transaction && $pdo->inTransaction()) {
			$pdo->rollBack();
		}
	}

	/**
	 * @return array<int, array{
	 *     filename: string,
	 *     module: string,
	 *     success: bool,
	 *     message: string,
	 *     description?: string
	 * }>
	 */
	public static function runAllPending(bool $rebuild_schema = true): array
	{
		return self::runMigrations(self::getPendingMigrations(), $rebuild_schema);
	}

	/**
	 * @param list<string> $modules
	 * @return array<int, array{
	 *     filename: string,
	 *     module: string,
	 *     success: bool,
	 *     message: string,
	 *     description?: string
	 * }>
	 */
	public static function runPendingForModules(array $modules, bool $rebuild_schema = true): array
	{
		$modules = array_map(
			static fn (string $module): string => PackageModuleHelper::normalizeRequestedModule($module),
			$modules
		);

		return self::runMigrations(self::getPendingMigrationsForModules($modules), $rebuild_schema);
	}

	/**
	 * @param array<int, array{
	 *     key: string,
	 *     module: string,
	 *     filename: string,
	 *     filepath: string,
	 *     hash: string,
	 *     base_class_name: string,
	 *     runtime_class_name: string
	 * }> $pending
	 * @return array<int, array{
	 *     filename: string,
	 *     module: string,
	 *     success: bool,
	 *     message: string,
	 *     description?: string
	 * }>
	 */
	private static function runMigrations(
		array $pending,
		bool $rebuild_schema = true,
		bool $write_migrations_file = true,
		bool $run_preflight = true
	): array {
		if ($run_preflight) {
			$preflight = self::checkPendingMigrations($pending);

			if (!$preflight['success']) {
				return [self::buildPreflightFailure($preflight['message'], $pending[0] ?? null)];
			}
		}

		$results = [];
		$any_success = false;

		foreach ($pending as $migration) {
			$result = self::runMigration($migration['filepath'], $migration['module'], false);
			$results[] = $result;

			if ($result['success']) {
				$any_success = true;
			}

			if (!$result['success']) {
				break;
			}
		}

		if ($any_success && $write_migrations_file) {
			self::writeMigrationsFile();

			if ($rebuild_schema) {
				self::rebuildDbSchema();
			}
		}

		return $results;
	}

	/**
	 * @return array<int, array{module: string, path: string}>
	 */
	private static function getPackageMigrationDirs(): array
	{
		$dirs = [];
		$seen_modules = [];

		if (is_file(PackageLockfile::getPath())) {
			$lock = PackageLockfile::load();

			foreach ($lock['packages'] as $package) {
				$type = PackageTypeHelper::normalizeType($package['type'] ?? null, 'Migration package');
				$id = PackageTypeHelper::normalizeId($package['id'] ?? null, 'Migration package');

				if ($type === 'core' && $id === 'framework') {
					continue;
				}

				$root = PackagePathHelper::getPackageRoot($type, $id);

				if (!is_string($root) || !is_dir($root)) {
					continue;
				}

				$dir = rtrim($root, '/') . '/migrations';

				if (!is_dir($dir)) {
					continue;
				}

				$module = PackageModuleHelper::buildModule($type, $id);
				$seen_modules[$module] = true;
				$dirs[] = [
					'module' => $module,
					'path' => $dir,
				];
			}
		}

		foreach ([
			['type' => 'core', 'path' => DEPLOY_ROOT . 'packages/dev/core'],
			['type' => 'core', 'path' => DEPLOY_ROOT . 'packages/registry/core'],
			['type' => 'theme', 'path' => DEPLOY_ROOT . 'packages/dev/themes'],
			['type' => 'theme', 'path' => DEPLOY_ROOT . 'packages/registry/themes'],
		] as $package_root) {
			if (!is_dir($package_root['path'])) {
				continue;
			}

			$package_dirs = glob($package_root['path'] . '/*/migrations');

			if ($package_dirs === false) {
				continue;
			}

			sort($package_dirs);

			foreach ($package_dirs as $dir) {
				$type = (string) $package_root['type'];
				$id = basename((string) dirname($dir));

				if ($type === 'core' && $id === 'framework') {
					continue;
				}

				$module = PackageModuleHelper::buildModule($type, $id);

				if (isset($seen_modules[$module])) {
					continue;
				}

				$seen_modules[$module] = true;
				$dirs[] = [
					'module' => $module,
					'path' => $dir,
				];
			}
		}

		return $dirs;
	}

	public static function writeMigrationsFile(): void
	{
		$applied = self::getAppliedMigrations();

		$content = "<?php\n\n";
		$content .= "/**\n";
		$content .= " * Applied database migrations.\n";
		$content .= " *\n";
		$content .= " * This file is automatically generated by MigrationRunner.\n";
		$content .= " * Do not edit manually.\n";
		$content .= " *\n";
		$content .= " * Generated: " . date('Y-m-d H:i:s') . "\n";
		$content .= " */\n\n";
		$content .= "return [\n";

		foreach ($applied as $info) {
			$content .= "\t// {$info['module']} / {$info['migration_name']}\n";
			$content .= "\t'{$info['migration_hash']}' => [\n";
			$content .= "\t\t'module' => '{$info['module']}',\n";
			$content .= "\t\t'name' => '{$info['migration_name']}',\n";
			$content .= "\t\t'applied_at' => '{$info['applied_at']}',\n";
			$content .= "\t],\n";
		}

		$content .= "];\n";

		file_put_contents(self::getMigrationsFilePath(), $content, LOCK_EX);
	}

	public static function rebuildDbSchema(): void
	{
		// Fresh migration runs leave the test schema behind until it is synchronized.
		// Rebuild the cache with the same auto-sync semantics as `radaptor build:db --auto-sync`
		// so install/update/migrate flows remain non-interactive.
		TestDatabaseSchemaSyncService::sync(false);
		CLICommandBuildDb::create([
			Config::DB_DEFAULT_DSN->value(),
			Db::rewriteDsnToTesting(Config::DB_DEFAULT_DSN->value()),
		]);
	}

	/**
	 * @return array<int, array{
	 *     filename: string,
	 *     module: string,
	 *     status: string,
	 *     applied_at: string|null
	 * }>
	 */
	public static function getStatus(): array
	{
		$all_files = self::getAllMigrationFiles();
		$applied = self::getAppliedMigrations();
		$status = [];

		foreach ($all_files as $migration) {
			$applied_info = $applied[$migration['key']] ?? null;

			$status[] = [
				'filename' => $migration['filename'],
				'module' => $migration['module'],
				'status' => $applied_info !== null ? 'applied' : 'pending',
				'applied_at' => $applied_info['applied_at'] ?? null,
			];
		}

		return $status;
	}

	/**
	 * @param array<int, array{
	 *     key: string,
	 *     module: string,
	 *     filename: string,
	 *     filepath: string,
	 *     hash: string,
	 *     base_class_name: string,
	 *     runtime_class_name: string
	 * }> $pending
	 */
	private static function assertDatabaseReadyForPendingMigrations(array $pending): void
	{
		if ($pending === []) {
			return;
		}

		$pdo = Db::instance();
		$database = self::getCurrentDatabaseName($pdo);
		$tables = self::getBaseTables($pdo, $database);
		$app_tables = array_values(array_filter(
			$tables,
			static fn (string $table): bool => !in_array($table, [
				'migrations',
				'runtime_site_locks',
				'runtime_worker_instances',
				'runtime_worker_pause_requests',
			], true)
		));

		if ($app_tables !== []) {
			return;
		}

		throw new RuntimeException(
			"Database schema is not initialized: '{$database}' contains no application tables outside the migrations metadata table. "
			. 'Load the bootstrap schema or restore a site snapshot before running migrations. Refusing to mark migrations as applied against an empty schema.'
		);
	}

	/**
	 * @param array{
	 *     module?: string,
	 *     filename?: string
	 * }|null $migration
	 * @return array{success: bool, message: string, module: string, filename: string}
	 */
	private static function buildPreflightFailure(string $message, ?array $migration = null): array
	{
		return [
			'success' => false,
			'message' => 'Migration preflight failed: ' . $message,
			'module' => (string) ($migration['module'] ?? self::PREFLIGHT_MODULE),
			'filename' => (string) ($migration['filename'] ?? self::PREFLIGHT_FILENAME),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $results
	 */
	private static function allMigrationResultsSucceeded(array $results): bool
	{
		foreach ($results as $result) {
			if (($result['success'] ?? false) !== true) {
				return false;
			}
		}

		return true;
	}

	private static function getCurrentDatabaseName(PDO $pdo): string
	{
		$stmt = $pdo->query('SELECT DATABASE()');
		$database = $stmt instanceof PDOStatement ? $stmt->fetchColumn() : false;

		if (!is_string($database) || $database === '') {
			throw new RuntimeException('Unable to determine current database name.');
		}

		return $database;
	}

	/**
	 * @return list<string>
	 */
	private static function getBaseTables(PDO $pdo, string $database): array
	{
		$stmt = $pdo->prepare(
			"SELECT TABLE_NAME
			 FROM INFORMATION_SCHEMA.TABLES
			 WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
			 ORDER BY TABLE_NAME"
		);
		$stmt->execute([$database]);

		return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
	}

	private static function buildSandboxDatabaseName(string $source_database): string
	{
		$suffix = bin2hex(random_bytes(4));
		$name = $source_database . '_migration_sandbox_' . getmypid() . '_' . $suffix;

		return substr($name, 0, 64);
	}

	private static function rewriteDsnDatabaseName(string $dsn, string $database): string
	{
		$parts = explode(';', $dsn);
		$rewritten = false;

		foreach ($parts as &$part) {
			if (str_starts_with($part, 'dbname=')) {
				$part = 'dbname=' . $database;
				$rewritten = true;

				break;
			}
		}
		unset($part);

		if (!$rewritten) {
			throw new RuntimeException('Cannot build migration sandbox DSN because DB_DEFAULT_DSN does not contain dbname.');
		}

		return implode(';', $parts);
	}

	private static function cloneDatabaseForMigrationSandbox(
		PDO $source_pdo,
		string $source_database,
		string $sandbox_database,
		string $sandbox_dsn
	): void {
		$tables = self::getBaseTables($source_pdo, $source_database);
		$create_order = self::sortTablesByForeignKeyDependencies($source_pdo, $source_database, $tables);
		$sandbox_pdo = self::createRawPdoConnection($sandbox_dsn);
		$sandbox_pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

		try {
			foreach ($create_order as $table) {
				$sandbox_pdo->exec(self::getCreateTableForSandbox($source_pdo, $table));
			}

			foreach ($tables as $table) {
				$quoted_table = self::quoteIdentifier($table);
				$source = self::quoteIdentifier($source_database) . '.' . $quoted_table;
				$target = self::quoteIdentifier($sandbox_database) . '.' . $quoted_table;
				$sandbox_pdo->exec("INSERT INTO {$target} SELECT * FROM {$source}");
			}
		} finally {
			$sandbox_pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
		}
	}

	private static function getCreateTableForSandbox(PDO $source_pdo, string $table): string
	{
		$stmt = $source_pdo->query('SHOW CREATE TABLE ' . self::quoteIdentifier($table));
		$row = $stmt instanceof PDOStatement ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
		$create = is_array($row) ? (string) ($row['Create Table'] ?? '') : '';

		if ($create === '') {
			throw new RuntimeException("Unable to read CREATE TABLE for {$table}.");
		}

		return preg_replace(
			'/^CREATE TABLE `?' . preg_quote($table, '/') . '`?/i',
			'CREATE TABLE ' . self::quoteIdentifier($table),
			$create,
			1
		) ?? $create;
	}

	/**
	 * @param list<string> $tables
	 * @return list<string>
	 */
	private static function sortTablesByForeignKeyDependencies(PDO $pdo, string $database, array $tables): array
	{
		$table_set = array_fill_keys($tables, true);
		$dependencies = array_fill_keys($tables, []);
		$stmt = $pdo->prepare(
			"SELECT TABLE_NAME, REFERENCED_TABLE_NAME
			 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
			 WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL"
		);
		$stmt->execute([$database]);

		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$table = (string) ($row['TABLE_NAME'] ?? '');
			$referenced_table = (string) ($row['REFERENCED_TABLE_NAME'] ?? '');

			if ($table === '' || $referenced_table === '' || $table === $referenced_table) {
				continue;
			}

			if (isset($table_set[$table], $table_set[$referenced_table])) {
				$dependencies[$table][] = $referenced_table;
			}
		}

		$sorted = [];
		$visited = [];
		$visiting = [];

		foreach ($tables as $table) {
			self::visitTableDependency($table, $dependencies, $visited, $visiting, $sorted);
		}

		return array_values(array_unique($sorted));
	}

	/**
	 * @param array<string, list<string>> $dependencies
	 * @param array<string, bool> $visited
	 * @param array<string, bool> $visiting
	 * @param list<string> $sorted
	 */
	private static function visitTableDependency(
		string $table,
		array $dependencies,
		array &$visited,
		array &$visiting,
		array &$sorted
	): void {
		if (isset($visited[$table])) {
			return;
		}

		if (isset($visiting[$table])) {
			$sorted[] = $table;
			$visited[$table] = true;

			return;
		}

		$visiting[$table] = true;

		foreach ($dependencies[$table] ?? [] as $dependency) {
			self::visitTableDependency($dependency, $dependencies, $visited, $visiting, $sorted);
		}

		unset($visiting[$table]);
		$visited[$table] = true;
		$sorted[] = $table;
	}

	private static function setDefaultDsnForCurrentProcess(string $dsn): void
	{
		putenv('DB_DEFAULT_DSN=' . $dsn);
		$_ENV['DB_DEFAULT_DSN'] = $dsn;
		$_SERVER['DB_DEFAULT_DSN'] = $dsn;
	}

	private static function restoreDefaultDsnForCurrentProcess(string|false $value, bool $exists): void
	{
		if ($exists) {
			self::setDefaultDsnForCurrentProcess((string) $value);

			return;
		}

		putenv('DB_DEFAULT_DSN');
		unset($_ENV['DB_DEFAULT_DSN'], $_SERVER['DB_DEFAULT_DSN']);
	}

	private static function quoteIdentifier(string $identifier): string
	{
		return '`' . str_replace('`', '``', $identifier) . '`';
	}

	private static function createRawPdoConnection(string $dsn): PDO
	{
		$pdo = new PDO($dsn);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
		$pdo->exec('SET NAMES utf8mb4');
		$pdo->exec("SET time_zone = '+00:00'");

		return $pdo;
	}

	/**
	 * @param array{
	 *     key: string,
	 *     module: string,
	 *     filename: string,
	 *     filepath: string,
	 *     hash: string,
	 *     base_class_name: string,
	 *     runtime_class_name: string
	 * } $migration
	 */
	private static function loadMigrationClass(array $migration): string
	{
		if (class_exists($migration['runtime_class_name'], false)) {
			return $migration['runtime_class_name'];
		}

		$code = file_get_contents($migration['filepath']);

		if ($code === false) {
			throw new RuntimeException("Unable to read migration file: {$migration['filepath']}");
		}

		$code = preg_replace('/^\s*<\?php\b/', '', $code, 1) ?? $code;
		$pattern = '/\bclass\s+' . preg_quote($migration['base_class_name'], '/') . '\b/';
		$replacement_count = 0;
		$rewritten = preg_replace(
			$pattern,
			'class ' . $migration['runtime_class_name'],
			$code,
			1,
			$replacement_count
		);

		if ($rewritten === null || $replacement_count !== 1) {
			throw new RuntimeException("Migration class not found: {$migration['base_class_name']}");
		}

		try {
			eval($rewritten);
		} catch (ParseError $e) {
			throw new RuntimeException("Migration parse failed: " . $e->getMessage(), 0, $e);
		}

		return $migration['runtime_class_name'];
	}

	private static function captureBufferedOutput(callable $callback): string
	{
		$initial_level = ob_get_level();
		ob_start();

		try {
			$callback();

			return (string) ob_get_contents();
		} finally {
			while (ob_get_level() > $initial_level) {
				ob_end_clean();
			}
		}
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
	private static function buildMigrationDescriptor(string $module, string $filepath): array
	{
		$filename = basename($filepath);
		$base_class_name = 'Migration_' . pathinfo($filename, PATHINFO_FILENAME);

		return [
			'key' => self::buildMigrationKey($module, $filename),
			'module' => $module,
			'filename' => $filename,
			'filepath' => $filepath,
			'hash' => self::buildMigrationHash($module, $filename),
			'base_class_name' => $base_class_name,
			'runtime_class_name' => self::buildRuntimeClassName($module, $filename),
		];
	}

	private static function buildMigrationKey(string $module, string $filename): string
	{
		return $module . ':' . $filename;
	}

	private static function buildMigrationHash(string $module, string $filename): string
	{
		return md5(self::buildMigrationKey($module, $filename));
	}

	private static function buildRuntimeClassName(string $module, string $filename): string
	{
		$module_part = preg_replace('/[^A-Za-z0-9_]/', '_', $module) ?? $module;
		$filename_part = pathinfo($filename, PATHINFO_FILENAME);

		return 'RuntimeMigration_' . $module_part . '_' . $filename_part;
	}

	/**
	 * @param array{
	 *     key: string,
	 *     module: string,
	 *     filename: string,
	 *     filepath: string,
	 *     hash: string,
	 *     base_class_name: string,
	 *     runtime_class_name: string
	 * } $a
	 * @param array{
	 *     key: string,
	 *     module: string,
	 *     filename: string,
	 *     filepath: string,
	 *     hash: string,
	 *     base_class_name: string,
	 *     runtime_class_name: string
	 * } $b
	 */
	private static function compareMigrations(array $a, array $b, array $installed_module_order): int
	{
		$timestamp_compare = strcmp(self::extractTimestampPrefix($a['filename']), self::extractTimestampPrefix($b['filename']));

		if ($timestamp_compare !== 0) {
			return $timestamp_compare;
		}

		$module_compare = self::compareModuleOrder($a['module'], $b['module'], $installed_module_order);

		if ($module_compare !== 0) {
			return $module_compare;
		}

		return strcmp($a['filename'], $b['filename']);
	}

	private static function compareModuleOrder(string $a, string $b, array $installed_module_order): int
	{
		$order_a = $installed_module_order[$a] ?? null;
		$order_b = $installed_module_order[$b] ?? null;

		if (is_int($order_a) && is_int($order_b) && $order_a !== $order_b) {
			return $order_a <=> $order_b;
		}

		$tier_a = self::getModuleTier($a);
		$tier_b = self::getModuleTier($b);

		if ($tier_a !== $tier_b) {
			return $tier_a <=> $tier_b;
		}

		if ($tier_a === 1) {
			return strcmp($a, $b);
		}

		return 0;
	}

	private static function getModuleTier(string $module): int
	{
		if ($module === 'framework') {
			return 0;
		}

		if (str_starts_with($module, 'core:')) {
			return 1;
		}

		if (str_starts_with($module, 'theme:')) {
			return 2;
		}

		if ($module === 'app') {
			return 3;
		}

		return 4;
	}

	private static function extractTimestampPrefix(string $filename): string
	{
		return substr($filename, 0, 15);
	}

	private static function detectModuleFromFilepath(string $filepath): string
	{
		$normalized = str_replace('\\', '/', $filepath);
		$framework_root = PackagePathHelper::getFrameworkRoot();

		if (is_string($framework_root)) {
			$framework_migrations = rtrim(str_replace('\\', '/', $framework_root), '/') . '/migrations/';

			if (str_starts_with($normalized, $framework_migrations)) {
				return 'framework';
			}
		}

		if (is_file(PackageLockfile::getPath())) {
			$lock = PackageLockfile::load();

			foreach ($lock['packages'] as $package) {
				$type = PackageTypeHelper::normalizeType($package['type'] ?? null, 'Migration package');
				$id = PackageTypeHelper::normalizeId($package['id'] ?? null, 'Migration package');
				$root = PackagePathHelper::getPackageRoot($type, $id);

				if (!is_string($root)) {
					continue;
				}

				$migration_root = rtrim(str_replace('\\', '/', $root), '/') . '/migrations/';

				if (str_starts_with($normalized, $migration_root)) {
					return PackageModuleHelper::buildModule($type, $id);
				}
			}
		}

		if (preg_match('#/packages/(?:dev|registry)/core/([^/]+)/migrations/#', $normalized, $matches) === 1) {
			return PackageModuleHelper::buildModule('core', $matches[1]);
		}

		if (preg_match('#/packages/(?:dev|registry)/themes/([^/]+)/migrations/#', $normalized, $matches) === 1) {
			return PackageModuleHelper::buildModule('theme', $matches[1]);
		}

		if (str_contains($normalized, '/app/migrations/')) {
			return 'app';
		}

		return 'framework';
	}

	private static function tableColumnExists(string $table, string $column): bool
	{
		$stmt = Db::instance()->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");

		return $stmt !== false && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
	}

	private static function tableExists(string $table): bool
	{
		$quoted_table = Db::instance()->quote($table);
		$stmt = Db::instance()->query("SHOW TABLES LIKE {$quoted_table}");

		return $stmt !== false && $stmt->fetch(PDO::FETCH_NUM) !== false;
	}

	private static function tableIndexExists(string $table, string $index_name): bool
	{
		$stmt = Db::instance()->query("SHOW INDEX FROM {$table} WHERE Key_name = '{$index_name}'");

		return $stmt !== false && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
	}

	private static function migrationHashExists(string $migration_hash): bool
	{
		$stmt = Db::instance()->prepare("SELECT migration_hash FROM migrations WHERE migration_hash = ? LIMIT 1");
		$stmt->execute([$migration_hash]);

		return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
	}

	private static function normalizeLegacyAppliedMigrations(): void
	{
		$stmt = Db::instance()->prepare("SELECT migration_hash, migration_name, module FROM migrations ORDER BY applied_at, migration_name");
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		if (empty($rows)) {
			return;
		}

		$known_modules_by_filename = self::getKnownModulesByFilename();

		foreach ($rows as $row) {
			$filename = (string) $row['migration_name'];
			$current_module = (string) ($row['module'] ?? '');
			$target_module = self::resolveAppliedMigrationModule($filename, $current_module, $known_modules_by_filename);
			$target_hash = self::buildMigrationHash($target_module, $filename);
			$current_hash = (string) $row['migration_hash'];

			if ($current_module === $target_module && $current_hash === $target_hash) {
				continue;
			}

			if ($current_hash !== $target_hash && self::migrationHashExists($target_hash)) {
				$delete = Db::instance()->prepare("DELETE FROM migrations WHERE migration_hash = ?");
				$delete->execute([$current_hash]);

				continue;
			}

			$update = Db::instance()->prepare(
				"UPDATE migrations
				 SET module = ?, migration_hash = ?
				 WHERE migration_hash = ?"
			);
			$update->execute([$target_module, $target_hash, $current_hash]);
		}
	}

	/**
	 * @return array<string, string[]>
	 */
	private static function getKnownModulesByFilename(): array
	{
		$modules = [];

		foreach (self::getAllMigrationFiles() as $migration) {
			$modules[$migration['filename']][] = $migration['module'];
		}

		foreach ($modules as $filename => $known_modules) {
			$known_modules = array_values(array_unique($known_modules));
			sort($known_modules);
			$modules[$filename] = $known_modules;
		}

		return $modules;
	}

	/**
	 * @param array<string, string[]> $known_modules_by_filename
	 */
	private static function resolveAppliedMigrationModule(
		string $filename,
		string $current_module,
		array $known_modules_by_filename
	): string {
		$current_module = trim($current_module);
		$known_modules = $known_modules_by_filename[$filename] ?? [];

		if (count($known_modules) === 1) {
			return $known_modules[0];
		}

		if ($current_module !== '' && in_array($current_module, $known_modules, true)) {
			return $current_module;
		}

		if ($current_module !== '' && $current_module !== 'framework') {
			return $current_module;
		}

		if (in_array('framework', $known_modules, true)) {
			return 'framework';
		}

		if (in_array('app', $known_modules, true)) {
			return 'app';
		}

		if ($current_module !== '') {
			return $current_module;
		}

		return 'framework';
	}

	/**
	 * @return array<string, int>
	 */
	private static function getInstalledModuleOrderMap(): array
	{
		$order = [
			'framework' => 0,
			'app' => PHP_INT_MAX,
		];
		$lock_path = PackageLockfile::getPath();

		if (!file_exists($lock_path)) {
			return $order;
		}

		try {
			$lock = PackageLockfile::loadFromPath($lock_path);
			$ordered_package_keys = PackageDependencyHelper::sortPackageKeysByDependencies($lock['packages']);
		} catch (Throwable) {
			return $order;
		}

		$position = 100;

		foreach ($ordered_package_keys as $package_key) {
			$module = PackageModuleHelper::buildModuleFromPackageKey($package_key);
			$order[$module] = $position;
			$position++;
		}

		return $order;
	}
}
