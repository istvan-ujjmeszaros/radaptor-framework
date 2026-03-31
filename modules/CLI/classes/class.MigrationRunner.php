<?php

/**
 * Handles database migrations for the Radaptor framework.
 *
 * Migrations are discovered from deterministic package-aware sources:
 * - framework migrations
 * - installed core/theme/plugin package migrations
 * - app migrations
 */
class MigrationRunner
{
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

		$stmt = Db::instance()->prepare(
			"SELECT migration_hash, module, migration_name, applied_at
			 FROM migrations
			 ORDER BY applied_at, module, migration_name"
		);
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$applied = [];

		foreach ($rows as $row) {
			$module = (string) ($row['module'] ?? 'framework');
			$filename = (string) $row['migration_name'];
			$key = self::buildMigrationKey($module, $filename);

			$applied[$key] = [
				'module' => $module,
				'migration_name' => $filename,
				'applied_at' => (string) $row['applied_at'],
				'migration_hash' => (string) $row['migration_hash'],
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
		$all_files = self::getAllMigrationFiles();
		$applied = self::getAppliedMigrations();

		return array_values(array_filter(
			$all_files,
			static fn (array $migration): bool => !isset($applied[$migration['key']])
		));
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
	public static function runMigration(string $filepath, ?string $module = null): array
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

		try {
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

		try {
			$migration_instance->run();
		} catch (Exception $e) {
			return [
				'success' => false,
				'message' => "Migration failed: " . $e->getMessage(),
				'module' => $migration['module'],
				'filename' => $migration['filename'],
				'description' => $description,
			];
		}

		$stmt = Db::instance()->prepare(
			"INSERT INTO migrations (migration_hash, module, migration_name, applied_at)
			 VALUES (?, ?, ?, ?)"
		);
		$stmt->execute([
			$migration['hash'],
			$migration['module'],
			$migration['filename'],
			date('Y-m-d H:i:s'),
		]);

		return [
			'success' => true,
			'message' => "Applied: {$migration['module']} / {$migration['filename']}",
			'module' => $migration['module'],
			'filename' => $migration['filename'],
			'description' => $description,
		];
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
	private static function runMigrations(array $pending, bool $rebuild_schema = true): array
	{
		$results = [];
		$any_success = false;

		foreach ($pending as $migration) {
			$result = self::runMigration($migration['filepath'], $migration['module']);
			$results[] = $result;

			if ($result['success']) {
				$any_success = true;
			}

			if (!$result['success']) {
				break;
			}
		}

		if ($any_success) {
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
			['type' => 'core', 'path' => DEPLOY_ROOT . 'core/dev'],
			['type' => 'core', 'path' => DEPLOY_ROOT . 'core/registry'],
			['type' => 'theme', 'path' => DEPLOY_ROOT . 'themes/dev'],
			['type' => 'theme', 'path' => DEPLOY_ROOT . 'themes/registry'],
			['type' => 'plugin', 'path' => DEPLOY_ROOT . 'plugins/dev'],
			['type' => 'plugin', 'path' => DEPLOY_ROOT . 'plugins/registry'],
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
		$command = new CLICommandBuildDb();
		$command->run();
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

		if (str_starts_with($module, 'plugin:')) {
			return 3;
		}

		if ($module === 'app') {
			return 4;
		}

		return 5;
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

		if (str_contains($normalized, '/radaptor/radaptor-framework/migrations/')) {
			return 'framework';
		}

		if (preg_match('#/core/(?:dev|registry)/([^/]+)/migrations/#', $normalized, $matches) === 1) {
			return PackageModuleHelper::buildModule('core', $matches[1]);
		}

		if (preg_match('#/themes/(?:dev|registry)/([^/]+)/migrations/#', $normalized, $matches) === 1) {
			return PackageModuleHelper::buildModule('theme', $matches[1]);
		}

		if (preg_match('#/plugins/(?:dev|registry)/([^/]+)/migrations/#', $normalized, $matches) === 1) {
			return PackageModuleHelper::buildModule('plugin', $matches[1]);
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

		if (
			$current_module !== ''
			&& !str_contains($current_module, ':')
			&& $current_module !== 'framework'
			&& $current_module !== 'app'
		) {
			$legacy_plugin_module = PackageModuleHelper::buildModule('plugin', $current_module);

			if (in_array($legacy_plugin_module, $known_modules, true)) {
				$current_module = $legacy_plugin_module;
			}
		}

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
