<?php

declare(strict_types=1);

final class BrowserEventDocsRegistry
{
	/** @var array<string, array<string, mixed>>|null */
	private static ?array $_cache = null;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function getAllEvents(): array
	{
		if (self::$_cache !== null) {
			return self::$_cache;
		}

		$path = DEPLOY_ROOT . ApplicationConfig::GENERATED_BROWSER_EVENT_DOCS_FILE;

		if (!is_file($path)) {
			return self::$_cache = [];
		}

		$data = require $path;

		if (!is_array($data)) {
			return self::$_cache = [];
		}

		ksort($data);

		return self::$_cache = $data;
	}

	/**
	 * @return array<string, list<array<string, mixed>>>
	 */
	public static function getGroupedEvents(): array
	{
		$grouped = [];

		foreach (self::getAllEvents() as $meta) {
			$group = (string) ($meta['group'] ?? 'Other');

			if (!isset($grouped[$group])) {
				$grouped[$group] = [];
			}

			$grouped[$group][] = $meta;
		}

		foreach ($grouped as &$events) {
			usort(
				$events,
				static fn (array $left, array $right): int => strcmp((string) $left['slug'], (string) $right['slug'])
			);
		}
		unset($events);

		ksort($grouped);

		return $grouped;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function getEventMeta(string $slug): ?array
	{
		$all = self::getAllEvents();

		return $all[$slug] ?? null;
	}

	public static function reset(): void
	{
		self::$_cache = null;
	}
}
