<?php
/**
 * @var string $themed_template_list_export
 */
?>
class ThemedTemplateList
{
	/**
	 * Themed template mappings: 'templateName.ThemeName' => 'path/to/template.php'
	 * @var array<string, string>
	 */
	protected static array $_themedTemplateList = <?= $themed_template_list_export ?>;

	public static function getThemedTemplatePath(string $templateName, string $themeName): ?string
	{
		$key = "{$templateName}.{$themeName}";
		return self::$_themedTemplateList[$key] ?? null;
	}

	/**
	 * Reverse lookup: find the key for a given path (for debug info).
	 */
	public static function getKeyForPath(string $path): ?string
	{
		$key = array_search($path, self::$_themedTemplateList, true);
		return $key !== false ? $key : null;
	}

	/**
	 * Find all themes that have a specific template.
	 *
	 * @param string $templateName The template name without theme suffix
	 * @return string[] Array of theme names that have this template
	 */
	public static function getThemesForTemplate(string $templateName): array
	{
		$themes = [];
		$prefix = $templateName . '.';

		foreach (self::$_themedTemplateList as $key => $path) {
			if (str_starts_with($key, $prefix)) {
				$themes[] = substr($key, strlen($prefix));
			}
		}

		return $themes;
	}
}
