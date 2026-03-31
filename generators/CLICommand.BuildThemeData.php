<?php

class CLICommandBuildThemeData extends AbstractCLICommand
{
	private static string $themeDataCacheFilename = '';
	private static array $_themeDataIndex = [];
	private static array $scannableDirectories = [];
	private static bool $followSymlinks = false;
	private static array $scannableFileEndings = ['.php'];
	private static string $themeDataFileBeginning = 'ThemeData';
	private static string $themeDataFileEnding = 'php';

	public function getName(): string
	{
		return 'Build theme data';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Regenerate the theme data registry.

			Usage: radaptor build:themeData

			Scans for ThemeData.*.php files and generates the theme list cache.
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

											if ((count($exploded_file_name) == 3) && ($exploded_file_name[0] == self::$themeDataFileBeginning) && ($exploded_file_name[2] == self::$themeDataFileEnding)) {
												$themeDataName = $exploded_file_name[1];
												self::$_themeDataIndex[$themeDataName] = $file_path;
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
		self::$themeDataCacheFilename = DEPLOY_ROOT . ApplicationConfig::GENERATED_THEME_DATA_FILE;
		self::_initConfig();

		foreach (self::$scannableDirectories as $dir) {
			self::_parseDir($dir);
		}

		$transformed_array = [];

		foreach (self::$_themeDataIndex as $key => $value) {
			$transformed_array[mb_strtoupper($key)] = $key;
		}

		ksort($transformed_array);

		$theme_constants = [];
		$theme_data_names = [];

		foreach ($transformed_array as $theme_upper_name => $theme_name) {
			$theme_constants[$theme_upper_name] = $theme_name;
			$theme_data_names[] = $theme_name;
		}

		$cache_content = GeneratorHelper::fetchTemplate(
			'build_theme_data',
			[
				'theme_constants' => $theme_constants,
				'theme_data_names' => $theme_data_names,
			]
		);

		if (($cacheFilename_handle = fopen(self::$themeDataCacheFilename, "w"))) {
			if (fwrite($cacheFilename_handle, str_replace("	", "	", $cache_content)) !== false) {
				echo "<b>themeData</b> leíró fájl sikeresen létrehozva!\n";
			} else {
				echo "Hiba történt a <b>themeData</b> leíró fájl írásakor!\n";
			}

			//			include self::$themeDataCacheFilename;
		} else {
			echo "<span style=\"background-color:red;\">Ismeretlen hiba a themeData leíró készítése közben!</span>\n";
		}
	}
}
