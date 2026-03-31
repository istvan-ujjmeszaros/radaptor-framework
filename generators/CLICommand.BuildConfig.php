<?php

class CLICommandBuildConfig extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Build config';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Regenerate the configuration registry.

			Usage: radaptor build:config

			Reads ApplicationConfig constants and generates the config cache file.
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

	private static string $cacheFilename = DEPLOY_ROOT . ApplicationConfig::GENERATED_CONFIG_FILE;

	public static function create(): void
	{
		$reflector = new ReflectionClass('ApplicationConfig');
		$constants = $reflector->getConstants();

		ksort($constants);

		$content = GeneratorHelper::fetchTemplate(
			'build_config',
			[
				'constant_names' => array_keys($constants),
			]
		);

		if (($cacheFilename_handle = fopen(self::$cacheFilename, "w"))) {
			if (fwrite($cacheFilename_handle, str_replace("	", "	", $content)) !== false) {
				echo "<b>Config</b> leíró sikeresen létrehozva!\n";
			} else {
				echo "Hiba történt a <b>Config</b> leíró írásakor!\n";
			}
		} else {
			echo "<span style=\"background-color:red;\">Ismeretlen hiba a Config leíró készítése közben!</span>\n";
		}
	}
}
