<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/class.LocaleService.php';

if (!class_exists('LocaleRegistry', false)) {
	final class LocaleMigrationSmokeLocaleRegistry
	{
		public static function getDisplayLabel(string $locale): string
		{
			return $locale;
		}

		public static function getNativeName(string $locale): string
		{
			return $locale;
		}
	}

	class_alias(LocaleMigrationSmokeLocaleRegistry::class, 'LocaleRegistry');
}

if (!class_exists('Db', false)) {
	final class LocaleMigrationSmokeDb
	{
		public static LocaleMigrationSmokePdo $pdo;

		public static function instance(string $dsn = ''): PDO
		{
			return self::$pdo;
		}
	}

	class_alias(LocaleMigrationSmokeDb::class, 'Db');
}

require_once __DIR__ . '/../migrations/20260508_090000_bcp47_locale_registry.php';

final class LocaleMigrationSmokeTest extends TestCase
{
	public function testMigrationCanonicalizesGarbageLegacyLocaleValues(): void
	{
		$garbageLocales = ['', 'C', 'posix', 'und', '  ', 'EN_US ', 'En-Us', 'éé'];
		$translationRows = [];

		foreach ($garbageLocales as $index => $locale) {
			$translationRows[] = [
				'domain' => 'messages',
				'key' => 'key_' . $index,
				'context' => '',
				'locale' => $locale,
			];
		}

		$pdo = new LocaleMigrationSmokePdo([
			'users' => [
				'columns' => ['locale'],
				'rows' => array_map(static fn (string $locale): array => ['locale' => $locale], $garbageLocales),
			],
			'i18n_translations' => [
				'columns' => ['domain', 'key', 'context', 'locale'],
				'rows' => $translationRows,
			],
			'i18n_build_state' => [
				'columns' => ['locale'],
				'rows' => [
					['locale' => 'EN_US '],
				],
			],
		]);
		LocaleMigrationSmokeDb::$pdo = $pdo;

		(new Migration_20260508_090000_bcp47_locale_registry())->run();

		foreach (['users', 'i18n_translations', 'i18n_build_state'] as $table) {
			foreach ($pdo->rows($table) as $row) {
				$locale = (string) ($row['locale'] ?? '');

				$this->assertTrue(LocaleService::isCanonicalBcp47($locale), "{$table}.locale remained non-canonical: {$locale}");
				$this->assertNotSame('und', $locale);
			}
		}
	}

	public function testMigrationMergesLegacyAndCanonicalLocaleRowsBeforeCanonicalizing(): void
	{
		$pdo = new LocaleMigrationSmokePdo([
			'i18n_translations' => [
				'columns' => ['domain', 'key', 'context', 'locale', 'text', 'human_reviewed', 'source_hash_snapshot'],
				'rows' => [
					[
						'domain' => 'messages',
						'key' => 'hello',
						'context' => '',
						'locale' => 'en_US',
						'text' => 'Hello from legacy',
						'human_reviewed' => 1,
						'source_hash_snapshot' => 'abc',
					],
					[
						'domain' => 'messages',
						'key' => 'hello',
						'context' => '',
						'locale' => 'en-US',
						'text' => 'Hello from canonical',
						'human_reviewed' => 0,
						'source_hash_snapshot' => 'abc',
					],
				],
			],
			'i18n_build_state' => [
				'columns' => ['locale', 'catalog_hash', 'built_at'],
				'rows' => [
					['locale' => 'en_US', 'catalog_hash' => 'old', 'built_at' => '2026-05-01 00:00:00'],
					['locale' => 'en-US', 'catalog_hash' => 'new', 'built_at' => '2026-05-02 00:00:00'],
				],
			],
		]);
		LocaleMigrationSmokeDb::$pdo = $pdo;

		(new Migration_20260508_090000_bcp47_locale_registry())->run();

		$this->assertSame([
			[
				'domain' => 'messages',
				'key' => 'hello',
				'context' => '',
				'locale' => 'en-US',
				'text' => 'Hello from legacy',
				'human_reviewed' => 1,
				'source_hash_snapshot' => 'abc',
			],
		], $pdo->rows('i18n_translations'));
		$this->assertSame([
			['locale' => 'en-US', 'catalog_hash' => 'new', 'built_at' => '2026-05-02 00:00:00'],
		], $pdo->rows('i18n_build_state'));
	}
}

