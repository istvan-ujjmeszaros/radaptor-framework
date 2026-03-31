<?php
/**
 * @var string $template_list_class_name
 * @var array<string, string> $template_constants
 * @var string $template_list_export
 * @var string $template_renderers_export
 */
?>
class <?= $template_list_class_name ?>
{
<?php foreach ($template_constants as $name => $value) { ?>
	public const string <?= $name ?> = <?= var_export($value, true) ?>;
<?php } ?>

	/**
	 * Template name to relative path mapping.
	 * @var array<string, string>
	 */
	protected static array $_templateList = <?= $template_list_export ?>;

	public static function hasTemplate(string $templateName): bool
	{
		return isset(self::$_templateList[$templateName]);
	}

	public static function getPathForTemplate(string $templateName): string
	{
		return self::$_templateList[$templateName] ?? '';
	}

	/**
	 * Template name to renderer class mapping.
	 * @var array<string, class-string<iTemplateRenderer>>
	 */
	protected static array $_templateRenderers = <?= $template_renderers_export ?>;

	/**
	 * Get the renderer class for a template.
	 *
	 * @param string $templateName Template name
	 * @return class-string<iTemplateRenderer> Renderer class name
	 */
	public static function getRendererForTemplate(string $templateName): string
	{
		return self::$_templateRenderers[$templateName] ?? 'TemplateRendererPhp';
	}
}
