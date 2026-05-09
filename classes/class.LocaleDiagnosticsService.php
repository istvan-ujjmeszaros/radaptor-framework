<?php

declare(strict_types=1);

final class LocaleDiagnosticsService
{
	private const int SOURCE_KEY_SCAN_CACHE_VERSION = 1;
	private const string SOURCE_KEY_SCAN_CACHE_RELATIVE_PATH = 'generated/i18n-source-scan-cache.json';
	private const string UNSCOPED_TEXT_COLUMN_ALLOWLIST_RELATIVE_PATH = 'generated/i18n-unscoped-allowlist.json';

	// Keep in sync with .gitignore and generated/build output directories.
	private const array SOURCE_KEY_EXCLUDED_DIRECTORIES = [
		'.git',
		'cache',
		'dist',
		'generated',
		'node_modules',
		'storage',
		'tests',
		'tmp',
		'uploaded_files',
		'vendor',
		'_UPLOADS',
	];

	/**
	 * @return array<string, mixed>
	 */
	public static function diagnose(): array
	{
		$issues = [];
		$warnings = [];
		$info = [];
		$default_locale = null;

		try {
			$default_locale = LocaleService::validateConfiguredDefaultLocale();
		} catch (Throwable $exception) {
			$issues[] = [
				'code' => 'invalid_default_locale',
				'message' => $exception->getMessage(),
			];
		}

		$locales_table_exists = self::tableExists('locales');

		if ($locales_table_exists && $default_locale !== null) {
			$row = self::fetchLocaleRow($default_locale);

			if ($row === null) {
				$issues[] = [
					'code' => 'default_locale_missing',
					'locale' => $default_locale,
				];
			} elseif ((int) ($row['is_enabled'] ?? 0) !== 1) {
				$issues[] = [
					'code' => 'default_locale_disabled',
					'locale' => $default_locale,
				];
			}
		}

		$disabled_usage = self::getDisabledLocaleUsage();

		foreach ($disabled_usage as $row) {
			$info[] = [
				'code' => 'disabled_locale_usage',
				'locale' => $row['locale'],
				'table' => $row['table'],
				'rows' => $row['rows'],
			];
		}

		foreach (self::getPotentialUnscopedTextColumns() as $row) {
			$info[] = [
				'code' => 'potential_unscoped_text_column',
				'table' => $row['table'],
				'column' => $row['column'],
				'type' => $row['type'],
			];
		}

		foreach (self::getNonCanonicalLocaleColumns() as $row) {
			$warnings[] = [
				'code' => 'non_canonical_locale_value',
				'table' => $row['table'],
				'column' => $row['column'],
				'value' => $row['value'],
			];
		}

		foreach (self::getLocaleColumnDefinitionIssues() as $row) {
			$warnings[] = [
				'code' => 'locale_column_definition_mismatch',
				'table' => $row['table'],
				'column' => $row['column'],
				'type' => $row['type'],
				'charset' => $row['charset'],
				'collation' => $row['collation'],
			];
		}

		foreach (self::getMissingSourceI18nKeyIssues() as $issue) {
			$issues[] = $issue;
		}

		foreach (self::getLocaleHomeResourceIssues() as $issue) {
			$issues[] = $issue;
		}

		return [
			'status' => $issues === [] ? 'success' : 'error',
			'default_locale' => $default_locale,
			'locales_table_exists' => $locales_table_exists,
			'issues' => $issues,
			'warnings' => $warnings,
			'info' => $info,
		];
	}

	/**
	 * @return list<array{locale: string, table: string, rows: int}>
	 */
	private static function getDisabledLocaleUsage(): array
	{
		if (!self::tableExists('locales')) {
			return [];
		}

		$result = [];

		foreach ([
			'users' => ['users', 'locale'],
			'resource_tree' => ['resource_tree', 'locale'],
			'richtext' => ['richtext', 'locale'],
			'i18n_translations' => ['i18n_translations', 'locale'],
			'i18n_build_state' => ['i18n_build_state', 'locale'],
		] as $key => [$table, $column]) {
			if (!self::columnExists($table, $column)) {
				continue;
			}

			$rows = Db::instance()->query(
				"SELECT t.`{$column}` AS locale, COUNT(*) AS row_count
				FROM `{$table}` t
				INNER JOIN `locales` l ON l.`locale` = t.`{$column}`
				WHERE l.`is_enabled` = 0
				GROUP BY t.`{$column}`"
			)->fetchAll(PDO::FETCH_ASSOC) ?: [];

			foreach ($rows as $row) {
				$result[] = [
					'locale' => (string) ($row['locale'] ?? ''),
					'table' => $key,
					'rows' => (int) ($row['row_count'] ?? 0),
				];
			}
		}

		return $result;
	}

