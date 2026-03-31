<?php

/**
 * Switch CLI database mode to normal/production database.
 *
 * Usage: radaptor db:usenormal
 */
class CLICommandDbUsenormal extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Switch to normal database';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Switch CLI database mode to normal/production database.

			Usage: radaptor db:usenormal
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
		CLIStorage::save('CLI_DATABASE_MODE', 'normal');

		// Clear cached PDO instances to force reconnection with new DSN
		Db::$pdoInstances = [];

		CLIOutput::success("Switched to normal database mode.");
		CLIOutput::showStatus();
	}
}
