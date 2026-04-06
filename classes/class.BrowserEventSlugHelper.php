<?php

class BrowserEventSlugHelper
{
	/** @var array<string, string>|null */
	private static ?array $_eventShortNameMap = null;

	/**
	 * @return array{context: string, event: string, slug: string}
	 */
	public static function shortNameToParts(string $short_name): array
	{
		$words = preg_split('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', $short_name);

		if ($words === false || count($words) === 0) {
			$context = strtolower($short_name);

			return [
				'context' => $context,
				'event' => '',
				'slug' => $context,
			];
		}

		$context = strtolower($words[0]);
		$event_words = array_slice($words, 1);
		$event = strtolower(implode('-', $event_words));

		return [
			'context' => $context,
			'event' => $event,
			'slug' => $event !== '' ? $context . ':' . $event : $context,
		];
	}

	public static function shortNameToSlug(string $short_name): string
	{
		return self::shortNameToParts($short_name)['slug'];
	}

	public static function eventNameToSlug(string $event_name): string
	{
		$parts = self::eventNameToRouteParts($event_name);

		return $parts['context'] . ':' . $parts['event'];
	}

	public static function slugToEventName(string $slug): ?string
	{
		$parts = self::splitSlug($slug);

		if ($parts === null) {
			return null;
		}

		return $parts['context'] . '.' . $parts['event'];
	}

	/**
	 * @return array{context: string, event: string}
	 */
	public static function eventNameToRouteParts(string $event_name): array
	{
		$event_name = trim($event_name);
		$parts = explode('.', $event_name);

		if (count($parts) !== 2) {
			throw new InvalidArgumentException('Browser event name must be in "context.event" format.');
		}

		$context = trim((string) $parts[0]);
		$event = trim((string) $parts[1]);

		if ($context === '' || $event === '') {
			throw new InvalidArgumentException('Browser event name must contain non-empty context and event parts.');
		}

		return [
			'context' => $context,
			'event' => $event,
		];
	}

	public static function slugToShortName(string $context, string $event): string
	{
		$slug = trim($context) . ':' . trim($event);
		$map = self::_getEventShortNameMap();

		if (isset($map[$slug])) {
			return $map[$slug];
		}

		return self::_toPascalCase($context) . self::_toPascalCase($event);
	}

	/**
	 * @return array{context: string, event: string}|null
	 */
	public static function splitSlug(string $slug): ?array
	{
		$slug = trim($slug);

		if ($slug === '') {
			return null;
		}

		$parts = explode(':', $slug);

		if (count($parts) !== 2) {
			return null;
		}

		$context = trim((string) $parts[0]);
		$event = trim((string) $parts[1]);

		if ($context === '' || $event === '') {
			return null;
		}

		return [
			'context' => $context,
			'event' => $event,
		];
	}

	private static function _toPascalCase(string $slug): string
	{
		$words = preg_split('/[-_]/', $slug);
		$result = '';

		foreach ($words as $word) {
			if ($word === '') {
				continue;
			}

			$result .= strtoupper($word[0]) . substr($word, 1);
		}

		return $result;
	}

	/**
	 * @return array<string, string>
	 */
	private static function _getEventShortNameMap(): array
	{
		if (self::$_eventShortNameMap !== null) {
			return self::$_eventShortNameMap;
		}

		$map = [];

		foreach (AutoloaderFromGeneratedMap::getFilteredList('Event') as $short_name) {
			$map[self::shortNameToSlug($short_name)] = $short_name;
		}

		return self::$_eventShortNameMap = $map;
	}
}
