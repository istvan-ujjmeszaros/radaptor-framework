<?php

declare(strict_types=1);

final class LocaleDiagnosticsService
{
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

		foreach (self::getLocaleHomeResourceIssues() as $issue) {
			$issues[] = $issue;
		}

		foreach (self::getRichTextWidgetStrategyIssues() as $issue) {
			$issues[] = $issue;
		}

		foreach (self::getRichTextWidgetLocaleIssues() as $issue) {
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
	private static function getRichTextWidgetLocaleIssues(): array
	{
		foreach (['widget_connections', 'attributes', 'richtext', 'resource_tree'] as $table) {
			if (!self::tableExists($table)) {
				return [];
			}
		}

		$rows = Db::instance()->query(
			"SELECT wc.`connection_id`, wc.`widget_name`, wc.`page_id`,
				a.`param_value` AS `content_id`,
				rt.`locale` AS `richtext_locale`, rt.`name` AS `richtext_name`,
				p.`node_id`, p.`node_type`, p.`path`, p.`resource_name`, p.`locale`, p.`lft`, p.`rgt`
			FROM `widget_connections` wc
			INNER JOIN `attributes` a
				ON a.`resource_name` = 'widget_connection'
				AND a.`resource_id` = wc.`connection_id`
				AND a.`param_name` = 'content_id'
			LEFT JOIN `richtext` rt ON rt.`id` = CAST(a.`param_value` AS UNSIGNED)
			LEFT JOIN `resource_tree` p ON p.`node_id` = wc.`page_id`
			WHERE LOWER(REPLACE(wc.`widget_name`, '_', '')) = 'richtext'
			ORDER BY wc.`connection_id`"
		)->fetchAll(PDO::FETCH_ASSOC) ?: [];
		$issues = [];

		foreach ($rows as $row) {
			$connection_id = (int) ($row['connection_id'] ?? 0);
			$content_id = (int) ($row['content_id'] ?? 0);

			if ($content_id <= 0) {
				continue;
			}

			if (($row['richtext_locale'] ?? null) === null) {
				continue;
			}

			if (($row['node_id'] ?? null) === null) {
				continue;
			}

			$page_locale = self::getEffectiveResourceLocale($row);

			if ($page_locale === null) {
				continue;
			}

			$richtext_locale = LocaleService::tryCanonicalize((string) ($row['richtext_locale'] ?? ''));

			if ($richtext_locale !== $page_locale) {
				$issues[] = [
					'code' => 'richtext_widget_locale_mismatch',
					'connection_id' => $connection_id,
					'page_id' => (int) ($row['page_id'] ?? 0),
					'page_path' => self::resourcePath($row),
					'page_locale' => $page_locale,
					'content_id' => $content_id,
					'content_name' => (string) ($row['richtext_name'] ?? ''),
					'content_locale' => $richtext_locale,
				];
			}
		}

		return $issues;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function getRichTextWidgetStrategyIssues(): array
	{
		if (!self::tableExists('widget_connections')) {
			return [];
		}

		$count = (int) Db::instance()->query(
			"SELECT COUNT(*)
			FROM `widget_connections`
			WHERE LOWER(REPLACE(`widget_name`, '_', '')) = 'richtext'"
		)->fetchColumn();

		if ($count === 0) {
			return [];
		}

		$widget_class = 'Widget';

		if (!class_exists($widget_class) || !is_callable([$widget_class, 'getContentLocaleStrategy'])) {
			return [[
				'code' => 'richtext_widget_locale_strategy_missing',
				'widget_name' => 'RichText',
				'connections' => $count,
			]];
		}

		try {
			$strategy = $widget_class::getContentLocaleStrategy('RichText');
		} catch (Throwable $exception) {
			return [[
				'code' => 'richtext_widget_locale_strategy_error',
				'widget_name' => 'RichText',
				'connections' => $count,
				'message' => $exception->getMessage(),
			]];
		}

		return is_object($strategy) ? [] : [[
			'code' => 'richtext_widget_locale_strategy_missing',
			'widget_name' => 'RichText',
			'connections' => $count,
		]];
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
