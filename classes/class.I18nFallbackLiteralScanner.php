<?php

declare(strict_types=1);

class I18nFallbackLiteralScanner
{
	private const array EXCLUDED_DIRECTORIES = [
		'.git',
		'cache',
		'dist',
		'generated',
		'node_modules',
		'tmp',
		'vendor',
	];

	private const array STANDALONE_LITERAL_ALLOWLIST = [
		'API',
		'CLI',
		'CSS',
		'CSV',
		'DB',
		'HTML',
		'HTTP',
		'HTTPS',
		'ID',
		'IP',
		'JSON',
		'MCP',
		'PHP',
		'SEO',
		'SQL',
		'TM',
		'UID',
		'URL',
		'XML',
	];

	/**
	 * @param array{roots?: list<string>, locales?: list<string>, all_packages?: bool} $options
	 * @return array{
	 *     status: string,
	 *     files_scanned: int,
	 *     occurrences: int,
	 *     issues: int,
	 *     allowed_literals: int,
	 *     results: list<array<string, mixed>>
	 * }
	 */
	public static function scan(array $options = []): array
	{
		$roots = self::normalizeRoots($options['roots'] ?? self::defaultRoots((bool) ($options['all_packages'] ?? false)));
		$locale_option = $options['locales'] ?? null;
		$locales = self::normalizeLocales(
			is_array($locale_option) && $locale_option !== []
				? $locale_option
				: I18nRuntime::getAvailableLocaleCodes()
		);
		$messages = self::loadMessageIndex();
		$translations = self::loadTranslationIndex($locales);
		$files_scanned = 0;
		$results = [];

		foreach ($roots as $root) {
			foreach (self::listPhpFiles($root) as $file) {
				$content = file_get_contents($file);

				if ($content === false) {
					continue;
				}

				$files_scanned++;

				foreach (self::extractOccurrences($content, $file) as $occurrence) {
					$key = (string) $occurrence['key'];
					$fallback = (string) $occurrence['fallback'];
					[$domain, $message_key] = self::splitKey($key);
					$index_key = self::indexKey($domain, $message_key, '');
					$domain_key = self::domainKey($domain, $message_key);
					$allowed_literal = self::isAllowedStandaloneLiteral($fallback);
					$missing_locales = [];
					$severity = 'ok';
					$code = '';

					if (!isset($messages['exact'][$index_key]) && !isset($messages['by_key'][$domain_key])) {
						if ($allowed_literal) {
							$severity = 'allowed';
							$code = 'allowed_standalone_literal';
						} else {
							$severity = 'error';
							$code = 'missing_i18n_message';
						}
					} else {
						foreach ($locales as $locale) {
							if (
								!isset($translations['exact'][self::indexKey($domain, $message_key, '', $locale)])
								&& !isset($translations['by_key'][self::domainLocaleKey($domain, $message_key, $locale)])
							) {
								$missing_locales[] = $locale;
							}
						}

						if ($missing_locales !== [] && !$allowed_literal) {
							$severity = 'error';
							$code = 'missing_i18n_translations';
						} elseif ($missing_locales !== [] && $allowed_literal) {
							$severity = 'allowed';
							$code = 'allowed_standalone_literal';
						}
					}

					$results[] = [
						...$occurrence,
						'full_key' => $key,
						'domain' => $domain,
						'message_key' => $message_key,
						'severity' => $severity,
						'code' => $code,
						'allowed_literal' => $allowed_literal,
						'missing_locales' => $missing_locales,
					];
				}
			}
		}

		$issue_count = count(array_filter($results, static fn (array $result): bool => $result['severity'] === 'error'));
		$allowed_count = count(array_filter($results, static fn (array $result): bool => $result['severity'] === 'allowed'));

		return [
			'status' => $issue_count > 0 ? 'error' : 'ok',
			'files_scanned' => $files_scanned,
			'occurrences' => count($results),
			'issues' => $issue_count,
			'allowed_literals' => $allowed_count,
			'results' => $results,
		];
	}

	/**
	 * @return list<array{file: string, line: int, pattern: string, key: string, fallback: string}>
	 */
	private static function extractOccurrences(string $content, string $file): array
	{
		$occurrences = [];
		// This is intentionally not a full PHP AST parser; it covers the local i18n helper shapes used today.
		$patterns = [
			'translate_fallback' => '/(?:(?:self|static|[A-Za-z_][A-Za-z0-9_]*)::|\$this->)?(?:translate|translateWithFallback)\(\s*([\'"])([^\'"]+)\1\s*,\s*([\'"])((?:\\\\.|(?!\3).)*)\3/s',
			'strings_bag_fallback' => '/\$this->strings\[\s*([\'"])([^\'"]+)\1\s*\]\s*\?\?\s*([\'"])((?:\\\\.|(?!\3).)*)\3/s',
		];

		foreach ($patterns as $pattern_name => $pattern) {
			if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
				continue;
			}

			foreach ($matches as $match) {
				$key = stripcslashes((string) $match[2][0]);
				$fallback = stripcslashes((string) $match[4][0]);

				if (!self::isTranslatableKey($key) || trim($fallback) === '') {
					continue;
				}

				$occurrences[] = [
					'file' => str_replace('\\', '/', $file),
					'line' => self::lineNumber($content, (int) $match[0][1]),
					'pattern' => $pattern_name,
					'key' => $key,
					'fallback' => $fallback,
				];
			}
		}

