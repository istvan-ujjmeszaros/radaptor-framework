<?php

/**
 * View/template helper functions.
 *
 * These helpers are available globally for use in templates and views.
 */

// Conditional: Laravel's illuminate/support (pulled in by Blade) already defines e()
// See: vendor/illuminate/support/helpers.php
if (!function_exists('e')) {
	/**
	 * Escape HTML special characters.
	 *
	 * Shorthand for htmlspecialchars() with secure defaults.
	 * Industry-standard naming from Laravel.
	 *
	 * @param string|null $value The string to escape
	 * @return string The escaped string
	 */
	function e(?string $value): string
	{
		return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}

/**
 * Register a library bundle from a template.
 *
 * Works across all template engines (PHP, Blade, Twig).
 * Equivalent to $this->registerLibrary() in PHP templates.
 *
 * @param string $bundleName The library bundle constant name (e.g., 'JQUERY', 'DATATABLES')
 */
function library(string $bundleName): void
{
	// Try each renderer's context (only one will be active at a time)
	$context = TemplateRendererPhp::getCurrentContext()
		?? TemplateRendererBlade::getCurrentContext()
		?? TemplateRendererTwig::getCurrentContext();

	$context?->getRenderer()?->registerLibrary($bundleName);
}

/**
 * Get the URL where a widget is placed.
 *
 * @param string $widgetName Widget class name (e.g., 'UserList', 'ResourceTree')
 * @return string URL path or empty string if not found
 */
function widget_url(string $widgetName): string
{
	$pageId = ResourceTypeWebpage::findWebpageIdWithWidget($widgetName);

	if ($pageId === 0) {
		return '';
	}

	return e(Url::getSeoUrl($pageId) ?? '');
}

/**
 * Get the URL where a form is placed, with optional item ID and extra params.
 *
 * @param string $formId Form class name (e.g., 'User', 'Company')
 * @param int|null $itemId Optional item ID for edit mode
 * @param string|null $referer Optional referer URL
 * @param array<string, mixed> $extraParams Additional query parameters
 * @return string URL path with parameters (pre-escaped for HTML)
 */
function form_url(string $formId, ?int $itemId = null, ?string $referer = null, array $extraParams = []): string
{
	return e(Form::getSeoUrl($formId, $itemId, $referer, $extraParams));
}

/**
 * Get URL for an event (pre-escaped for HTML).
 *
 * Shorthand for Url::getUrl() with HTML escaping for safe template use.
 *
 * @param string $eventName Event name in format 'context.event' (e.g., 'companies.ajaxList')
 * @param array<string, mixed> $customparams Additional query parameters
 * @return string URL path with parameters (pre-escaped for HTML)
 */
function event_url(string $eventName = '', array $customparams = []): string
{
	return e(Url::getUrl($eventName, $customparams));
}

/**
 * Get AJAX URL for an event (pre-escaped for HTML).
 *
 * Use this in HTML attributes: <a href="<?= ajax_url('...') ?>">
 * For JavaScript, use ajax_url_raw() instead.
 *
 * @param string $eventName Event name in format 'context.event' (e.g., 'companies.ajaxList')
 * @param array<string, mixed> $customparams Additional query parameters
 * @return string URL path with parameters (pre-escaped for HTML)
 */
function ajax_url(string $eventName = '', array $customparams = []): string
{
	return e(Url::getAjaxUrl($eventName, $customparams));
}

/**
 * Get AJAX URL for an event (raw, not escaped).
 *
 * Use this in JavaScript: ajax: "<?= ajax_url_raw('...') ?>"
 * For HTML attributes, use ajax_url() instead (pre-escaped).
 *
 * @param string $eventName Event name in format 'context.event' (e.g., 'companies.ajaxList')
 * @param array<string, mixed> $customparams Additional query parameters
 * @return string URL path with parameters (NOT escaped)
 */
function ajax_url_raw(string $eventName = '', array $customparams = []): string
{
	return Url::getAjaxUrl($eventName, $customparams);
}

/**
 * Modify current URL with new params (pre-escaped for HTML).
 *
 * Shorthand for Url::modifyCurrentUrl() with HTML escaping for safe template use.
 *
 * @param array<string, mixed> $params Parameters to add/modify in the current URL
 * @return string Modified URL (pre-escaped for HTML)
 */
function modify_url(array $params): string
{
	return e(Url::modifyCurrentUrl($params));
}

/**
 * Translate a string by key from the compiled i18n catalog.
 *
 * Falls back: current locale → en_US → key slug.
 *
 * @param string $key    Dot-separated key (e.g. 'common.save', 'workers.list.title')
 * @param array<string, mixed> $params Named parameters for interpolation (e.g. ['name' => 'John'])
 * @return string Translated text, or the key if not found
 */
function t(string $key, array $params = []): string
{
	return I18nRuntime::t($key, $params);
}

/**
 * Register i18n keys for JS emission via window.__i18n.
 *
 * Works like library() — keys are aggregated by the page composer and
 * emitted as a single <script> block in fetchClosingHtml().
 *
 * @param string|array<string> $keys Single key or list of keys to register
 */
function registerI18n(string|array $keys): void
{
	$context = TemplateRendererPhp::getCurrentContext()
		?? TemplateRendererBlade::getCurrentContext()
		?? TemplateRendererTwig::getCurrentContext();

	$context?->getRenderer()?->registerI18n($keys);
}
