<?php

/**
 * Show current CLI database mode and user.
 *
 * Usage: radaptor db:mode
 */
class CLICommandDbMode extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show current database mode';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Show current CLI database mode (normal or test) and authenticated user.

			Usage: radaptor db:mode
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function run(): void
	{
		CLIOutput::showStatus();
		echo "\n";

		echo "Commands:\n";
		echo "  radaptor db:usetest    Switch to test database\n";
		echo "  radaptor db:usenormal  Switch to normal/production database\n";
	}
}
