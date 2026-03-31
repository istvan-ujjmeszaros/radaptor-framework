<?php

/**
 * Generator for template renderer registry.
 *
 * Scans autoloader for classes implementing iTemplateRenderer and generates
 * __template_renderers__.php with extension-to-renderer mappings.
 *
 * Extensions are sorted by priority (highest first) to ensure proper matching
 * (e.g., '.blade.php' is checked before '.php').
 */
class CLICommandBuildTemplateRenderers extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Build template renderers';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Regenerate the template renderer registry.

			Usage: radaptor build:templateRenderers

			Scans for classes implementing iTemplateRenderer and generates
			extension-to-renderer mappings.
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
		$rendererCacheFilename = DEPLOY_ROOT . ApplicationConfig::GENERATED_TEMPLATE_RENDERERS_FILE;

		// Find all classes implementing iTemplateRenderer
		$autoloadMap = AutoloaderGeneratedMap::getAutoloadMap();

		/** @var list<array{class: string, extension: string, priority: int}> $renderers */
		$renderers = [];

		foreach ($autoloadMap as $className => $path) {
			// Skip non-renderer classes
			if (!str_starts_with($className, 'TemplateRenderer')) {
				continue;
			}

			// Check if class implements iTemplateRenderer
			if (!class_exists($className)) {
				require_once DEPLOY_ROOT . $path;
			}

			if (!class_exists($className)) {
				continue;
			}

			$reflection = new ReflectionClass($className);

			if (!$reflection->implementsInterface('iTemplateRenderer')) {
				continue;
			}

			// Get extension and priority from the class
			$extension = $className::getFileExtension();
			$priority = $className::getPriority();

			$renderers[] = [
				'class' => $className,
				'extension' => $extension,
				'priority' => $priority,
			];
		}

		// Sort by priority descending (highest first)
		$priorities = array_column($renderers, 'priority');
		array_multisort($priorities, SORT_DESC, $renderers);

		// Build extension-to-renderer map
		$extensionMap = [];

		foreach ($renderers as $renderer) {
			$extensionMap[$renderer['extension']] = $renderer['class'];
		}

		// Build ordered extension list (for matching in correct order)
		$extensionOrder = array_column($renderers, 'extension');

		$cache_content = GeneratorHelper::fetchTemplate(
			'build_template_renderers',
			[
				'extension_map' => $extensionMap,
				'extension_order' => $extensionOrder,
			]
		);

		GeneratorHelper::writeGeneratedFile($rendererCacheFilename, $cache_content);
	}
}
