<?php

class CLICommandBuildRoles extends AbstractCLICommand
{
	private static string $cacheFilename;

	public function getName(): string
	{
		return 'Build roles';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Regenerate the roles enum registry.

			Usage: radaptor build:roles

			Reads role identifiers from the database and generates the role constants cache.
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
		self::$cacheFilename = DEPLOY_ROOT . ApplicationConfig::GENERATED_ROLES_FILE;

		$query = "SELECT role FROM roles_tree";
		$stmt = Db::instance()->prepare($query);
		$stmt->execute();

		$rs = $stmt->fetchAll(PDO::FETCH_COLUMN);

		$consts = [];

		foreach ($rs as $role) {
			$consts['ROLE_' . mb_strtoupper((string) $role)] = $role;
		}

		$cache_content = GeneratorHelper::fetchTemplate(
			'build_roles',
			[
				'role_constants' => $consts,
			]
		);

		if (($cacheFilename_handle = fopen(self::$cacheFilename, "w"))) {
			if (fwrite($cacheFilename_handle, str_replace("	", "	", $cache_content)) !== false) {
				echo "<b>role</b> leíró fájl sikeresen létrehozva!\n";
			} else {
				echo "Hiba történt a <b>role</b> leíró fájl írásakor!\n";
			}
		} else {
			echo "<span style=\"background-color:red;\">Ismeretlen hiba a role leíró készítése közben!</span>\n";
		}
	}
}
