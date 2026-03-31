<?php

class CLICommandBuildLayouts extends AbstractCLICommand
{
	private static string $cacheFilename = '';
	private static array $_index = [];
	private static array $scannableDirectories = [];
	private static bool $followSymlinks = false;
	private static array $scannableFileEndings = ['.php'];
	private static string $fileBeginning = 'layout';
	private static string $fileEnding = 'php';

	public function getName(): string
	{
		return 'Build layouts';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Regenerate the layout type registry.

			Usage: radaptor build:layouts

			Scans the codebase for layout.*.php files and generates the layout types cache.
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
		self::$scannableDirectories = [DEPLOY_ROOT, ];
	}

	private static function _parseDir(string $dir_path): void
	{
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

											//var_dump($exploded_file_name);
											if (($exploded_file_name[0] == self::$fileBeginning) && ($exploded_file_name[count($exploded_file_name) - 1] == self::$fileEnding)) {
												$layoutTypeName = $exploded_file_name[1];
												self::$_index[$layoutTypeName] = $file_path;
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
		self::$cacheFilename = DEPLOY_ROOT . ApplicationConfig::GENERATED_LAYOUTS_FILE;
		self::_initConfig();

		foreach (self::$scannableDirectories as $dir) {
			self::_parseDir($dir);
		}

		$layout_type_names = array_keys(self::$_index);
		sort($layout_type_names);

		$cache_content = GeneratorHelper::fetchTemplate(
			'build_layouts',
			[
				'layout_type_names' => $layout_type_names,
			]
		);

		if (($cacheFilename_handle = fopen(self::$cacheFilename, "w"))) {
			if (fwrite($cacheFilename_handle, str_replace("	", "	", $cache_content)) !== false) {
				echo "<b>LayoutType</b> leíró fájl sikeresen létrehozva!\n";
			} else {
				echo "Hiba történt a <b>LayoutType</b> leíró fájl írásakor!\n";
			}
		} else {
			echo "<span style=\"background-color:red;\">Ismeretlen hiba a LayoutType leíró készítése közben!</span>\n";
		}
	}
}
