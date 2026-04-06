<?php

class CLICommandBuildEventDocs extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Build browser event docs';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Regenerate the browser event documentation registry.

			Usage: radaptor build:event-docs

			Scans explicitly documented browser events and generates the docs registry cache.
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getRiskLevel(): string
	{
		return 'build';
	}

	public function run(): void
	{
		self::create();
	}

	public static function create(): void
	{
		$docs = [];

		foreach (AutoloaderFromGeneratedMap::getFilteredList('Event') as $short_name) {
			$class_name = 'Event' . $short_name;

			if (!class_exists($class_name)) {
				continue;
			}

			if (!is_subclass_of($class_name, iBrowserEventDocumentable::class)) {
				continue;
			}

			/** @var class-string<iBrowserEventDocumentable> $class_name */
			$meta = $class_name::describeBrowserEvent();
			$event_name = trim((string) $meta['event_name']);

			if ($event_name === '') {
				Kernel::abort("Missing event_name in browser event docs: {$class_name}");
			}

			$route = BrowserEventSlugHelper::eventNameToRouteParts($event_name);
			$slug = BrowserEventSlugHelper::eventNameToSlug($event_name);

			$meta['class'] = $class_name;
			$meta['slug'] = $slug;
			$meta['route'] = [
				'event_name' => $event_name,
				'context' => $route['context'],
				'event' => $route['event'],
				'query' => '?context=' . urlencode($route['context']) . '&event=' . urlencode($route['event']),
			];
			$meta['invocation'] = [
				'url_php' => "Url::getUrl('{$event_name}')",
				'template_helper' => "event_url('{$event_name}')",
				'ajax_helper' => "ajax_url('{$event_name}')",
				'ajax_helper_raw' => "ajax_url_raw('{$event_name}')",
			];
			$meta['notes'] = array_map('strval', $meta['notes'] ?? []);
			$meta['side_effects'] = array_map('strval', $meta['side_effects'] ?? []);

			$docs[$slug] = $meta;
		}

		ksort($docs);

		$cache_content = GeneratorHelper::fetchTemplate(
			'build_browser_event_docs',
			[
				'browser_event_docs_export' => GeneratorHelper::formatArrayForExport($docs),
			]
		);

		GeneratorHelper::writeGeneratedFile(
			DEPLOY_ROOT . ApplicationConfig::GENERATED_BROWSER_EVENT_DOCS_FILE,
			$cache_content
		);

		BrowserEventDocsRegistry::reset();
	}
}
