<?php

declare(strict_types=1);

/**
 * Lists all explicitly documented browser events.
 *
 * URL: ?context=events&event=index
 * Accepts: ?format=json (default) or ?format=html
 */
class EventEventsIndex extends AbstractEvent
{
	public function authorize(PolicyContext $policyContext): PolicyDecision
	{
		return PolicyDecision::allow();
	}

	public function run(): void
	{
		$format = Request::_GET('format', 'json', ['json', 'html']);
		$grouped = BrowserEventDocsRegistry::getGroupedEvents();

		if ($format === 'html') {
			header('Content-Type: text/html; charset=UTF-8');
			echo BrowserEventDocsHtmlRenderer::renderIndex($grouped);
		} else {
			ApiResponse::renderSuccess([
				'total' => count(BrowserEventDocsRegistry::getAllEvents()),
				'groups' => $grouped,
			]);
		}
	}
}
