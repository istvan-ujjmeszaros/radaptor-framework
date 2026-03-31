<?php

/**
 * Interface for pluggable template rendering engines.
 *
 * Implementations handle specific template syntaxes (PHP, Blade, Twig).
 * The generator discovers renderers at build time and maps file extensions
 * to renderer classes in __template_renderers__.php for O(1) lookup.
 */
interface iTemplateRenderer
{
	/**
	 * Returns the file extension this renderer handles.
	 * Extensions are matched longest-first to resolve conflicts
	 * (e.g., '.blade.php' before '.php').
	 *
	 * @return string File extension including dot (e.g., '.php', '.blade.php', '.twig')
	 */
	public static function getFileExtension(): string;

	/**
	 * Returns priority for extension matching.
	 * Higher priority renderers are checked first.
	 * Use this to ensure '.blade.php' (priority 10) is checked before '.php' (priority 0).
	 *
	 * @return int Priority value (higher = checked first)
	 */
	public static function getPriority(): int;

	/**
	 * Renders the template and returns the output as a string.
	 *
	 * @param string $templatePath Absolute path to the template file
	 * @param array<mixed> $props Variables to pass to the template
	 * @param Template $templateContext The Template instance for accessing context (WebpageComposer, etc.)
	 * @return string Rendered HTML content
	 */
	public static function render(string $templatePath, array $props, Template $templateContext): string;
}