	/**
	 * @return list<array{table: string, column: string, value: string}>
	 */
	private static function getNonCanonicalLocaleColumns(): array
	{
		$result = [];

		foreach (self::knownLocaleColumns() as [$table, $column]) {
			if (!self::columnExists($table, $column)) {
				continue;
			}

			$rows = Db::instance()->query(
				"SELECT DISTINCT `{$column}` AS locale
				FROM `{$table}`
				WHERE `{$column}` IS NOT NULL AND `{$column}` <> ''"
			)->fetchAll(PDO::FETCH_ASSOC) ?: [];

			foreach ($rows as $row) {
				$value = (string) ($row['locale'] ?? '');

				if (!LocaleService::isCanonicalBcp47($value)) {
					$result[] = [
						'table' => $table,
						'column' => $column,
						'value' => $value,
					];
				}
			}
		}

		return $result;
	}

	/**
	 * @return list<array{table: string, column: string, type: string, charset: string, collation: string}>
	 */
	private static function getLocaleColumnDefinitionIssues(): array
	{
		$result = [];

		foreach (self::knownLocaleColumns() as [$table, $column]) {
			if (!self::columnExists($table, $column)) {
				continue;
			}

			$stmt = Db::instance()->prepare(
				"SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, CHARACTER_SET_NAME, COLLATION_NAME
				FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE()
					AND TABLE_NAME = ?
					AND COLUMN_NAME = ?"
			);
			$stmt->execute([$table, $column]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
			$type = strtolower((string) ($row['DATA_TYPE'] ?? ''));
			$length = (int) ($row['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
			$charset = (string) ($row['CHARACTER_SET_NAME'] ?? '');
			$collation = (string) ($row['COLLATION_NAME'] ?? '');

			if ($type !== 'varchar' || $length < 64 || $charset !== 'ascii' || $collation !== 'ascii_bin') {
				$result[] = [
					'table' => $table,
					'column' => $column,
					'type' => $type . '(' . $length . ')',
					'charset' => $charset,
					'collation' => $collation,
				];
			}
		}

		return $result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function getLocaleHomeResourceIssues(): array
	{
		if (!self::tableExists('locale_home_resources') || !self::tableExists('resource_tree')) {
			return [];
		}

		$rows = Db::instance()->query(
			"SELECT `site_context`, `locale`, `computed_resource_id`, `manual_resource_id`
			FROM `locale_home_resources`
			ORDER BY `site_context`, `locale`"
		)->fetchAll(PDO::FETCH_ASSOC) ?: [];
		$issues = [];

		foreach ($rows as $row) {
			$site_context = (string) ($row['site_context'] ?? '');
			$locale = LocaleService::tryCanonicalize((string) ($row['locale'] ?? '')) ?? (string) ($row['locale'] ?? '');

			foreach (['manual_resource_id', 'computed_resource_id'] as $field) {
				$resource_id = self::nullableResourceId($row[$field] ?? null);

				if ($resource_id === null) {
					continue;
				}

				$issue = self::validateLocaleHomeResourceReference($site_context, $locale, $resource_id);

				if ($issue !== null) {
					$issues[] = [
						'code' => 'locale_home_resource_invalid',
						'site_context' => $site_context,
						'locale' => $locale,
						'field' => $field,
						'resource_id' => $resource_id,
					] + $issue;
				}
			}

			$expected_computed_id = self::computeExpectedLocaleHomeResourceId($site_context, $locale);
			$stored_computed_id = self::nullableResourceId($row['computed_resource_id'] ?? null);

			if ($stored_computed_id !== $expected_computed_id) {
				$issues[] = [
					'code' => 'locale_home_resource_stale_computed',
					'site_context' => $site_context,
					'locale' => $locale,
					'stored_resource_id' => $stored_computed_id,
					'expected_resource_id' => $expected_computed_id,
				];
			}
		}

		return $issues;
	}

	private static function nullableResourceId(mixed $resource_id): ?int
	{
		return is_numeric($resource_id) && (int) $resource_id > 0 ? (int) $resource_id : null;
	}

	private static function computeExpectedLocaleHomeResourceId(string $site_context, string $locale): ?int
	{
		$root = self::fetchSiteRootByName($site_context);

		if ($root === null) {
			return null;
		}

		$stmt = Db::instance()->prepare(
			"SELECT `node_id`, `node_type`, `parent_id`, `path`, `resource_name`, `locale`, `lft`, `rgt`
				FROM `resource_tree`
					WHERE `lft` > ? AND `rgt` < ?
						AND `locale` = ?
						AND `node_type` IN ('folder', 'webpage')
					ORDER BY `lft`
					LIMIT 1"
		);
		$stmt->execute([(int) ($root['lft'] ?? 0), (int) ($root['rgt'] ?? 0), $locale]);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

		if ($rows === []) {
			return null;
		}

		$row = $rows[0];

		if (($row['node_type'] ?? '') === 'webpage') {
			$node_id = self::nullableResourceId($row['node_id'] ?? null);

			return $node_id !== null && self::validateLocaleHomeResourceReference($site_context, $locale, $node_id) === null
				? $node_id
				: null;
		}

		$index_id = self::fetchDirectIndexPageId((int) ($row['node_id'] ?? 0));

		if ($index_id !== null && self::validateLocaleHomeResourceReference($site_context, $locale, $index_id) === null) {
			return $index_id;
		}

		return null;
	}

	private static function fetchDirectIndexPageId(int $folder_id): ?int
	{
		if ($folder_id <= 0) {
			return null;
		}

		$stmt = Db::instance()->prepare(
			"SELECT `node_id`
			FROM `resource_tree`
			WHERE `parent_id` = ?
				AND `node_type` = 'webpage'
				AND `resource_name` = 'index.html'
			ORDER BY `lft`
			LIMIT 1"
		);
		$stmt->execute([$folder_id]);
		$node_id = $stmt->fetchColumn();

		return self::nullableResourceId($node_id);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function validateLocaleHomeResourceReference(string $site_context, string $locale, int $resource_id): ?array
	{
		$resource = self::fetchResourceRowById($resource_id);

		if ($resource === null) {
			return ['reason' => 'missing_resource'];
		}

		$root = self::fetchSiteRootByName($site_context);

		if ($root === null) {
			return ['reason' => 'unknown_site_context'];
		}

		if (($resource['node_type'] ?? '') !== 'webpage') {
			return [
				'reason' => 'not_webpage',
				'node_type' => (string) ($resource['node_type'] ?? ''),
				'path' => self::resourcePath($resource),
			];
		}

		if ((int) ($resource['lft'] ?? 0) <= (int) ($root['lft'] ?? 0) || (int) ($resource['rgt'] ?? 0) >= (int) ($root['rgt'] ?? 0)) {
			return [
				'reason' => 'wrong_site_context',
				'path' => self::resourcePath($resource),
			];
		}

		if (self::isProtectedSystemPath(self::resourcePath($resource))) {
			return [
				'reason' => 'protected_path',
				'path' => self::resourcePath($resource),
			];
		}

		$effective_locale = self::getEffectiveResourceLocale($resource);

		if ($effective_locale !== $locale) {
			return [
				'reason' => 'locale_mismatch',
				'path' => self::resourcePath($resource),
				'effective_locale' => $effective_locale,
			];
		}

		return null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function getMissingSourceI18nKeyIssues(): array
	{
		if (!self::tableExists('i18n_messages')) {
			return [];
		}

		$existing_keys = self::getRegisteredI18nMessageKeys();
		$source_keys = [];
		$scan_cache = self::loadSourceKeyScanCache();
		$next_scan_cache = self::emptySourceKeyScanCache();

		foreach (self::getSourceKeyScanRoots() as $root) {
			self::scanSourceKeyRoot($root, $source_keys, $scan_cache, $next_scan_cache);
		}

		self::writeSourceKeyScanCache($next_scan_cache);
		ksort($source_keys);
		$issues = [];

		foreach ($source_keys as $key => $occurrences) {
			if (isset($existing_keys[$key])) {
				continue;
			}

			$issues[] = [
				'code' => 'source_i18n_key_missing',
				'key' => $key,
				'occurrences' => $occurrences,
			];
		}

		return $issues;
	}

	/**
	 * @return array<string, true>
	 */
	private static function getRegisteredI18nMessageKeys(): array
	{
		$rows = Db::instance()->query(
			"SELECT `domain`, `key`
			FROM `i18n_messages`
			WHERE `context` = ''"
		)->fetchAll(PDO::FETCH_ASSOC) ?: [];
		$keys = [];

		foreach ($rows as $row) {
			$domain = (string) ($row['domain'] ?? '');
			$key = (string) ($row['key'] ?? '');

			if ($domain !== '' && $key !== '') {
				$keys[$domain . '.' . $key] = true;
			}
		}

		return $keys;
	}

	/**
	 * @return list<string>
	 */
	private static function getSourceKeyScanRoots(): array
	{
		$roots = [
			DEPLOY_ROOT . 'app/',
		];

		foreach (PackagePathHelper::getActivePackageRoots(['core', 'theme', 'plugin']) as $package_root) {
			$roots[] = rtrim((string) $package_root, '/') . '/';
		}

		$framework_root = PackagePathHelper::getFrameworkRoot();
		$cms_root = PackagePathHelper::getCmsRoot();

		if (is_string($cms_root)) {
			$roots[] = rtrim($cms_root, '/') . '/';
		}

		if (is_string($framework_root)) {
			$roots[] = rtrim($framework_root, '/') . '/';
		}

		$roots = array_values(array_unique(array_map(
			static fn (string $root): string => rtrim(str_replace('\\', '/', $root), '/') . '/',
			$roots
		)));
		sort($roots);

		return $roots;
	}

	/**
	 * @param array<string, list<array{file: string, line: int}>> $source_keys
	 * @param array{version: int, files: array<string, array<string, mixed>>} $scan_cache
	 * @param array{version: int, files: array<string, array<string, mixed>>} $next_scan_cache
	 */
	private static function scanSourceKeyRoot(string $root, array &$source_keys, array $scan_cache, array &$next_scan_cache): void
	{
		if (!is_dir($root)) {
			return;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
			);
		} catch (Throwable) {
			return;
		}

		foreach ($iterator as $file) {
			if (!$file instanceof SplFileInfo || !$file->isFile()) {
				continue;
			}

			$path = str_replace('\\', '/', $file->getPathname());

			if (self::isSourceKeyPathExcluded($path)) {
				continue;
			}

			if (!self::isSourceKeyScanFile($path)) {
				continue;
			}

			$file_source_keys = self::getCachedSourceKeysForFile($path, $file, $scan_cache);

			if ($file_source_keys !== null) {
				self::mergeSourceI18nKeys($file_source_keys, $source_keys);
				$next_scan_cache['files'][$path] = self::buildSourceKeyScanCacheEntry($file, $file_source_keys);

				continue;
			}

			$content = file_get_contents($path);

			if ($content === false) {
				continue;
			}

			$file_source_keys = [];

			if (str_ends_with($path, '.blade.php') || str_ends_with($path, '.twig') || str_ends_with($path, '.js')) {
				self::extractRegexSourceKeys($content, $path, $file_source_keys);
			} elseif (str_ends_with($path, '.php')) {
				self::extractPhpSourceKeys($content, $path, $file_source_keys);
				self::extractTemplateStringBagSourceKeys($content, $path, $file_source_keys);
			}

			self::mergeSourceI18nKeys($file_source_keys, $source_keys);
			$next_scan_cache['files'][$path] = self::buildSourceKeyScanCacheEntry($file, $file_source_keys);
		}
	}

	/**
	 * @return array{version: int, files: array<string, array<string, mixed>>}
	 */
	private static function emptySourceKeyScanCache(): array
	{
		return [
			'version' => self::SOURCE_KEY_SCAN_CACHE_VERSION,
			'files' => [],
		];
	}

	/**
	 * @return array{version: int, files: array<string, array<string, mixed>>}
	 */
	private static function loadSourceKeyScanCache(): array
	{
		$path = self::sourceKeyScanCachePath();

		if (!is_file($path)) {
			return self::emptySourceKeyScanCache();
		}

		try {
			$decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable) {
			return self::emptySourceKeyScanCache();
		}

		if (!is_array($decoded) || (int) ($decoded['version'] ?? 0) !== self::SOURCE_KEY_SCAN_CACHE_VERSION || !is_array($decoded['files'] ?? null)) {
			return self::emptySourceKeyScanCache();
		}

		return [
			'version' => self::SOURCE_KEY_SCAN_CACHE_VERSION,
			'files' => $decoded['files'],
		];
	}

	/**
	 * @param array{version: int, files: array<string, array<string, mixed>>} $cache
	 */
	private static function writeSourceKeyScanCache(array $cache): void
	{
		$path = self::sourceKeyScanCachePath();
		$directory = dirname($path);

		try {
			if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
				return;
			}

			$encoded = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

			if (!is_string($encoded)) {
				return;
			}

			file_put_contents($path, $encoded . "\n", LOCK_EX);
		} catch (Throwable) {
			// Diagnostics must not fail because the optional scan cache is unavailable.
		}
	}

	private static function sourceKeyScanCachePath(): string
	{
		return DEPLOY_ROOT . self::SOURCE_KEY_SCAN_CACHE_RELATIVE_PATH;
	}

	/**
	 * @param array{version: int, files: array<string, array<string, mixed>>} $scan_cache
	 * @return array<string, list<array{file: string, line: int}>>|null
	 */
	private static function getCachedSourceKeysForFile(string $path, SplFileInfo $file, array $scan_cache): ?array
	{
		$entry = $scan_cache['files'][$path] ?? null;

		if (!is_array($entry) || (int) ($entry['mtime'] ?? -1) !== $file->getMTime() || (int) ($entry['size'] ?? -1) !== $file->getSize()) {
			return null;
		}

		return self::normalizeCachedSourceKeyMap($entry['keys'] ?? null);
	}

	/**
	 * @param array<string, list<array{file: string, line: int}>> $file_source_keys
	 * @return array{mtime: int, size: int, keys: array<string, list<array{file: string, line: int}>>}
	 */
	private static function buildSourceKeyScanCacheEntry(SplFileInfo $file, array $file_source_keys): array
	{
		ksort($file_source_keys);

		return [
			'mtime' => $file->getMTime(),
			'size' => $file->getSize(),
			'keys' => $file_source_keys,
		];
	}

	/**
	 * @return array<string, list<array{file: string, line: int}>>|null
	 */
	private static function normalizeCachedSourceKeyMap(mixed $keys): ?array
	{
		if (!is_array($keys)) {
			return null;
		}

		$normalized = [];

		foreach ($keys as $key => $occurrences) {
			if (!is_string($key) || !is_array($occurrences)) {
				continue;
			}

			foreach ($occurrences as $occurrence) {
				if (!is_array($occurrence) || !is_string($occurrence['file'] ?? null)) {
					continue;
				}

				$normalized[$key][] = [
					'file' => $occurrence['file'],
					'line' => max(1, (int) ($occurrence['line'] ?? 1)),
				];
			}
		}

		return $normalized;
	}

	/**
	 * @param array<string, list<array{file: string, line: int}>> $file_source_keys
	 * @param array<string, list<array{file: string, line: int}>> $source_keys
	 */
	private static function mergeSourceI18nKeys(array $file_source_keys, array &$source_keys): void
	{
		foreach ($file_source_keys as $key => $occurrences) {
			foreach ($occurrences as $occurrence) {
				self::registerSourceI18nKey($key, $occurrence['file'], $occurrence['line'], $source_keys);
			}
		}
	}

	private static function isSourceKeyPathExcluded(string $path): bool
	{
		$normalized = '/' . trim(str_replace('\\', '/', $path), '/') . '/';

		foreach (self::SOURCE_KEY_EXCLUDED_DIRECTORIES as $directory) {
			if (str_contains($normalized, '/' . $directory . '/')) {
				return true;
			}
		}

		return false;
	}

	private static function isSourceKeyScanFile(string $path): bool
	{
		return str_ends_with($path, '.php')
			|| str_ends_with($path, '.blade.php')
			|| str_ends_with($path, '.twig')
			|| str_ends_with($path, '.js');
	}

	/**
	 * @param array<string, list<array{file: string, line: int}>> $source_keys
	 */
	private static function extractPhpSourceKeys(string $content, string $file, array &$source_keys): void
	{
		$tokens = token_get_all($content);
		$count = count($tokens);

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];

			if (!is_array($token) || $token[0] !== T_STRING) {
				continue;
			}

			$name = $token[1];

			if ($name !== 't' && $name !== 'registerI18n') {
				continue;
			}

			if (self::previousSignificantTokenIsMemberAccess($tokens, $i)) {
				continue;
			}

			$j = self::nextNonWhitespaceTokenIndex($tokens, $i + 1);

			if ($j === null || ($tokens[$j] ?? null) !== '(') {
				continue;
			}

			$j = self::nextNonWhitespaceTokenIndex($tokens, $j + 1);

			if ($j === null || !isset($tokens[$j])) {
				continue;
			}

			if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
				self::registerSourceI18nKey(
					self::unquotePhpStringToken((string) $tokens[$j][1]),
					$file,
					(int) ($tokens[$j][2] ?? 1),
					$source_keys
				);

				continue;
			}

			if ($name !== 'registerI18n' || !self::isPhpArrayStartToken($tokens[$j])) {
				continue;
			}

			$depth = 1;
			$j++;

			while ($j < $count && $depth > 0) {
				$current = $tokens[$j];

				if (self::isPhpArrayStartToken($current)) {
					$depth++;
				} elseif ($current === ']' || $current === ')') {
					$depth--;
				} elseif (is_array($current) && $current[0] === T_CONSTANT_ENCAPSED_STRING) {
					self::registerSourceI18nKey(
						self::unquotePhpStringToken((string) $current[1]),
						$file,
						(int) ($current[2] ?? 1),
						$source_keys
					);
				}

				$j++;
			}
		}
	}

	/**
	 * @param list<array|string> $tokens
	 */
	private static function previousSignificantTokenIsMemberAccess(array $tokens, int $index): bool
	{
		for ($i = $index - 1; $i >= 0; $i--) {
			$token = $tokens[$i];

			if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}

			return $token === '->' || (is_array($token) && in_array($token[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true));
		}

		return false;
	}

	/**
	 * @param list<array|string> $tokens
	 */
	private static function nextNonWhitespaceTokenIndex(array $tokens, int $start): ?int
	{
		$count = count($tokens);

		for ($i = $start; $i < $count; $i++) {
			$token = $tokens[$i];

			if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}

			return $i;
		}

		return null;
	}

