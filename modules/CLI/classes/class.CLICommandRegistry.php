<?php

declare(strict_types=1);

/**
 * Discovers and catalogs CLI commands available for web execution.
 *
 * Uses the generated autoloader map to find all CLICommand classes,
 * instantiates them to read their metadata, and groups them by category.
 */
class CLICommandRegistry
{
	/** @var array<string, array<string, mixed>>|null */
	private static ?array $_webCache = null;

	/** @var array<string, array<string, mixed>>|null */
	private static ?array $_allCache = null;

	/**
	 * Get all web-runnable commands grouped by category.
	 *
	 * @return array<string, list<array{slug: string, name: string, docs: string, params: list<array<string, mixed>>, risk_level: string, timeout: int}>>
	 */
	public static function getGroupedCommands(): array
	{
		return self::_groupCommands(self::_getAllCommands(true));
	}

	/**
	 * Get all CLI commands grouped by category, including non-web-runnable ones.
	 *
	 * @return array<string, list<array{slug: string, name: string, docs: string, params: list<array<string, mixed>>, risk_level: string, timeout: int}>>
	 */
	public static function getAllGroupedCommands(): array
	{
		return self::_groupCommands(self::_getAllCommands(false));
	}

	/**
	 * Get metadata for a single command by slug.
	 *
	 * @return array{slug: string, name: string, docs: string, params: list<array<string, mixed>>, risk_level: string, timeout: int, category: string}|null
	 */
	public static function getCommandMeta(string $slug): ?array
	{
		$all = self::_getAllCommands(true);

		return $all[$slug] ?? null;
	}

	/**
	 * Get metadata for any CLI command, regardless of web availability.
	 *
	 * @return array{slug: string, name: string, docs: string, params: list<array<string, mixed>>, risk_level: string, timeout: int, category: string}|null
	 */
	public static function getAnyCommandMeta(string $slug): ?array
	{
		$all = self::_getAllCommands(false);

		return $all[$slug] ?? null;
	}

	/**
	 * Resolve command metadata by short class name suffix, e.g. "WebpageList".
	 *
	 * @return array{slug: string, name: string, docs: string, params: list<array<string, mixed>>, risk_level: string, timeout: int, category: string}|null
	 */
	public static function getAnyCommandMetaByShortName(string $short_name): ?array
	{
		return self::getAnyCommandMeta(self::shortNameToSlug($short_name));
	}

	/**
	 * Check whether a command slug is web-runnable.
	 */
	public static function isWebRunnable(string $slug): bool
	{
		return self::getCommandMeta($slug) !== null;
	}

	/**
	 * Convert a PascalCase short name to a CLI slug.
	 *
	 * e.g. "DbSchema" → "db:schema", "I18nTmReindex" → "i18n:tm-reindex",
	 *      "BuildAutoloader" → "build:autoloader"
	 *
	 * The convention is: the first PascalCase word is the context, the rest is the command.
	 * Multi-word segments are hyphenated in the slug.
	 */
	public static function shortNameToSlug(string $short_name): string
	{
		// Split PascalCase into words, preserving numeric sequences like "18"
		$words = preg_split('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', $short_name);

		if ($words === false || count($words) === 0) {
			return strtolower($short_name);
		}

		// The context is the first word (lowercased).
		// Special case: "I18n" should stay as "i18n" not "i-18-n"
		$context = strtolower($words[0]);
		$command_words = array_slice($words, 1);

		if (empty($command_words)) {
			return $context;
		}

		$command = strtolower(implode('-', $command_words));

		return $context . ':' . $command;
	}

	/**
	 * @return array<string, array{slug: string, name: string, docs: string, params: list<array<string, mixed>>, risk_level: string, timeout: int, category: string}>
	 */
	private static function _groupCommands(array $all): array
	{
		$grouped = [];

		foreach ($all as $slug => $meta) {
			$category = $meta['category'];

			if (!isset($grouped[$category])) {
				$grouped[$category] = [];
			}

			$grouped[$category][] = [
				'slug' => $slug,
				'name' => $meta['name'],
				'docs' => $meta['docs'],
				'params' => $meta['params'],
				'risk_level' => $meta['risk_level'],
				'timeout' => $meta['timeout'],
			];
		}

		ksort($grouped);

		return $grouped;
	}

	/**
	 * @return array<string, array{slug: string, name: string, docs: string, params: list<array<string, mixed>>, risk_level: string, timeout: int, category: string}>
	 */
	private static function _getAllCommands(bool $web_only): array
	{
		if ($web_only && self::$_webCache !== null) {
			return self::$_webCache;
		}

		if (!$web_only && self::$_allCache !== null) {
			return self::$_allCache;
		}

		$cache = [];

		$short_names = AutoloaderFromGeneratedMap::getFilteredList('CLICommand');

		foreach ($short_names as $short_name) {
			$class_name = 'CLICommand' . $short_name;

			if (!class_exists($class_name)) {
				continue;
			}

			if (!is_subclass_of($class_name, AbstractCLICommand::class)) {
				continue;
			}

			$command = new $class_name();

			if ($web_only && !$command->isWebRunnable()) {
				continue;
			}

			$slug = self::shortNameToSlug($short_name);
			$parts = explode(':', $slug);
			$category = $parts[0] ?? 'other';

			$cache[$slug] = [
				'slug' => $slug,
				'name' => $command->getName(),
				'docs' => $command->getDocs(),
				'params' => $command->getWebParams(),
				'risk_level' => $command->getRiskLevel(),
				'timeout' => $command->getWebTimeout(),
				'category' => $category,
			];
		}

		ksort($cache);

		if ($web_only) {
			self::$_webCache = $cache;
		} else {
			self::$_allCache = $cache;
		}

		return $cache;
	}
}
