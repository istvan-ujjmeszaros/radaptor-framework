<?php

class CLICommandBuildAll extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Build all';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Run all build generators in sequence.

			Usage: radaptor build:all

			Runs: config, db, template renderers, templates, widgets, theme data,
			layouts, forms, plugins, import/export datasets, roles, autoloader.
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
	public function getWebTimeout(): int
	{
		return 120;
	}

	public function run(): void
	{
		self::create();
	}

	public static function create(): void
	{
		$commands = [
			CLICommandBuildConfig::class,
			CLICommandBuildDb::class,
			CLICommandBuildTemplateRenderers::class,  // Must run before templates
			CLICommandBuildTemplates::class,
			CLICommandBuildWidgets::class,
			CLICommandBuildThemeData::class,
			CLICommandBuildAssets::class,
			CLICommandBuildLayouts::class,
			CLICommandBuildForms::class,
			CLICommandBuildPlugins::class,
			CLICommandBuildImportExportDatasets::class,
			CLICommandBuildRoles::class,
			CLICommandBuildAutoloader::class,
		];

		foreach ($commands as $command) {
			self::runCommand($command);
		}
	}

	/**
	 * @param class-string<AbstractCLICommand> $commandClass
	 */
	private static function runCommand(string $commandClass): void
	{
		echo "[build:all] Running {$commandClass}\n";
		(new $commandClass())->run();
		echo "[build:all] Finished {$commandClass}\n\n";
	}
}
