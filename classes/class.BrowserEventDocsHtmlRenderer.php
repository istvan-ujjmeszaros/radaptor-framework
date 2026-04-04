<?php

declare(strict_types=1);

final class BrowserEventDocsHtmlRenderer
{
	/**
	 * @param array<string, list<array<string, mixed>>> $grouped
	 */
	public static function renderIndex(array $grouped): string
	{
		$template = new Template('browserEventDocs.index');
		$template->props['grouped'] = $grouped;
		$template->props['total'] = count(BrowserEventDocsRegistry::getAllEvents());

		return $template->fetch();
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	public static function renderShow(array $meta): string
	{
		$template = new Template('browserEventDocs.show');
		$template->props['meta'] = $meta;

		return $template->fetch();
	}
}