	private static function isPhpArrayStartToken(mixed $token): bool
	{
		return $token === '[' || (is_array($token) && strtolower((string) ($token[1] ?? '')) === 'array');
	}

	private static function unquotePhpStringToken(string $value): string
	{
		return stripcslashes(trim($value, "'\""));
	}

	/**
	 * @param array<string, list<array{file: string, line: int}>> $source_keys
	 */
	private static function extractRegexSourceKeys(string $content, string $file, array &$source_keys): void
	{
		if (preg_match_all('/\bt\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches, PREG_OFFSET_CAPTURE)) {
			foreach ($matches[1] as [$key, $offset]) {
				self::registerSourceI18nKey((string) $key, $file, self::lineNumberAtOffset($content, (int) $offset), $source_keys);
			}
		}

		if (preg_match_all('/registerI18n\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches, PREG_OFFSET_CAPTURE)) {
			foreach ($matches[1] as [$key, $offset]) {
				self::registerSourceI18nKey((string) $key, $file, self::lineNumberAtOffset($content, (int) $offset), $source_keys);
			}
		}

		if (preg_match_all('/registerI18n\s*\(\s*\[([^\]]+)\]/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
			foreach ($matches[1] as [$inner, $inner_offset]) {
				if (!preg_match_all('/[\'"]([^\'"]+)[\'"]/', (string) $inner, $key_matches, PREG_OFFSET_CAPTURE)) {
					continue;
				}

				foreach ($key_matches[1] as [$key, $offset]) {
					self::registerSourceI18nKey(
						(string) $key,
						$file,
						self::lineNumberAtOffset($content, (int) $inner_offset + (int) $offset),
						$source_keys
					);
				}
			}
		}

		if (preg_match_all('/window\.__i18n\[[\'"]([^\'"]+)[\'"]\]/', $content, $matches, PREG_OFFSET_CAPTURE)) {
			foreach ($matches[1] as [$key, $offset]) {
				self::registerSourceI18nKey((string) $key, $file, self::lineNumberAtOffset($content, (int) $offset), $source_keys);
			}
		}
	}

	/**
	 * @param array<string, list<array{file: string, line: int}>> $source_keys
	 */
	private static function extractTemplateStringBagSourceKeys(string $content, string $file, array &$source_keys): void
	{
		if (!preg_match_all('/\\$this->strings\\[[\'"]([^\'"]+)[\'"]\\]/', $content, $matches, PREG_OFFSET_CAPTURE)) {
			return;
		}

		foreach ($matches[1] as [$key, $offset]) {
			self::registerSourceI18nKey((string) $key, $file, self::lineNumberAtOffset($content, (int) $offset), $source_keys);
		}
	}

	private static function lineNumberAtOffset(string $content, int $offset): int
	{
		return substr_count(substr($content, 0, max(0, $offset)), "\n") + 1;
	}

	/**
	 * @param array<string, list<array{file: string, line: int}>> $source_keys
	 */
	private static function registerSourceI18nKey(string $key, string $file, int $line, array &$source_keys): void
	{
		if (!preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+$/', $key)) {
			return;
		}

		$source_keys[$key] ??= [];

		if (count($source_keys[$key]) >= 10) {
			return;
		}

		$source_keys[$key][] = [
			'file' => $file,
			'line' => $line,
		];
	}

	/**
	 * @return list<array{table: string, column: string, type: string}>
	 */
	private static function getPotentialUnscopedTextColumns(): array
	{
		try {
			$rows = Db::instance()->query(
				"SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE
				FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE()
					AND DATA_TYPE IN ('char', 'varchar', 'text', 'mediumtext', 'longtext')
				ORDER BY TABLE_NAME, ORDINAL_POSITION"
			)->fetchAll(PDO::FETCH_ASSOC) ?: [];
		} catch (Throwable) {
			return [];
		}

		$result = [];
		$allowlist = self::getPotentialUnscopedTextColumnAllowlist();
		$interesting_names = [
			'title' => true,
			'subtitle' => true,
			'description' => true,
			'content' => true,
			'text' => true,
			'label' => true,
			'name' => true,
			'heading' => true,
			'keywords' => true,
		];

		foreach ($rows as $row) {
			$table = (string) ($row['TABLE_NAME'] ?? '');
			$column = (string) ($row['COLUMN_NAME'] ?? '');

			if ($table === '' || $column === '' || str_starts_with($table, 'i18n_') || self::columnExists($table, 'locale')) {
				continue;
			}

			if (isset($allowlist[$table . '.' . $column]) || isset($allowlist[$table . '.*'])) {
				continue;
			}

			if (!isset($interesting_names[strtolower($column)])) {
				continue;
			}

			$result[] = [
				'table' => $table,
				'column' => $column,
				'type' => (string) ($row['COLUMN_TYPE'] ?? $row['DATA_TYPE'] ?? ''),
			];
		}

		return $result;
	}

	/**
	 * @return array<string, true>
	 */
	private static function getPotentialUnscopedTextColumnAllowlist(): array
	{
		$path = DEPLOY_ROOT . self::UNSCOPED_TEXT_COLUMN_ALLOWLIST_RELATIVE_PATH;

		if (!is_file($path)) {
			return [];
		}

		try {
			$decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable) {
			return [];
		}

		if (!is_array($decoded)) {
			return [];
		}

		$items = is_array($decoded['allow'] ?? null) ? $decoded['allow'] : $decoded;

		if (!is_array($items)) {
			return [];
		}

		$allowlist = [];

		foreach ($items as $item) {
			if (!is_string($item) || !preg_match('/^[A-Za-z0-9_]+\\.(?:[A-Za-z0-9_]+|\\*)$/', $item)) {
				continue;
			}

			$allowlist[$item] = true;
		}

		return $allowlist;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function fetchResourceRowById(int $resource_id): ?array
	{
		$stmt = Db::instance()->prepare(
			"SELECT `node_id`, `node_type`, `parent_id`, `path`, `resource_name`, `locale`, `lft`, `rgt`
			FROM `resource_tree`
			WHERE `node_id` = ?
			LIMIT 1"
		);
		$stmt->execute([$resource_id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function fetchSiteRootByName(string $site_context): ?array
	{
		$stmt = Db::instance()->prepare(
			"SELECT `node_id`, `node_type`, `path`, `resource_name`, `locale`, `lft`, `rgt`
			FROM `resource_tree`
			WHERE `node_type` = 'root' AND `resource_name` = ?
			LIMIT 1"
		);
		$stmt->execute([$site_context]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}

	/**
	 * @param array<string, mixed> $resource
	 */
	private static function getEffectiveResourceLocale(array $resource): ?string
	{
		$own_locale = LocaleService::tryCanonicalize((string) ($resource['locale'] ?? ''));

		if ($own_locale !== null) {
			return $own_locale;
		}

		$stmt = Db::instance()->prepare(
			"SELECT `locale`
			FROM `resource_tree`
			WHERE `lft` < ? AND `rgt` > ? AND `locale` IS NOT NULL AND `locale` <> ''
			ORDER BY `lft` DESC
			LIMIT 1"
		);
		$stmt->execute([(int) ($resource['lft'] ?? 0), (int) ($resource['rgt'] ?? 0)]);
		$value = $stmt->fetchColumn();

		return is_string($value) ? LocaleService::tryCanonicalize($value) : null;
	}

	/**
	 * @param array<string, mixed> $resource
	 */
	private static function resourcePath(array $resource): string
	{
		$path = (string) ($resource['path'] ?? '');
		$name = (string) ($resource['resource_name'] ?? '');

		if (($resource['node_type'] ?? '') === 'root') {
			return '/';
		}

		return self::normalizePath($path . $name);
	}

	private static function isProtectedSystemPath(string $path): bool
	{
		$path = self::normalizePath($path);

		if ($path === '/login.html') {
			return true;
		}

		foreach (['/admin', '/account'] as $protected_path) {
			if ($path === $protected_path || str_starts_with($path, $protected_path . '/')) {
				return true;
			}
		}

		return false;
	}

	private static function normalizePath(string $path): string
	{
		$path = '/' . ltrim(trim($path), '/');
		$path = preg_replace('#/+#', '/', $path) ?? $path;

		return $path === '' ? '/' : $path;
	}

	/**
	 * @return list<array{0: string, 1: string}>
	 */
	private static function knownLocaleColumns(): array
	{
		return [
			['locales', 'locale'],
			['users', 'locale'],
			['i18n_translations', 'locale'],
			['i18n_build_state', 'locale'],
			['i18n_tm_entries', 'source_locale'],
			['i18n_tm_entries', 'target_locale'],
			['resource_tree', 'locale'],
			['richtext', 'locale'],
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function fetchLocaleRow(string $locale): ?array
	{
		if (!self::tableExists('locales')) {
			return null;
		}

		$stmt = Db::instance()->prepare("SELECT * FROM `locales` WHERE `locale` = ?");
		$stmt->execute([$locale]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}

	private static function tableExists(string $table): bool
	{
		try {
			$stmt = Db::instance()->prepare(
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

	private static function columnExists(string $table, string $column): bool
	{
		if (!self::tableExists($table)) {
			return false;
		}

		$stmt = Db::instance()->prepare(
			"SELECT 1
			FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = ?
				AND COLUMN_NAME = ?"
		);
		$stmt->execute([$table, $column]);

		return (bool) $stmt->fetchColumn();
	}
}
