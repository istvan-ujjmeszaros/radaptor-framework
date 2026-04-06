<?php

declare(strict_types=1);

/**
 * Shows documentation for one explicitly documented browser event.
 *
 * URL: ?context=events&event=show&slug=<context:event>
 * Accepts: ?format=json (default) or ?format=html
 */
class EventEventsShow extends AbstractEvent
{
	public function authorize(PolicyContext $policyContext): PolicyDecision
	{
		return PolicyDecision::allow();
	}

	public function run(): void
	{
		$format = Request::_GET('format', 'json', ['json', 'html']);
		$slug = trim((string) Request::_GET('slug', ''));

		if ($slug === '') {
			$this->_renderError($format, 400, 'EVENT_DOCS_SLUG_REQUIRED', 'Missing event docs slug.');

			return;
		}

		$metadata = BrowserEventDocsRegistry::getEventMeta($slug);

		if ($metadata === null) {
			$this->_renderError($format, 404, 'EVENT_DOCS_NOT_FOUND', 'Event documentation not found.');

			return;
		}

		if ($format === 'html') {
			header('Content-Type: text/html; charset=UTF-8');
			echo BrowserEventDocsHtmlRenderer::renderShow($metadata);

			return;
		}

		ApiResponse::renderSuccess($metadata);
	}

	private function _renderError(string $format, int $http_code, string $code_id, string $message): void
	{
		if ($format === 'html') {
			http_response_code($http_code);
			echo '<h1>' . $http_code . ' ' . e($message) . '</h1>';

			return;
		}

		http_response_code($http_code);
		ApiResponse::renderError($code_id, $message, $http_code);
	}
}
