<?php

declare(strict_types=1);

class I18nHardcodedUiScanner
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

	private const array EXCLUDED_PATH_PARTS = [
		'/generators/templates/',
	];

	private const array IGNORED_TEXT_TAGS = [
		'script',
		'style',
		'pre',
		'code',
		'svg',
	];

	private const array TRANSLATABLE_ATTRIBUTES = [
		'alt',
		'aria-label',
		'placeholder',
		'title',
	];

	private const array VISIBLE_INPUT_VALUE_TYPES = [
		'button',
		'reset',
		'submit',
	];

	private const array STANDALONE_LITERAL_ALLOWLIST = [
		'API',
		'CLI',
		'CSS',
		'CSV',
		'DB',
		'DNS',
		'HTML',
		'HTTP',
		'HTTPS',
		'ID',
		'IP',
		'JSON',
		'MCP',
		'PDF',
		'PHP',
		'SEO',
		'SQL',
		'SSL',
		'TM',
		'UID',
		'UI',
		'URL',
		'XML',
	];

	/**
	 * @param array{roots?: list<string>, all_packages?: bool} $options
	 * @return array{
	 *     status: string,
	 *     files_scanned: int,
	 *     issues: int,
	 *     results: list<array<string, mixed>>
	 * }
	 */
	public static function scan(array $options = []): array
	{
		$roots = self::normalizeRoots($options['roots'] ?? self::defaultRoots((bool) ($options['all_packages'] ?? false)));
		$files_scanned = 0;
		$results = [];

		foreach ($roots as $root) {
			foreach (self::listTemplateFiles($root) as $file) {
				$content = file_get_contents($file);

				if ($content === false) {
					continue;
				}

				$format = self::templateFormat($file);

				if ($format === null) {
					continue;
				}

				$files_scanned++;
				array_push($results, ...self::scanTemplateFile($content, $file, $format));
			}
		}

		return [
			'status' => $results === [] ? 'ok' : 'warning',
			'files_scanned' => $files_scanned,
			'issues' => count($results),
			'results' => $results,
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function scanTemplateFile(string $content, string $file, string $format): array
	{
		if ($format !== 'php') {
			return self::scanTemplateChunk(self::maskTemplateSyntax($content), $file, $format, 1);
		}

		$masked_content = '';

		foreach (token_get_all($content) as $token) {
			$token_content = is_array($token) ? (string) $token[1] : (string) $token;
			$masked_content .= is_array($token) && $token[0] === T_INLINE_HTML
				? $token_content
				: self::preserveLineWhitespace($token_content);
		}

		return self::scanTemplateChunk($masked_content, $file, $format, 1);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function scanTemplateChunk(string $content, string $file, string $format, int $base_line): array
	{
		$results = [];
		$offset = 0;
		$ignored_tag_stack = [];
		$length = strlen($content);

		while ($offset < $length) {
			$tag_start = self::findNextHtmlTagStart($content, $offset);

			if ($tag_start === null) {
				if ($ignored_tag_stack === []) {
					array_push($results, ...self::scanTextNode(
						substr($content, $offset),
						$file,
						$format,
						$base_line + self::lineOffset($content, $offset)
					));
				}

				break;
			}

			if ($tag_start > $offset && $ignored_tag_stack === []) {
				array_push($results, ...self::scanTextNode(
					substr($content, $offset, $tag_start - $offset),
					$file,
					$format,
					$base_line + self::lineOffset($content, $offset)
				));
			}

			$comment_end = self::htmlCommentEnd($content, $tag_start);

			if ($comment_end !== null) {
				$offset = $comment_end;

				continue;
			}

			$tag_end = self::findTagEnd($content, $tag_start);

			if ($tag_end === false) {
				if ($ignored_tag_stack === []) {
					array_push($results, ...self::scanTextNode(
						substr($content, $tag_start),
						$file,
						$format,
						$base_line + self::lineOffset($content, $tag_start)
					));
				}

				break;
			}

			$tag_source = substr($content, $tag_start, $tag_end - $tag_start + 1);
			$tag = self::parseTag($tag_source);

			if ($tag !== null) {
				if ($tag['closing']) {
					self::popIgnoredTag($ignored_tag_stack, $tag['name']);
				} else {
					if ($ignored_tag_stack === []) {
						array_push($results, ...self::scanTagAttributes(
							$tag_source,
							$tag['name'],
							$file,
							$format,
							$base_line + self::lineOffset($content, $tag_start)
						));
					}

					if (!$tag['self_closing'] && in_array($tag['name'], self::IGNORED_TEXT_TAGS, true)) {
						$ignored_tag_stack[] = $tag['name'];
					}
				}
			}

			$offset = $tag_end + 1;
		}

		return $results;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function scanTextNode(string $source, string $file, string $format, int $base_line): array
	{
		$masked = self::maskTemplateSyntax($source);
		$literal = self::normalizeLiteral($masked);

		if (!self::isReportableLiteral($literal)) {
			return [];
		}

		return [[
			'file' => self::normalizePath($file),
			'line' => self::lineNumber($source, self::firstLiteralOffset($masked), $base_line),
			'format' => $format,
			'type' => 'text_node',
			'code' => 'hardcoded_ui_text',
			'severity' => 'warning',
			'literal' => $literal,
			'snippet' => self::snippet($literal),
		]];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function scanTagAttributes(
		string $tag_source,
		string $tag_name,
		string $file,
		string $format,
		int $base_line
	): array {
		if (
			!preg_match_all(
				'/\s([A-Za-z_:][A-Za-z0-9_:\-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/s',
				$tag_source,
				$matches,
				PREG_SET_ORDER | PREG_OFFSET_CAPTURE
			)
		) {
			return [];
		}

		$results = [];

		foreach ($matches as $match) {
			$attribute = strtolower((string) $match[1][0]);
			$allowed = in_array($attribute, self::TRANSLATABLE_ATTRIBUTES, true)
				|| ($attribute === 'value' && self::isVisibleValueAttribute($tag_source, $tag_name));

			if (!$allowed) {
				continue;
			}

			$value_match = null;

			foreach ([2, 3, 4] as $group_index) {
				if (isset($match[$group_index]) && $match[$group_index][1] >= 0) {
					$value_match = $match[$group_index];

					break;
				}
			}

			if ($value_match === null) {
				continue;
			}

			$value = (string) $value_match[0];
			$masked = self::maskTemplateSyntax($value);
			$literal = self::normalizeLiteral($masked);

			if (!self::isReportableLiteral($literal)) {
				continue;
			}

			$results[] = [
				'file' => self::normalizePath($file),
				'line' => self::lineNumber($tag_source, (int) $value_match[1] + self::firstLiteralOffset($masked), $base_line),
				'format' => $format,
				'type' => 'attribute',
				'code' => 'hardcoded_ui_attribute',
				'severity' => 'warning',
				'literal' => $literal,
				'tag' => $tag_name,
				'attribute' => $attribute,
				'snippet' => self::snippet($literal),
			];
		}

		return $results;
	}

	private static function isVisibleValueAttribute(string $tag_source, string $tag_name): bool
	{
		if ($tag_name !== 'input') {
			return false;
		}

		if (
			preg_match(
				'/\stype\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/i',
				$tag_source,
				$type_match
			) !== 1
		) {
			return false;
		}

		$type = strtolower(trim((string) ($type_match[1] ?: ($type_match[2] ?: ($type_match[3] ?? '')))));

		return in_array($type, self::VISIBLE_INPUT_VALUE_TYPES, true);
	}

	private static function findNextHtmlTagStart(string $content, int $offset): ?int
	{
		if (preg_match('/<!--|<!\[|<![A-Za-z]|<\s*\/?\s*[A-Za-z][A-Za-z0-9:_-]*/', $content, $match, PREG_OFFSET_CAPTURE, $offset) !== 1) {
			return null;
		}

		return (int) $match[0][1];
	}

	private static function htmlCommentEnd(string $content, int $tag_start): ?int
	{
		if (str_starts_with(substr($content, $tag_start, 4), '<!--')) {
			$end = strpos($content, '-->', $tag_start + 4);

			return $end === false ? null : $end + 3;
		}

		if (str_starts_with(substr($content, $tag_start, 3), '<![')) {
			$end = strpos($content, '-->', $tag_start + 3);

			return $end === false ? null : $end + 3;
		}

		return null;
	}

	private static function findTagEnd(string $content, int $tag_start): ?int
	{
		$quote = null;
		$length = strlen($content);

		for ($offset = $tag_start + 1; $offset < $length; $offset++) {
			$char = $content[$offset];

			if ($quote !== null) {
				if ($char === $quote) {
					$quote = null;
				}

				continue;
			}

			if ($char === '"' || $char === "'") {
				$quote = $char;

				continue;
			}

			if ($char === '>') {
				return $offset;
			}
		}

		return null;
	}

	/**
	 * @return array{name: string, closing: bool, self_closing: bool}|null
	 */
	private static function parseTag(string $tag_source): ?array
	{
		if (str_starts_with($tag_source, '<!--') || preg_match('/^<![A-Za-z]/', $tag_source) === 1) {
			return null;
		}

		if (preg_match('/^<\s*(\/?)\s*([A-Za-z][A-Za-z0-9:_-]*)\b/s', $tag_source, $match) !== 1) {
			return null;
		}

		return [
			'name' => strtolower($match[2]),
			'closing' => $match[1] === '/',
			'self_closing' => preg_match('/\/\s*>$/', $tag_source) === 1,
		];
	}

	/**
	 * @param list<string> $ignored_tag_stack
	 */
	private static function popIgnoredTag(array &$ignored_tag_stack, string $tag_name): void
	{
		for ($index = count($ignored_tag_stack) - 1; $index >= 0; $index--) {
			if ($ignored_tag_stack[$index] !== $tag_name) {
				continue;
			}

			array_splice($ignored_tag_stack, $index, 1);

			return;
		}
	}

	private static function maskTemplateSyntax(string $source): string
	{
		$patterns = [
			'/<\?(?:php|=)?[\s\S]*?\?>/i',
			'/\{\{--[\s\S]*?--\}\}/',
			'/(?<![A-Za-z0-9_])@php\b[\s\S]*?(?<![A-Za-z0-9_])@endphp\b/',
			'/\{!![\s\S]*?!!\}/',
			'/\{\{[\s\S]*?\}\}/',
			'/\{%[\s\S]*?%\}/',
			'/\{#[\s\S]*?#\}/',
			'/(?<![A-Za-z0-9_])@\w+(?:\s*\((?:[^()]|\([^()]*\))*\))?/',
		];

		foreach ($patterns as $pattern) {
			$source = preg_replace_callback(
				$pattern,
				static fn (array $match): string => self::preserveLineWhitespace((string) $match[0]),
				$source
			) ?? $source;
		}

		return $source;
	}

	private static function preserveLineWhitespace(string $source): string
	{
		return preg_replace('/[^\r\n]/', ' ', $source) ?? '';
	}

	private static function normalizeLiteral(string $literal): string
	{
		$literal = html_entity_decode($literal, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$literal = trim($literal);
		$literal = preg_replace('/\s+/u', ' ', $literal) ?? $literal;

		return trim($literal);
	}

	private static function isReportableLiteral(string $literal): bool
	{
		if ($literal === '' || strlen($literal) < 2) {
			return false;
		}

		if (in_array($literal, self::STANDALONE_LITERAL_ALLOWLIST, true)) {
			return false;
		}

		if (preg_match('/\p{L}/u', $literal) !== 1) {
			return false;
		}

		if (preg_match('/^[\d\s.,:+\-\/%()#]+$/u', $literal) === 1) {
			return false;
		}

		if (preg_match('/^[a-z][a-z0-9+.-]*:\/\/\S+$/i', $literal) === 1) {
			return false;
		}

		if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $literal) === 1) {
			return false;
		}

		if (preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $literal) === 1) {
			return false;
		}

		return true;
	}

	private static function firstLiteralOffset(string $source): int
	{
		if (preg_match('/\p{L}/u', $source, $match, PREG_OFFSET_CAPTURE) === 1) {
			return (int) $match[0][1];
		}

		return 0;
	}

	private static function lineNumber(string $source, int $relative_offset, int $base_line): int
	{
		return $base_line + substr_count(substr($source, 0, max(0, $relative_offset)), "\n");
	}

	private static function lineOffset(string $source, int $offset): int
	{
		return substr_count(substr($source, 0, max(0, $offset)), "\n");
	}

	private static function snippet(string $literal): string
	{
		if (mb_strlen($literal) <= 120) {
			return $literal;
		}

		return mb_substr($literal, 0, 117) . '...';
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
	 * @return list<string>
	 */
	private static function listTemplateFiles(string $root): array
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

			$file = self::normalizePath($file_info->getPathname());

			if (self::isExcludedPath($file)) {
				continue;
			}

			if (self::templateFormat($file) !== null) {
				$files[] = $file;
			}
		}

		sort($files);

		return $files;
	}

	private static function templateFormat(string $file): ?string
	{
		$file = str_replace('\\', '/', $file);

		if (str_ends_with($file, '.blade.php')) {
			return 'blade';
		}

		if (str_ends_with($file, '.twig')) {
			return 'twig';
		}

		if (str_ends_with($file, '.php') || str_ends_with($file, '.phtml')) {
			return 'php';
		}

		return null;
	}

	private static function isExcludedPath(string $file): bool
	{
		foreach (self::EXCLUDED_PATH_PARTS as $excluded_path_part) {
			if (str_contains($file, $excluded_path_part)) {
				return true;
			}
		}

		return false;
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
