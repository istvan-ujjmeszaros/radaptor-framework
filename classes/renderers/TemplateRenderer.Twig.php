<?php

declare(strict_types=1);

/**
 * Twig template renderer.
 *
 * Provides Twig templating engine support with custom functions for Radaptor:
 * - library('BUNDLE_NAME') - Register a library bundle
 * - widget_url('WidgetName') - Get URL where widget is placed
 * - form_url('FormName', itemId, referer, extraParams) - Get URL for form
 * - event_url('EventName', params) - Get event URL (pre-escaped)
 * - ajax_url('EventName', params) - Get AJAX URL (pre-escaped)
 * - modify_url(params) - Modify current URL (pre-escaped)
 *
 * Requires: composer require twig/twig
 */
class TemplateRendererTwig implements iTemplateRenderer
{
	private static ?Template $currentContext = null;

	public static function getFileExtension(): string
	{
		return '.twig';
	}

	public static function getPriority(): int
	{
		return 10;  // Higher priority - checked before .php
	}

	/**
	 * @param array<mixed> $props
	 */
	public static function render(string $templatePath, array $props, Template $templateContext): string
	{
		self::$currentContext = $templateContext;

		$twig = self::_getTwig($templatePath);

		// Convert absolute path to relative for Twig loader
		// The loader is configured with the template's directory
		$templateName = basename($templatePath);
		$template = $twig->load($templateName);

		// Add template context to props so templates can access it
		$props['_template'] = $templateContext;

		$content = $template->render($props);

		self::$currentContext = null;

		return $content;
	}

	/**
	 * Get the current template context (for custom functions).
	 */
	public static function getCurrentContext(): ?Template
	{
		return self::$currentContext;
	}

	/**
	 * Get Twig environment configured for the given template's directory.
	 * We need to recreate the environment for each render to support different directories.
	 */
	private static function _getTwig(string $templatePath): \Twig\Environment
	{
		$templateDir = dirname($templatePath);

		// Setup loader with the template's directory
		$loader = new \Twig\Loader\FilesystemLoader([$templateDir]);

		// Setup cache directory
		$cachePath = self::resolveCachePath();

		// Create Twig environment
		$twig = new \Twig\Environment($loader, [
			'cache' => $cachePath,
			'auto_reload' => true,
			'strict_variables' => false,
		]);

		// Register custom functions
		self::_registerFunctions($twig);

		return $twig;
	}

	private static function resolveCachePath(): string
	{
		$override_root = getenv('RADAPTOR_TEMPLATE_CACHE_ROOT');

		if (is_string($override_root) && trim($override_root) !== '') {
			$cachePath = rtrim(trim($override_root), '/') . '/twig';
		} else {
			$cachePath = DEPLOY_ROOT . 'storage/framework/twig';
		}

		if (!is_dir($cachePath)) {
			mkdir($cachePath, 0o775, true);
		}

		return $cachePath;
	}

	private static function _registerFunctions(\Twig\Environment $twig): void
	{
		// library('BUNDLE_NAME') - Register a library
		$twig->addFunction(new \Twig\TwigFunction('library', function (string $bundleName) {
			$context = TemplateRendererTwig::getCurrentContext();

			if ($context !== null) {
				$context->getRenderer()?->registerLibrary($bundleName);
			}
		}));

		// widget_url('WidgetName') - Get URL where widget is placed
		$twig->addFunction(new \Twig\TwigFunction('widget_url', function (string $widgetName): string {
			return widget_url($widgetName);
		}, ['is_safe' => ['html']]));

		// form_url('FormName', itemId, referer, extraParams) - Get URL for form
		$twig->addFunction(new \Twig\TwigFunction('form_url', function (
			string $formId,
			?int $itemId = null,
			?string $referer = null,
			array $extraParams = []
		): string {
			return form_url($formId, $itemId, $referer, $extraParams);
		}, ['is_safe' => ['html']]));

		// escape_html($var) - HTML escape (Twig has built-in |escape, but this matches PHP convention)
		$twig->addFunction(new \Twig\TwigFunction('escape_html', function (string $value): string {
			return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE);
		}));

		// event_url('EventName', params) - Get event URL (pre-escaped)
		$twig->addFunction(new \Twig\TwigFunction('event_url', function (
			string $eventName = '',
			array $customparams = []
		): string {
			return event_url($eventName, $customparams);
		}, ['is_safe' => ['html']]));

		// ajax_url('EventName', params) - Get AJAX URL (pre-escaped)
		$twig->addFunction(new \Twig\TwigFunction('ajax_url', function (
			string $eventName = '',
			array $customparams = []
		): string {
			return ajax_url($eventName, $customparams);
		}, ['is_safe' => ['html']]));

		// modify_url(params) - Modify current URL (pre-escaped)
		$twig->addFunction(new \Twig\TwigFunction('modify_url', function (
			array $params
		): string {
			return modify_url($params);
		}, ['is_safe' => ['html']]));

		// t('key', params) - Translate string from i18n catalog
		$twig->addFunction(new \Twig\TwigFunction('t', function (
			string $key,
			array $params = []
		): string {
			return t($key, $params);
		}));

		// registerI18n('key') or registerI18n(['key1', 'key2']) - Register keys for window.__i18n
		$twig->addFunction(new \Twig\TwigFunction('registerI18n', function (string|array $keys): void {
			$context = TemplateRendererTwig::getCurrentContext();
			$context?->getRenderer()?->registerI18n($keys);
		}));
	}
}
