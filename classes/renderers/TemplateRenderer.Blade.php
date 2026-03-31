<?php

declare(strict_types=1);

/**
 * Laravel Blade template renderer.
 *
 * Provides Blade templating engine support with custom directives for Radaptor:
 * - @library('BUNDLE_NAME') - Register a library bundle
 * - @widgetUrl('WidgetName') - Get URL where widget is placed
 * - @formUrl('FormName', $itemId, $referer, $extraParams) - Get URL for form
 * - @eventUrl('EventName', $params) - Get event URL (pre-escaped)
 * - @ajaxUrl('EventName', $params) - Get AJAX URL (pre-escaped)
 * - @modifyUrl($params) - Modify current URL (pre-escaped)
 *
 * Requires: composer require illuminate/view illuminate/filesystem
 */
class TemplateRendererBlade implements iTemplateRenderer
{
	private static ?\Illuminate\View\Factory $factory = null;
	private static ?Template $currentContext = null;

	public static function getFileExtension(): string
	{
		return '.blade.php';
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

		$factory = self::_getFactory();

		// Add the template's directory as a view location
		$templateDir = dirname($templatePath);
		$factory->addLocation($templateDir);

		// Render the template
		$content = $factory->file($templatePath, $props)->render();

		self::$currentContext = null;

		return $content;
	}

	/**
	 * Get the current template context (for custom directives).
	 */
	public static function getCurrentContext(): ?Template
	{
		return self::$currentContext;
	}

	private static function _getFactory(): \Illuminate\View\Factory
	{
		if (self::$factory !== null) {
			return self::$factory;
		}

		// Setup filesystem
		$files = new \Illuminate\Filesystem\Filesystem();

		// Setup view finder with default paths
		$viewPaths = [DEPLOY_ROOT];
		$finder = new \Illuminate\View\FileViewFinder($files, $viewPaths);

		// Setup Blade compiler with cache directory
		$cachePath = self::resolveCachePath();

		$compiler = new \Illuminate\View\Compilers\BladeCompiler($files, $cachePath);

		// Register custom directives
		self::_registerDirectives($compiler);

		// Create engine resolver
		$resolver = new \Illuminate\View\Engines\EngineResolver();

		$resolver->register('blade', function () use ($compiler, $files) {
			return new \Illuminate\View\Engines\CompilerEngine($compiler, $files);
		});

		$resolver->register('php', function () use ($files) {
			return new \Illuminate\View\Engines\PhpEngine($files);
		});

		// Create dispatcher (simple implementation)
		$dispatcher = new \Illuminate\Events\Dispatcher();

		// Create factory
		self::$factory = new \Illuminate\View\Factory($resolver, $finder, $dispatcher);

		return self::$factory;
	}

	private static function resolveCachePath(): string
	{
		$override_root = getenv('RADAPTOR_TEMPLATE_CACHE_ROOT');

		if (is_string($override_root) && trim($override_root) !== '') {
			$cachePath = rtrim(trim($override_root), '/') . '/views';
		} else {
			$cachePath = DEPLOY_ROOT . 'storage/framework/views';
		}

		if (!is_dir($cachePath)) {
			mkdir($cachePath, 0o775, true);
		}

		return $cachePath;
	}

	private static function _registerDirectives(\Illuminate\View\Compilers\BladeCompiler $compiler): void
	{
		// @library('BUNDLE_NAME') - Register a library
		$compiler->directive('library', function (string $expression): string {
			return "<?php TemplateRendererBlade::getCurrentContext()?->getRenderer()?->registerLibrary({$expression}); ?>";
		});

		// @widgetUrl('WidgetName') - Get URL where widget is placed (pre-escaped)
		$compiler->directive('widgetUrl', function (string $expression): string {
			return "<?php echo widget_url({$expression}); ?>";
		});

		// @formUrl('FormName', $itemId, $referer, $extraParams) - Get URL for form (pre-escaped)
		$compiler->directive('formUrl', function (string $expression): string {
			return "<?php echo form_url({$expression}); ?>";
		});

		// @escape($var) - HTML escape (explicit)
		$compiler->directive('escape', function (string $expression): string {
			return "<?php echo htmlspecialchars({$expression}, ENT_QUOTES | ENT_SUBSTITUTE); ?>";
		});

		// @eventUrl('EventName', $params) - Get event URL (pre-escaped)
		$compiler->directive('eventUrl', function (string $expression): string {
			return "<?php echo event_url({$expression}); ?>";
		});

		// @ajaxUrl('EventName', $params) - Get AJAX URL (pre-escaped)
		$compiler->directive('ajaxUrl', function (string $expression): string {
			return "<?php echo ajax_url({$expression}); ?>";
		});

		// @modifyUrl($params) - Modify current URL (pre-escaped)
		$compiler->directive('modifyUrl', function (string $expression): string {
			return "<?php echo modify_url({$expression}); ?>";
		});
	}
}
