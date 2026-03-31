<?php

class CLICommandBuildWidgets extends AbstractCLICommand
{
	private static string $widgetCacheFilename = '';
	private static array $_widgetIndex = [];
	private static array $scannableDirectories = [];
	private static bool $followSymlinks = false;
	private static array $scannableFileEndings = ['.php'];
	private static string $widgetFileBeginning = 'Widget';
	private static string $widgetFileEnding = 'php';

	public function getName(): string
	{
		return 'Build widgets';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Regenerate the widget registry.

			Usage: radaptor build:widgets

			Scans the codebase for Widget.*.php files and generates the widget list cache.
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
		self::$scannableDirectories = PackagePathHelper::getScannableRoots();
	}

	private static function _parseDir(string $dir_path): void
	{
		$dir_path = rtrim($dir_path, '/') . '/';

		if (PackagePathHelper::shouldSkipPath($dir_path)) {
			return;
		}

		if (PackageThemeScanHelper::shouldSkipPath($dir_path)) {
			return;
		}

		if (GeneratorHelper::folderIsExcluded($dir_path)) {
			return;
		}

		//		$dir_path = preg_replace('{/$}', '', $dir_path);
		if (is_dir($dir_path)) {
			if (($dir = opendir($dir_path))) {
				while (($file = readdir($dir)) !== false) {
					$file_path = $dir_path . $file;

					if ($file[0] !== '.') {
						switch (filetype($file_path)) {
							case 'dir':

								if ($file != "." && $file != "..") {
									if (GeneratorHelper::folderIsExcluded($file_path)) {
										break;
									}

									self::_parseDir($file_path . '/');
								}

								break;

							case 'link':

								if (self::$followSymlinks) {
									self::_parseDir($file_path . '/');
								}

								break;

							case 'file':
								if (!count(self::$scannableFileEndings) || in_array(mb_substr($file, mb_strrpos($file, '.')), self::$scannableFileEndings)) {
									if (($php_file = fopen($file_path, "r"))) {
										$size = filesize($file_path);

										if ($size > 0 && fread($php_file, $size)) {
											// ha event.valami.php akkor
											$exploded_file_name = explode('.', $file);

											if ((count($exploded_file_name) == 3) && ($exploded_file_name[0] == self::$widgetFileBeginning) && ($exploded_file_name[2] == self::$widgetFileEnding)) {
												$widgetName = $exploded_file_name[1];
												self::$_widgetIndex[$widgetName] = $file_path;
											}
										}
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
		self::$widgetCacheFilename = DEPLOY_ROOT . ApplicationConfig::GENERATED_WIDGETS_FILE;
		self::$_widgetIndex = [];
		self::$scannableDirectories = [];
		self::_initConfig();

		/** @var list<string> $scannable_directories */
		$scannable_directories = self::$scannableDirectories;

		foreach ($scannable_directories as $dir) {
			self::_parseDir($dir);
		}

		/** @var array<string, string> $widget_index */
		$widget_index = self::$_widgetIndex;
		$sorted_widget_array = [];

		foreach ($widget_index as $key => $value) {
			$sorted_widget_array[mb_strtoupper($key)] = $key;
		}

		ksort($sorted_widget_array);

		self::$_widgetIndex = $sorted_widget_array;

		/** @var array<string, string> $widget_index */
		$widget_index = self::$_widgetIndex;

		$constants = [];
		$widget_names = [];

		foreach ($widget_index as $widget_upper_name => $widget) {
			$constants[$widget_upper_name] = $widget;
			$widget_names[] = $widget;
		}

		$cache_content = GeneratorHelper::fetchTemplate(
			'build_widgets',
			[
				'widget_constants' => $constants,
				'widget_names' => $widget_names,
			]
		);

		if (($cacheFilename_handle = fopen(self::$widgetCacheFilename, "w"))) {
			if (fwrite($cacheFilename_handle, str_replace("	", "	", $cache_content)) !== false) {
				echo "<b>widget</b> leíró fájl sikeresen létrehozva!\n";
			} else {
				echo "Hiba történt a <b>widget</b> leíró fájl írásakor!\n";
			}
		} else {
			echo "<span style=\"background-color:red;\">Ismeretlen hiba a widget leíró készítése közben!</span>\n";
		}
	}
}