final class LocaleMigrationSmokePdo extends PDO
{
	/** @var array<string, array{columns: list<string>, rows: list<array<string, mixed>>}> */
	private array $tables;

	/**
	 * @param array<string, array{columns: list<string>, rows: list<array<string, mixed>>}> $tables
	 */
	public function __construct(array $tables)
	{
		$this->tables = $tables;
	}

	public function exec(string $statement): int|false
	{
		if (str_starts_with($statement, 'CREATE TABLE IF NOT EXISTS `locales`')) {
			$this->tables['locales'] ??= [
				'columns' => ['locale', 'label', 'native_label', 'is_enabled', 'sort_order'],
				'rows' => [],
			];

			return 0;
		}

		if (preg_match('/ALTER TABLE `([^`]+)`\\s+ADD CONSTRAINT `[^`]+`\\s+FOREIGN KEY \\(`([^`]+)`\\) REFERENCES `locales`/s', $statement, $matches)) {
			$this->assertForeignKeyValuesExist($matches[1], $matches[2]);

			return 0;
		}

		return 0;
	}

	public function prepare(string $query, array $options = []): PDOStatement|false
	{
		return new LocaleMigrationSmokeStatement(function (array $params) use ($query): array {
			if (str_contains($query, 'FROM information_schema.TABLES')) {
				return isset($this->tables[(string) ($params[0] ?? '')]) ? [[1]] : [];
			}

			if (str_contains($query, 'FROM information_schema.COLUMNS')) {
				$table = (string) ($params[0] ?? '');
				$column = (string) ($params[1] ?? '');

				return in_array($column, $this->tables[$table]['columns'] ?? [], true) ? [[1]] : [];
			}

			if (str_contains($query, 'FROM information_schema.TABLE_CONSTRAINTS')) {
				return [];
			}

			if (preg_match('/UPDATE `([^`]+)` SET `([^`]+)` = \\? WHERE `([^`]+)` = \\?/', $query, $matches)) {
				$this->updateLocaleRows($matches[1], $matches[2], (string) ($params[0] ?? ''), (string) ($params[1] ?? ''));

				return [];
			}

			if (preg_match('/DELETE FROM `([^`]+)` WHERE (.+)$/', $query, $matches)) {
				$this->deleteRows($matches[1], $matches[2], $params);

				return [];
			}

			if (preg_match('/INSERT INTO `([^`]+)` \\((.+)\\) VALUES/s', $query, $matches)) {
				$this->insertRow($matches[1], $matches[2], $params);

				return [];
			}

			if (str_starts_with($query, 'INSERT INTO `locales`')) {
				$this->upsertLocale((string) ($params[0] ?? ''));

				return [];
			}

			return [];
		});
	}

	public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
	{
		return new LocaleMigrationSmokeStatement(fn (): array => $this->queryRows($query));
	}

