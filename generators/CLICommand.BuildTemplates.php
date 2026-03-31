<?php

class CLICommandBuildTemplates extends AbstractCLICommand
{
	private static string $templateCacheFilename = '';
	private static string $testTemplateCacheFilename = '';
	private static string $themedTemplateCacheFilename = '';
	private static array $templateIndex = [];
	private static array $testTemplateIndex = [];
	private static array $themedTemplateIndex = [];
	private static array $templateRenderers = [];
	private static array $testTemplateRenderers = [];
	private static array $scannableDirectories = [];
	private static array $testTemplateDirectories = [];
	private static bool $followSymlinks = false;

	/**
	 * Supported template extensions in priority order (longest first).
	 * @var array<string, string> Extension => Renderer class
	 */
	private static array $supportedExtensions = [];

	/**
	 * File endings to scan (performance filter).
	 * Derived from supported extensions - only files ending with these are checked.
	 * @var array<string>
	 */
	private static array $scannableFileEndings = [];

	public function getName(): string
	{
		return 'Build templates';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Regenerate the template registry.

			Usage: radaptor build:templates

			Scans the codebase for template files and generates the template list cache.
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

	private static function _initConfig(): void
	{
		self::$templateCacheFilename = DEPLOY_ROOT . ApplicationConfig::GENERATED_TEMPLATES_FILE;
		self::$testTemplateCacheFilename = DEPLOY_ROOT . ApplicationConfig::GENERATED_TEST_TEMPLATES_FILE;
		self::$themedTemplateCacheFilename = DEPLOY_ROOT . ApplicationConfig::GENERATED_THEMED_TEMPLATES_FILE;
		self::$scannableDirectories = PackagePathHelper::getScannableRoots();
		self::$testTemplateDirectories = [DEPLOY_ROOT . 'tests/', ];

		// Load supported extensions from TemplateRenderers if available
		if (class_exists('TemplateRenderers')) {
			self::$supportedExtensions = TemplateRenderers::getExtensionMap();
		} else {
			// Fallback to PHP-only if renderer list not yet generated
			self::$supportedExtensions = ['.php' => 'TemplateRendererPhp'];
		}

		// Build scannable file endings for performance filter
		// Extract unique final extensions (e.g., .blade.php -> .php, .twig -> .twig)
		$endings = [];

		foreach (array_keys(self::$supportedExtensions) as $ext) {
			// Get last .xxx part
			$lastDot = strrpos($ext, '.');
			$ending = ($lastDot !== false) ? substr($ext, $lastDot) : $ext;
			$endings[$ending] = true;
		}

		self::$scannableFileEndings = array_keys($endings);
	}

	/**
	 * Extract theme name from file path.
	 * Matches: /themes/{ThemeName}/ or /templates-common/default-{ThemeName}/.
	 */
	private static function _extractThemeName(string $path): ?string
	{
		return PackageThemeScanHelper::extractThemeName($path);
	}

	/**
	 * Check if filename ends with any scannable extension (performance filter).
	 */
	private static function _hasScannableEnding(string $filename): bool
	{
		foreach (self::$scannableFileEndings as $ending) {
			if (str_ends_with($filename, $ending)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a filename matches a template pattern and extract info.
	 *
	 * @return array{name: string, extension: string, renderer: string}|null
	 */
	private static function _matchTemplateFile(string $filename, string $file_beginning): ?array
	{
		if (!str_starts_with($filename, $file_beginning . '.')) {
			return null;
		}

		// Check each supported extension (already in priority order - longest first)
		foreach (self::$supportedExtensions as $extension => $rendererClass) {
			if (str_ends_with($filename, $extension)) {
				$prefixLen = strlen($file_beginning . '.');
				$suffixLen = strlen($extension);
				$templateName = substr($filename, $prefixLen, -$suffixLen);

				if ($templateName !== '') {
					return [
						'name' => $templateName,
						'extension' => $extension,
						'renderer' => $rendererClass,
					];
				}
			}
		}

		return null;
	}

	/**
	 * @param array<string, string> $template_index
	 * @param array<string, string> $template_renderers
	 * @param array<string, string> $themed_template_index
	 */
	private static function _parseDir(
		string $dir_path,
		string $file_beginning,
		array &$template_index,
		array &$template_renderers,
		array &$themed_template_index,
		bool $respect_excluded_folders = true,
		bool $register_themed_templates = true
	): void {
		$dir_path = rtrim($dir_path, '/') . '/';

		if (PackagePathHelper::shouldSkipPath($dir_path)) {
			return;
		}

		if (PackageThemeScanHelper::shouldSkipPath($dir_path)) {
			return;
		}

		if ($respect_excluded_folders && GeneratorHelper::folderIsExcluded($dir_path)) {
			return;
		}

		if (is_dir($dir_path)) {
			if (($dir = opendir($dir_path))) {
				while (($file = readdir($dir)) !== false) {
					$file_path = $dir_path . $file;

					if ($file[0] !== '.') {
						switch (filetype($file_path)) {
							case 'dir':
								if ($file != "." && $file != "..") {
									if ($respect_excluded_folders && GeneratorHelper::folderIsExcluded($file_path)) {
										break;
									}

									self::_parseDir($file_path . '/', $file_beginning, $template_index, $template_renderers, $themed_template_index, $respect_excluded_folders, $register_themed_templates);
								}

								break;

							case 'link':
								if (self::$followSymlinks) {
									self::_parseDir($file_path . '/', $file_beginning, $template_index, $template_renderers, $themed_template_index, $respect_excluded_folders, $register_themed_templates);
								}

								break;

							case 'file':
								// Performance filter: skip files that don't have a scannable ending
								if (!self::_hasScannableEnding($file)) {
									break;
								}

								$match = self::_matchTemplateFile($file, $file_beginning);

								if ($match !== null) {
									$relativePath = PackagePathHelper::toStoragePath($file_path);
									$templateName = $match['name'];

									$template_index[$templateName] = $relativePath;
									$template_renderers[$templateName] = $match['renderer'];

									// Also register themed version if in a theme directory
									$themeName = $register_themed_templates ? self::_extractThemeName($file_path) : null;

									if ($themeName !== null) {
										$themedKey = "{$templateName}.{$themeName}";
										$themed_template_index[$themedKey] = $relativePath;
									}
								}

								break;
						}
					}
				}
			}
		}
	}

	public static function create(): void
	{
		PackageThemeScanHelper::reset();
		self::$templateIndex = [];
		self::$testTemplateIndex = [];
		self::$themedTemplateIndex = [];
		self::$templateRenderers = [];
		self::$testTemplateRenderers = [];
		self::$supportedExtensions = [];
		self::$scannableDirectories = [];
		self::$testTemplateDirectories = [];
		self::$scannableFileEndings = [];
		self::_initConfig();

		/** @var list<string> $scannable_directories */
		$scannable_directories = self::$scannableDirectories;

		/** @var list<string> $test_template_directories */
		$test_template_directories = self::$testTemplateDirectories;

		foreach ($scannable_directories as $dir) {
			self::_parseDir($dir, 'template', self::$templateIndex, self::$templateRenderers, self::$themedTemplateIndex);
		}

		$ignored_test_themed_templates = [];

		foreach ($test_template_directories as $dir) {
			self::_parseDir($dir, 'test_template', self::$testTemplateIndex, self::$testTemplateRenderers, $ignored_test_themed_templates, false, false);
		}

		/** @var array<string, string> $template_index */
		$template_index = self::$templateIndex;

		/** @var array<string, string> $template_renderers */
		$template_renderers = self::$templateRenderers;
		$upper_keys_array = [];

		foreach ($template_index as $key => $value) {
			$transformedKey = str_replace(['.', '-'], '_', mb_strtoupper((string) $key));
			$upper_keys_array[$transformedKey] = $value;
		}

		ksort($upper_keys_array);
		ksort(self::$templateIndex);
		ksort(self::$templateRenderers);

		$template_constants = [];

		foreach ($upper_keys_array as $key => $value) {
			$constName = str_replace(['.', '-'], '_', mb_strtoupper($key));
			$template_constants[$constName] = $value;
		}

		$cache_content = GeneratorHelper::fetchTemplate(
			'build_templates',
			[
				'template_list_class_name' => 'TemplateList',
				'template_constants' => $template_constants,
				'template_list_export' => GeneratorHelper::formatArrayForExport($template_index),
				'template_renderers_export' => GeneratorHelper::formatArrayForExport($template_renderers),
			]
		);

		if (($cacheFilename_handle = fopen(self::$templateCacheFilename, "w"))) {
			if (fwrite($cacheFilename_handle, str_replace("	", "	", $cache_content)) !== false) {
				echo "<b>template</b> leíró fájl sikeresen létrehozva!\n";
			} else {
				echo "Hiba történt az <b>template</b> leíró fájl írásakor!\n";
			}
		} else {
			echo "<span style=\"background-color:red;\">Ismeretlen hiba a template leíró készítése közben!</span>\n";
		}

		/** @var array<string, string> $test_template_index */
		$test_template_index = self::$testTemplateIndex;

		/** @var array<string, string> $test_template_renderers */
		$test_template_renderers = self::$testTemplateRenderers;
		$test_upper_keys_array = [];

		foreach ($test_template_index as $key => $value) {
			$transformedKey = str_replace(['.', '-'], '_', mb_strtoupper((string) $key));
			$test_upper_keys_array[$transformedKey] = $value;
		}

		ksort($test_upper_keys_array);
		ksort(self::$testTemplateIndex);
		ksort(self::$testTemplateRenderers);

		$test_template_constants = [];

		foreach ($test_upper_keys_array as $key => $value) {
			$constName = str_replace(['.', '-'], '_', mb_strtoupper($key));
			$test_template_constants[$constName] = $value;
		}

		$test_cache_content = GeneratorHelper::fetchTemplate(
			'build_templates',
			[
				'template_list_class_name' => 'TestTemplateList',
				'template_constants' => $test_template_constants,
				'template_list_export' => GeneratorHelper::formatArrayForExport($test_template_index),
				'template_renderers_export' => GeneratorHelper::formatArrayForExport($test_template_renderers),
			]
		);

		if (($testCacheFilename_handle = fopen(self::$testTemplateCacheFilename, "w"))) {
			if (fwrite($testCacheFilename_handle, str_replace("	", "	", $test_cache_content)) !== false) {
				echo "<b>test_templates</b> leíró fájl sikeresen létrehozva!\n";
			} else {
				echo "Hiba történt a <b>test_templates</b> leíró fájl írásakor!\n";
			}
		} else {
			echo "<span style=\"background-color:red;\">Ismeretlen hiba a test_templates leíró készítése közben!</span>\n";
		}

		// Generate themed templates file
		ksort(self::$themedTemplateIndex);

		$themed_cache_content = GeneratorHelper::fetchTemplate(
			'build_themed_templates',
			[
				'themed_template_list_export' => GeneratorHelper::formatArrayForExport(self::$themedTemplateIndex),
			]
		);

		if (($themedCacheFilename_handle = fopen(self::$themedTemplateCacheFilename, "w"))) {
			if (fwrite($themedCacheFilename_handle, str_replace("	", "	", $themed_cache_content)) !== false) {
				echo "<b>themed_templates</b> leíró fájl sikeresen létrehozva!\n";
			} else {
				echo "Hiba történt a <b>themed_templates</b> leíró fájl írásakor!\n";
			}
		} else {
			echo "<span style=\"background-color:red;\">Ismeretlen hiba a themed_templates leíró készítése közben!</span>\n";
		}
	}
}
