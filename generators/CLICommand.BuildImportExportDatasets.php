<?php

class CLICommandBuildImportExportDatasets extends AbstractCLICommand
{
	private static string $_cacheFilename = '';
	private static array $_datasetIndex = [];
	private static array $_scannableDirectories = [];
	private static bool $_followSymlinks = false;
	private static array $_scannableFileEndings = ['.php'];
	private static string $_fileBeginning = 'ImportExportDataset';
	private static string $_fileEnding = 'php';

	public function getName(): string
	{
		return 'Build import/export datasets';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Regenerate the import/export dataset registry.

			Usage: radaptor build:import-export-datasets

			Scans for ImportExportDataset.*.php files and generates the dataset list cache.
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
		self::$_scannableDirectories = [DEPLOY_ROOT];
	}

	private static function _parseDir(string $dir_path): void
	{
		if (GeneratorHelper::folderIsExcluded($dir_path)) {
			return;
		}

		if (!is_dir($dir_path)) {
			return;
		}

		if (($dir = opendir($dir_path)) === false) {
			return;
		}

		while (($file = readdir($dir)) !== false) {
			$file_path = $dir_path . $file;

			if ($file[0] === '.') {
				continue;
			}

			switch (filetype($file_path)) {
				case 'dir':
					if ($file !== '.' && $file !== '..' && !GeneratorHelper::folderIsExcluded($file_path)) {
						self::_parseDir($file_path . '/');
					}

					break;

				case 'link':
					if (self::$_followSymlinks) {
						self::_parseDir($file_path . '/');
					}

					break;

				case 'file':
					if (count(self::$_scannableFileEndings) > 0 && !in_array(mb_substr($file, mb_strrpos($file, '.')), self::$_scannableFileEndings, true)) {
						break;
					}

					$exploded_file_name = explode('.', $file);

					if ((count($exploded_file_name) === 3) && ($exploded_file_name[0] === self::$_fileBeginning) && ($exploded_file_name[2] === self::$_fileEnding)) {
						$datasetName = $exploded_file_name[1];
						self::$_datasetIndex[$datasetName] = $file_path;
					}

					break;
			}
		}

		closedir($dir);
	}

	public static function create(): void
	{
		self::$_cacheFilename = DEPLOY_ROOT . ApplicationConfig::GENERATED_IMPORT_EXPORT_DATASETS_FILE;
		self::_initConfig();

		foreach (self::$_scannableDirectories as $dir) {
			self::_parseDir($dir);
		}

		ksort(self::$_datasetIndex);

		$dataset_constants = [];
		$dataset_names = [];

		foreach (self::$_datasetIndex as $datasetName => $path) {
			$dataset_constants[mb_strtoupper(GeneratorHelper::toSnakeCase($datasetName))] = $datasetName;
			$dataset_names[] = $datasetName;
		}

		$cache_content = GeneratorHelper::fetchTemplate(
			'build_import_export_datasets',
			[
				'dataset_constants' => $dataset_constants,
				'dataset_names' => $dataset_names,
			]
		);

		if (($cacheFilename_handle = fopen(self::$_cacheFilename, 'w'))) {
			if (fwrite($cacheFilename_handle, str_replace("\t", "\t", $cache_content)) !== false) {
				echo "<b>import_export_datasets</b> leíró fájl sikeresen létrehozva!\n";
			} else {
				echo "Hiba történt az <b>import_export_datasets</b> leíró fájl írásakor!\n";
			}
		} else {
			echo "<span style=\"background-color:red;\">Ismeretlen hiba az import_export_datasets leíró készítése közben!</span>\n";
		}
	}
}
