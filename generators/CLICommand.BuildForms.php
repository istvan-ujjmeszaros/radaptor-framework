<?php

class CLICommandBuildForms extends AbstractCLICommand
{
	private static string $cacheFilename;

	public function getName(): string
	{
		return 'Build forms';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Regenerate the form registry.

			Usage: radaptor build:forms

			Scans the autoloader for FormType classes and generates the form list cache.
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
		self::$cacheFilename = DEPLOY_ROOT . ApplicationConfig::GENERATED_FORMS_FILE;

		$formTypes = AutoloaderFromGeneratedMap::getFilteredList('FormType');

		$form_constants = [];

		foreach ($formTypes as $formType) {
			$form_constants[mb_strtoupper($formType)] = $formType;
		}

		$cache_content = GeneratorHelper::fetchTemplate(
			'build_forms',
			[
				'form_constants' => $form_constants,
			]
		);

		if (($cacheFilename_handle = fopen(self::$cacheFilename, "w"))) {
			if (fwrite($cacheFilename_handle, str_replace("	", "	", $cache_content)) !== false) {
				echo "<b>form_data</b> leíró fájl sikeresen létrehozva!\n";
			} else {
				echo "Hiba történt a <b>form_data</b> leíró fájl írásakor!\n";
			}
		} else {
			echo "<span style=\"background-color:red;\">Ismeretlen hiba a form_data leíró készítése közben!</span>\n";
		}
	}
}