	public function quote(string $string, int $type = PDO::PARAM_STR): string|false
	{
		return "'" . str_replace("'", "''", $string) . "'";
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function rows(string $table): array
	{
		return $this->tables[$table]['rows'] ?? [];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function queryRows(string $query): array
	{
		if (preg_match('/SELECT DISTINCT `([^`]+)` AS locale FROM `([^`]+)`/i', $query, $matches)) {
			$column = $matches[1];
			$table = $matches[2];
			$seen = [];
			$rows = [];

			foreach ($this->tables[$table]['rows'] ?? [] as $row) {
				if (!array_key_exists($column, $row) || $row[$column] === null) {
					continue;
				}

				$value = (string) $row[$column];

				if (isset($seen[$value])) {
					continue;
				}

				$seen[$value] = true;
				$rows[] = ['locale' => $value];
			}

			return $rows;
		}

		if (preg_match('/SELECT `(.+)` FROM `([^`]+)`/i', $query, $matches)) {
			$columns = array_map(
				static fn (string $column): string => trim($column, " `"),
				explode('`, `', $matches[1])
			);
			$table = $matches[2];
			$rows = [];

			foreach ($this->tables[$table]['rows'] ?? [] as $row) {
				$projected = [];

				foreach ($columns as $column) {
					$projected[$column] = $row[$column] ?? null;
				}

				$rows[] = $projected;
			}

			return $rows;
		}

		return [];
	}

	private function updateLocaleRows(string $table, string $column, string $newValue, string $oldValue): void
	{
		foreach ($this->tables[$table]['rows'] as &$row) {
			if ((string) ($row[$column] ?? '') === $oldValue) {
				$row[$column] = $newValue;
			}
		}
		unset($row);
	}

	/**
	 * @param array<int, mixed> $params
	 */
	private function deleteRows(string $table, string $whereSql, array $params): void
	{
		preg_match_all('/`([^`]+)` = \\?/', $whereSql, $matches);
		$columns = $matches[1] ?? [];
		$remaining = [];

		foreach ($this->tables[$table]['rows'] ?? [] as $row) {
			$matchesRow = true;

			foreach ($columns as $index => $column) {
				if ((string) ($row[$column] ?? '') !== (string) ($params[$index] ?? '')) {
					$matchesRow = false;

					break;
				}
			}

			if (!$matchesRow) {
				$remaining[] = $row;
			}
		}

		$this->tables[$table]['rows'] = $remaining;
	}

	/**
	 * @param array<int, mixed> $params
	 */
	private function insertRow(string $table, string $columnsSql, array $params): void
	{
		$columns = array_map(
			static fn (string $column): string => trim($column, " `"),
			explode(',', $columnsSql)
		);
		$row = [];

		foreach ($columns as $index => $column) {
			$row[$column] = $params[$index] ?? '';
		}

		$this->tables[$table]['rows'][] = $row;
	}

	private function upsertLocale(string $locale): void
	{
		$this->tables['locales'] ??= [
			'columns' => ['locale', 'label', 'native_label', 'is_enabled', 'sort_order'],
			'rows' => [],
		];

		foreach ($this->tables['locales']['rows'] as $row) {
			if (($row['locale'] ?? '') === $locale) {
				return;
			}
		}

		$this->tables['locales']['rows'][] = ['locale' => $locale];
	}

	private function assertForeignKeyValuesExist(string $table, string $column): void
	{
		$locales = array_fill_keys(array_map(
			static fn (array $row): string => (string) ($row['locale'] ?? ''),
			$this->tables['locales']['rows'] ?? []
		), true);

		foreach ($this->tables[$table]['rows'] ?? [] as $row) {
			$value = (string) ($row[$column] ?? '');

			if ($value !== '' && !isset($locales[$value])) {
				throw new RuntimeException("Missing locale FK target for {$table}.{$column}: {$value}");
			}
		}
	}
}

final class LocaleMigrationSmokeStatement extends PDOStatement
{
	/** @var callable(array<int, mixed>): list<array<string|int, mixed>> */
	private $handler;

	/** @var list<array<string|int, mixed>> */
	private array $rows = [];

	/**
	 * @param callable(array<int, mixed>): list<array<string|int, mixed>> $handler
	 */
	public function __construct(callable $handler)
	{
		$this->handler = $handler;
	}

	public function execute(?array $params = null): bool
	{
		$this->rows = ($this->handler)($params ?? []);

		return true;
	}

	public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
	{
		if ($this->rows === []) {
			$this->execute();
		}

		return $this->rows;
	}

	public function fetchColumn(int $column = 0): mixed
	{
		if ($this->rows === []) {
			$this->execute();
		}

		$row = $this->rows[0] ?? [];

		return array_values($row)[$column] ?? false;
	}

	public function rowCount(): int
	{
		if ($this->rows === []) {
			$this->execute();
		}

		return count($this->rows);
	}
}