		return $occurrences;
	}

	/**
	 * @return list<string>
	 */
	private static function defaultRoots(bool $all_packages = false): array
	{
		$roots = [];

		foreach (I18nSeedTargetDiscovery::discoverRoots($all_packages) as $root) {
			$roots[] = $root['path'];
		}

		$roots = array_values(array_unique(array_map([self::class, 'normalizePath'], $roots)));
		sort($roots);

		return $roots;
	}

	/**
	 * @param mixed $roots
	 * @return list<string>
	 */
	private static function normalizeRoots(mixed $roots): array
	{
		if (!is_array($roots)) {
			return [];
		}

		$normalized = [];

		foreach ($roots as $root) {
			$root = self::normalizePath((string) $root);

			if ($root !== '' && is_dir($root)) {
				$normalized[$root] = true;
			}
		}

		$roots = array_keys($normalized);
		sort($roots);

		return $roots;
	}

	/**
	 * @param mixed $locales
	 * @return list<string>
	 */
	private static function normalizeLocales(mixed $locales): array
	{
		if (!is_array($locales)) {
			return [];
		}

		$normalized = [];

		foreach ($locales as $locale) {
			$locale = LocaleService::tryCanonicalize((string) $locale) ?? '';

			if ($locale === '') {
				continue;
			}

			if (!LocaleRegistry::isKnownLocale($locale)) {
				throw new RuntimeException("Unknown locale: {$locale}");
			}

			$normalized[$locale] = true;
		}

		$locales = array_keys($normalized);
		sort($locales);

		return $locales;
	}

	/**
	 * @return list<string>
	 */
	private static function listPhpFiles(string $root): array
	{
		$root = self::normalizePath($root);

		if (!is_dir($root)) {
			return [];
		}

		$directory_iterator = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
		$filter = new RecursiveCallbackFilterIterator(
			$directory_iterator,
			static function (SplFileInfo $current): bool {
				if (!$current->isDir()) {
					return true;
				}

				return !in_array($current->getBasename(), self::EXCLUDED_DIRECTORIES, true);
			}
		);
		$iterator = new RecursiveIteratorIterator($filter);
		$files = [];

		foreach ($iterator as $file_info) {
			if (!$file_info->isFile()) {
				continue;
			}

			$filename = $file_info->getFilename();

			if ($file_info->getExtension() === 'php' || str_ends_with($filename, '.phtml')) {
				$files[] = self::normalizePath($file_info->getPathname());
			}
		}

		sort($files);

		return $files;
	}

	/**
	 * @return array{exact: array<string, true>, by_key: array<string, true>}
	 */
	private static function loadMessageIndex(): array
	{
		$stmt = Db::instance()->prepare('SELECT domain, `key`, context FROM i18n_messages');
		$stmt->execute();
		$messages = [
			'exact' => [],
			'by_key' => [],
		];

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$domain = (string) $row['domain'];
			$key = (string) $row['key'];
			$messages['exact'][self::indexKey($domain, $key, (string) $row['context'])] = true;
			$messages['by_key'][self::domainKey($domain, $key)] = true;
		}

		return $messages;
	}

	/**
	 * @param list<string> $locales
	 * @return array{exact: array<string, true>, by_key: array<string, true>}
	 */
	private static function loadTranslationIndex(array $locales): array
	{
		if ($locales === []) {
			return [
				'exact' => [],
				'by_key' => [],
			];
		}

		$placeholders = implode(', ', array_fill(0, count($locales), '?'));
		$stmt = Db::instance()->prepare(
			"SELECT domain, `key`, context, locale
			FROM i18n_translations
			WHERE locale IN ({$placeholders})
				AND TRIM(COALESCE(text, '')) <> ''"
		);
		$stmt->execute($locales);
		$translations = [
			'exact' => [],
			'by_key' => [],
		];

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$domain = (string) $row['domain'];
			$key = (string) $row['key'];
			$locale = (string) $row['locale'];
			$translations['exact'][self::indexKey($domain, $key, (string) $row['context'], $locale)] = true;
			$translations['by_key'][self::domainLocaleKey($domain, $key, $locale)] = true;
		}

		return $translations;
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private static function splitKey(string $full_key): array
	{
		$pos = strpos($full_key, '.');

		if ($pos === false) {
			return [$full_key, $full_key];
		}

		return [substr($full_key, 0, $pos), substr($full_key, $pos + 1)];
	}

	private static function indexKey(string $domain, string $key, string $context, string $locale = ''): string
	{
		return implode("\0", [$domain, $key, $context, $locale]);
	}

	private static function domainKey(string $domain, string $key): string
	{
		return $domain . "\0" . $key;
	}

	private static function domainLocaleKey(string $domain, string $key, string $locale): string
	{
		return $domain . "\0" . $key . "\0" . $locale;
	}

	private static function isTranslatableKey(string $key): bool
	{
		return preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+$/', $key) === 1;
	}

	private static function isAllowedStandaloneLiteral(string $fallback): bool
	{
		$fallback = trim($fallback);

		return in_array($fallback, self::STANDALONE_LITERAL_ALLOWLIST, true)
			|| preg_match('/^\d+(?:\s+(?:minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years))?$/i', $fallback) === 1;
	}

	private static function lineNumber(string $content, int $offset): int
	{
		return substr_count(substr($content, 0, $offset), "\n") + 1;
	}

	private static function normalizePath(string $path): string
	{
		$path = str_replace('\\', '/', $path);
		$real = realpath($path);

		if ($real !== false) {
			return rtrim(str_replace('\\', '/', $real), '/');
		}

		return rtrim($path, '/');
	}
}
