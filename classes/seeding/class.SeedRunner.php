<?php

class SeedRunner
{
	/**
	 * @return array<string, mixed>
	 */
	public static function run(
		bool $include_demo_seeds = false,
		bool $rerun_demo_seeds = false,
		bool $skip_seeds = false,
		bool $dry_run = false,
		?callable $confirm_demo_rerun = null
	): array {
		return self::runPaths(
			PackageLockfile::getPath(),
			DEPLOY_ROOT . 'app',
			$include_demo_seeds,
			$rerun_demo_seeds,
			$skip_seeds,
			$dry_run,
			$confirm_demo_rerun
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function status(bool $include_demo_seeds = false): array
	{
		return self::statusPaths(
			PackageLockfile::getPath(),
			DEPLOY_ROOT . 'app',
			$include_demo_seeds
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function statusPaths(
		string $package_lock_path,
		string $app_path,
		bool $include_demo_seeds = false
	): array {
		$seeds = self::discoverSeeds($package_lock_path, $app_path, $include_demo_seeds);
		$applied = self::hasSeedsTable() ? self::getAppliedSeeds() : [];
		$seeds = self::attachStatuses($seeds, $applied);

		return [
			'include_demo_seeds' => $include_demo_seeds,
			'seeds_total' => count($seeds),
			'mandatory_total' => count(array_filter($seeds, static fn (array $seed): bool => $seed['kind'] === 'mandatory')),
			'demo_total' => count(array_filter($seeds, static fn (array $seed): bool => $seed['kind'] === 'demo')),
			'status_counts' => self::buildStatusCounts($seeds),
			'seeds' => $seeds,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function runPaths(
		string $package_lock_path,
		string $app_path,
		bool $include_demo_seeds = false,
		bool $rerun_demo_seeds = false,
		bool $skip_seeds = false,
		bool $dry_run = false,
		?callable $confirm_demo_rerun = null
	): array {
		if ($skip_seeds) {
			return [
				'status' => 'skipped',
				'dry_run' => $dry_run,
				'include_demo_seeds' => $include_demo_seeds,
				'rerun_demo_seeds' => $rerun_demo_seeds,
				'skip_seeds' => true,
				'prompted_demo_rerun' => false,
				'demo_rerun_confirmed' => null,
				'seeds_processed' => 0,
				'seeds_executed' => 0,
				'seeds_skipped' => 0,
				'has_errors' => false,
				'message' => 'Seed execution skipped by flag.',
				'seeds' => [],
			];
		}

		if (!$dry_run) {
			self::assertSeedRunReady($package_lock_path, $app_path);
		}

		$seeds = self::discoverSeeds($package_lock_path, $app_path, $include_demo_seeds);
		$applied = self::hasSeedsTable() ? self::getAppliedSeeds() : [];
		$seeds = self::attachStatuses($seeds, $applied);
		$selected_seeds = array_values(array_filter(
			$seeds,
			static fn (array $seed): bool => $seed['kind'] === 'mandatory' || $include_demo_seeds
		));
		$selected_seeds = self::sortSeedsByDependencies($selected_seeds);
		$prompted_demo_rerun = false;
		$demo_rerun_confirmed = null;
		$existing_demo_seeds = array_values(array_filter(
			$selected_seeds,
			static fn (array $seed): bool => $seed['kind'] === 'demo' && in_array($seed['status'], ['applied', 'changed'], true)
		));

		if ($existing_demo_seeds !== [] && !$rerun_demo_seeds) {
			$prompted_demo_rerun = $confirm_demo_rerun !== null;
			$demo_rerun_confirmed = $confirm_demo_rerun !== null
				? (bool) $confirm_demo_rerun($existing_demo_seeds)
				: false;

			if ($demo_rerun_confirmed !== true) {
				return [
					'status' => 'aborted',
					'dry_run' => $dry_run,
					'include_demo_seeds' => $include_demo_seeds,
					'rerun_demo_seeds' => false,
					'skip_seeds' => false,
					'prompted_demo_rerun' => $prompted_demo_rerun,
					'demo_rerun_confirmed' => $demo_rerun_confirmed,
					'seeds_processed' => count($selected_seeds),
					'seeds_executed' => 0,
					'seeds_skipped' => count($selected_seeds),
					'has_errors' => true,
					'message' => 'Demo seeds already ran before. Rerun confirmation is required.',
					'seeds' => $selected_seeds,
				];
			}

			$rerun_demo_seeds = true;
		}

		$seeds_executed = 0;
		$seeds_skipped = 0;
		$results = [];

		foreach ($selected_seeds as $seed) {
			$should_run = self::shouldRunSeed($seed, $rerun_demo_seeds);

			if (!$should_run) {
				$seed['run_status'] = 'skipped';
				$results[] = $seed;
				$seeds_skipped++;

				continue;
			}

			$execution = self::executeSeed($seed, $dry_run);
			$results[] = [...$seed, ...$execution];

			if (($execution['success'] ?? false) === true) {
				$seeds_executed++;

				continue;
			}

			return [
				'status' => 'error',
				'dry_run' => $dry_run,
				'include_demo_seeds' => $include_demo_seeds,
				'rerun_demo_seeds' => $rerun_demo_seeds,
				'skip_seeds' => false,
				'prompted_demo_rerun' => $prompted_demo_rerun,
				'demo_rerun_confirmed' => $demo_rerun_confirmed,
				'seeds_processed' => count($selected_seeds),
				'seeds_executed' => $seeds_executed,
				'seeds_skipped' => $seeds_skipped,
				'has_errors' => true,
				'message' => (string) ($execution['message'] ?? 'Seed execution failed.'),
				'seeds' => $results,
			];
		}

		return [
			'status' => 'success',
			'dry_run' => $dry_run,
			'include_demo_seeds' => $include_demo_seeds,
			'rerun_demo_seeds' => $rerun_demo_seeds,
			'skip_seeds' => false,
			'prompted_demo_rerun' => $prompted_demo_rerun,
			'demo_rerun_confirmed' => $demo_rerun_confirmed,
			'seeds_processed' => count($selected_seeds),
			'seeds_executed' => $seeds_executed,
			'seeds_skipped' => $seeds_skipped,
			'has_errors' => false,
			'message' => null,
			'seeds' => $results,
		];
	}

	public static function ensureSeedsTable(): void
	{
		self::assertSeedsTableExists();
	}

	private static function assertSeedRunReady(string $package_lock_path, string $app_path): void
	{
		self::assertSeedsTableExists();

		$pending_migrations = MigrationRunner::getPendingMigrationsForModules(
			self::buildMigrationModules($package_lock_path, $app_path)
		);

		if ($pending_migrations !== []) {
			throw new RuntimeException(
				'Pending migrations must be applied before running seeds. Run \'radaptor migrate:run\' or \'radaptor install\'.'
			);
		}
	}

	private static function assertSeedsTableExists(): void
	{
		if (!self::hasSeedsTable()) {
			throw new RuntimeException(
				'Seed tracking table is missing. Run \'radaptor migrate:run\' or \'radaptor install\' first.'
			);
		}
	}

	private static function hasSeedsTable(): bool
	{
		$stmt = Db::instance()->query("SHOW TABLES LIKE 'seeds'");

		return $stmt !== false && $stmt->fetchColumn() !== false;
	}

	/**
	 * @return array<string, array{module: string, seed_class: string, kind: string, version: string, applied_at: string}>
	 */
	private static function getAppliedSeeds(): array
	{
		$stmt = Db::instance()->query(
			"SELECT module, seed_class, kind, version, applied_at
			 FROM seeds
			 ORDER BY module, seed_class"
		);

		if ($stmt === false) {
			return [];
		}

		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$applied = [];

		foreach ($rows as $row) {
			$key = self::buildSeedKey((string) $row['module'], (string) $row['seed_class']);
			$applied[$key] = [
				'module' => (string) $row['module'],
				'seed_class' => (string) $row['seed_class'],
				'kind' => (string) $row['kind'],
				'version' => (string) $row['version'],
				'applied_at' => (string) $row['applied_at'],
			];
		}

		return $applied;
	}

	/**
	 * @param array<string, array{module: string, seed_class: string, kind: string, version: string, applied_at: string}> $applied
	 * @param list<array<string, mixed>> $seeds
	 * @return list<array<string, mixed>>
	 */
	private static function attachStatuses(array $seeds, array $applied): array
	{
		$with_status = [];

		foreach ($seeds as $seed) {
			$key = self::buildSeedKey($seed['module'], $seed['class']);
			$applied_seed = $applied[$key] ?? null;
			$status = 'pending';

			if ($applied_seed !== null) {
				$status = $applied_seed['version'] === $seed['version']
					? 'applied'
					: 'changed';
			}

			$seed['status'] = $status;
			$seed['applied_version'] = $applied_seed['version'] ?? null;
			$seed['applied_at'] = $applied_seed['applied_at'] ?? null;
			$with_status[] = $seed;
		}

		return $with_status;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function discoverSeeds(string $package_lock_path, string $app_path, bool $include_demo_seeds): array
	{
		$owners = self::discoverOwners($package_lock_path, $app_path);
		$kinds = $include_demo_seeds ? ['mandatory', 'demo'] : ['mandatory'];
		$seeds = [];

		foreach ($owners as $owner) {
			foreach ($kinds as $kind) {
				$seed_dir = rtrim($owner['base_path'], '/') . '/seeds/' . $kind;
				$seed_files = glob($seed_dir . '/Seed.*.php') ?: [];
				sort($seed_files);

				foreach ($seed_files as $seed_file) {
					$seeds[] = self::loadSeedDescriptor($owner['module'], $owner['base_path'], $kind, $seed_file);
				}
			}
		}

		return $seeds;
	}

	/**
	 * @return list<array{module: string, base_path: string}>
	 */
	private static function discoverOwners(string $package_lock_path, string $app_path): array
	{
		$owners = [];

		if (file_exists($package_lock_path)) {
			$lock = PackageLockfile::loadFromPath($package_lock_path);
			$ordered_package_keys = PackageDependencyHelper::sortPackageKeysByDependencies($lock['packages']);

			foreach ($ordered_package_keys as $package_key) {
				$package = $lock['packages'][$package_key];
				$base_path = self::resolvePackageBasePath($package);

				if ($base_path === null || !is_dir($base_path)) {
					continue;
				}

				$owners[] = [
					'module' => PackageModuleHelper::buildModuleFromPackageKey($package_key),
					'base_path' => $base_path,
				];
			}
		}

		if (is_dir($app_path)) {
			$owners[] = [
				'module' => 'app',
				'base_path' => rtrim($app_path, '/'),
			];
		}

		return $owners;
	}

	/**
	 * @return list<string>
	 */
	private static function buildMigrationModules(string $package_lock_path, string $app_path): array
	{
		$modules = ['framework'];

		if (file_exists($package_lock_path)) {
			$lock = PackageLockfile::loadFromPath($package_lock_path);
			$ordered_package_keys = PackageDependencyHelper::sortPackageKeysByDependencies($lock['packages']);

			foreach ($ordered_package_keys as $package_key) {
				$modules[] = PackageModuleHelper::buildModuleFromPackageKey($package_key);
			}
		}

		if (is_dir($app_path)) {
			$modules[] = 'app';
		}

		return array_values(array_unique($modules));
	}

	/**
	 * @param array<string, mixed> $package
	 */
	private static function resolvePackageBasePath(array $package): ?string
	{
		$resolved = is_array($package['resolved'] ?? null) ? $package['resolved'] : [];
		$source = is_array($package['source'] ?? null) ? $package['source'] : [];
		$path = $resolved['resolved_path'] ?? $source['resolved_path'] ?? null;

		if (!is_string($path) || trim($path) === '') {
			$path = $resolved['path'] ?? $source['path'] ?? null;
		}

		if (!is_string($path) || trim($path) === '') {
			return null;
		}

		if (!str_starts_with($path, '/')) {
			$path = DEPLOY_ROOT . ltrim($path, '/');
		}

		$real_path = realpath($path);

		return $real_path !== false ? rtrim(str_replace('\\', '/', $real_path), '/') : rtrim(str_replace('\\', '/', $path), '/');
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function loadSeedDescriptor(string $module, string $base_path, string $kind, string $seed_file): array
	{
		$class_name = str_replace('.', '', basename($seed_file, '.php'));

		require_once $seed_file;

		if (!class_exists($class_name)) {
			throw new RuntimeException("Seed class '{$class_name}' not found in {$seed_file}");
		}

		$seed = new $class_name();

		if (!($seed instanceof AbstractSeed)) {
			throw new RuntimeException("Seed class '{$class_name}' must extend AbstractSeed.");
		}

		$version = trim($seed->getVersion());

		if ($version === '') {
			throw new RuntimeException("Seed '{$class_name}' must declare a non-empty version.");
		}

		return [
			'module' => $module,
			'kind' => $kind,
			'class' => $class_name,
			'path' => $seed_file,
			'base_path' => $base_path,
			'version' => $version,
			'description' => trim($seed->getDescription()),
			'dependencies' => $seed->getDependencies(),
		];
	}

	/**
	 * @param list<array<string, mixed>> $seeds
	 * @return list<array<string, mixed>>
	 */
	private static function sortSeedsByDependencies(array $seeds): array
	{
		$seed_map = [];
		$incoming = [];
		$adjacency = [];
		$order_map = [];

		foreach ($seeds as $index => $seed) {
			$class_name = (string) $seed['class'];
			$seed_map[$class_name] = $seed;
			$incoming[$class_name] = 0;
			$adjacency[$class_name] = [];
			$order_map[$class_name] = $index;
		}

		foreach ($seeds as $seed) {
			$class_name = (string) $seed['class'];
			$dependencies = array_values(array_filter(array_map(
				static fn (mixed $dependency): string => trim((string) $dependency),
				is_array($seed['dependencies']) ? $seed['dependencies'] : []
			), static fn (string $dependency): bool => $dependency !== ''));
			sort($dependencies);

			foreach ($dependencies as $dependency_class) {
				if (!isset($seed_map[$dependency_class])) {
					throw new RuntimeException("Seed '{$class_name}' depends on missing seed '{$dependency_class}'.");
				}

				if ($dependency_class === $class_name) {
					throw new RuntimeException("Circular seed dependency detected involving {$class_name}.");
				}

				if (isset($adjacency[$dependency_class][$class_name])) {
					continue;
				}

				$adjacency[$dependency_class][$class_name] = true;
				$incoming[$class_name]++;
			}
		}

		$queue = array_keys(array_filter($incoming, static fn (int $count): bool => $count === 0));
		usort($queue, static function (string $a, string $b) use ($order_map): int {
			$order_compare = ($order_map[$a] ?? PHP_INT_MAX) <=> ($order_map[$b] ?? PHP_INT_MAX);

			return $order_compare !== 0 ? $order_compare : strcmp($a, $b);
		});

		$ordered_classes = [];

		while ($queue !== []) {
			$class_name = array_shift($queue);
			$ordered_classes[] = $class_name;
			$dependants = array_keys($adjacency[$class_name]);
			usort($dependants, static function (string $a, string $b) use ($order_map): int {
				$order_compare = ($order_map[$a] ?? PHP_INT_MAX) <=> ($order_map[$b] ?? PHP_INT_MAX);

				return $order_compare !== 0 ? $order_compare : strcmp($a, $b);
			});

			foreach ($dependants as $dependant_class) {
				$incoming[$dependant_class]--;

				if ($incoming[$dependant_class] === 0) {
					$queue[] = $dependant_class;
				}
			}

			usort($queue, static function (string $a, string $b) use ($order_map): int {
				$order_compare = ($order_map[$a] ?? PHP_INT_MAX) <=> ($order_map[$b] ?? PHP_INT_MAX);

				return $order_compare !== 0 ? $order_compare : strcmp($a, $b);
			});
		}

		if (count($ordered_classes) !== count($seed_map)) {
			$cycle_classes = [];

			foreach ($incoming as $class_name => $count) {
				if ($count > 0) {
					$cycle_classes[] = $class_name;
				}
			}

			sort($cycle_classes);

			throw new RuntimeException('Circular seed dependency detected: ' . implode(', ', $cycle_classes));
		}

		$sorted = [];

		foreach ($ordered_classes as $class_name) {
			$sorted[] = $seed_map[$class_name];
		}

		return $sorted;
	}

	/**
	 * @param array<string, mixed> $seed
	 * @return array<string, mixed>
	 */
	private static function executeSeed(array $seed, bool $dry_run): array
	{
		require_once $seed['path'];
		$class_name = $seed['class'];
		$seed_instance = new $class_name();

		if (!($seed_instance instanceof AbstractSeed)) {
			return [
				'success' => false,
				'run_status' => 'error',
				'message' => "Seed '{$class_name}' is not a valid AbstractSeed.",
			];
		}

		$context = new SeedContext(
			$seed['module'],
			$seed['kind'],
			$seed['base_path'],
			$dry_run
		);
		$pdo = Db::instance();
		$started_transaction = !$pdo->inTransaction();

		try {
			if ($started_transaction) {
				$pdo->beginTransaction();
			}

			$seed_instance->run($context);

			if ($dry_run) {
				if ($started_transaction && $pdo->inTransaction()) {
					$pdo->rollBack();
				}
			} else {
				self::upsertAppliedSeed($seed);

				if ($started_transaction && $pdo->inTransaction()) {
					$pdo->commit();
				}
			}

			return [
				'success' => true,
				'run_status' => $dry_run ? 'dry_run' : 'executed',
				'message' => null,
			];
		} catch (Throwable $exception) {
			if ($started_transaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}

			return [
				'success' => false,
				'run_status' => 'error',
				'message' => $exception->getMessage(),
			];
		}
	}

	/**
	 * @param array<string, mixed> $seed
	 */
	private static function upsertAppliedSeed(array $seed): void
	{
		$stmt = Db::instance()->prepare(
			"INSERT INTO seeds (module, seed_class, kind, version, applied_at)
			 VALUES (?, ?, ?, ?, ?)
			 ON DUPLICATE KEY UPDATE
			 kind = VALUES(kind),
			 version = VALUES(version),
			 applied_at = VALUES(applied_at)"
		);
		$stmt->execute([
			$seed['module'],
			$seed['class'],
			$seed['kind'],
			$seed['version'],
			date('Y-m-d H:i:s'),
		]);
	}

	/**
	 * @param array<string, mixed> $seed
	 */
	private static function shouldRunSeed(array $seed, bool $rerun_demo_seeds): bool
	{
		if ($seed['kind'] === 'mandatory') {
			return in_array($seed['status'], ['pending', 'changed'], true);
		}

		if ($seed['kind'] === 'demo') {
			return $rerun_demo_seeds || $seed['status'] === 'pending';
		}

		return false;
	}

	private static function buildSeedKey(string $module, string $seed_class): string
	{
		return $module . ':' . $seed_class;
	}

	/**
	 * @param list<array<string, mixed>> $seeds
	 * @return array<string, int>
	 */
	private static function buildStatusCounts(array $seeds): array
	{
		$counts = [
			'pending' => 0,
			'applied' => 0,
			'changed' => 0,
		];

		foreach ($seeds as $seed) {
			$status = $seed['status'] ?? null;

			if (is_string($status) && isset($counts[$status])) {
				$counts[$status]++;
			}
		}

		return $counts;
	}
}
