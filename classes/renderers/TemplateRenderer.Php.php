<?php

declare(strict_types=1);

/**
 * Native PHP template renderer.
 *
 * Handles traditional PHP templates using include.
 * This is the default renderer maintaining 100% backward compatibility.
 */
class TemplateRendererPhp implements iTemplateRenderer
{
	private static ?Template $currentContext = null;

	public static function getFileExtension(): string
	{
		return '.php';
	}

	public static function getPriority(): int
	{
		return 0;  // Lowest priority - checked last to allow .blade.php and .twig to match first
	}

	/**
	 * Get the current template context (for global helper functions).
	 */
	public static function getCurrentContext(): ?Template
	{
		return self::$currentContext;
	}

	/**
	 * @param array<mixed> $props
	 */
	public static function render(string $templatePath, array $props, Template $templateContext): string
	{
		self::$currentContext = $templateContext;

		// Make template context available via $this in the included file
		// by binding the closure to the Template instance
		$renderFn = function (string $_templatePath, array $_props): string {
			// Extract props to local variables for template access
			extract($_props, EXTR_SKIP);

			Kernel::ob_start();
			include $_templatePath;
			$content = ob_get_contents();
			echo "\0";  // workaround for empty templates and ob_end_clean
			Kernel::ob_end_clean();

			return $content !== false ? $content : '';
		};

		// Bind the closure to the Template instance so $this works in templates
		$boundRenderFn = \Closure::bind($renderFn, $templateContext, Template::class);

		$result = $boundRenderFn($templatePath, $props);

		self::$currentContext = null;

		return $result;
	}
}
