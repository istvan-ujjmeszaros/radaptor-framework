<?php

/**
 * Switch CLI database mode to test database.
 *
 * Usage: radaptor db:usetest
 */
class CLICommandDbUsetest extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Switch to test database';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Switch CLI database mode to test database. All subsequent CLI commands will use the _test database.

			Usage: radaptor db:usetest
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		CLIStorage::save('CLI_DATABASE_MODE', 'test');

		// Clear cached PDO instances to force reconnection with new DSN
		Db::$pdoInstances = [];

		CLIOutput::success("Switched to test database mode.");
		CLIOutput::showStatus();
	}
}
