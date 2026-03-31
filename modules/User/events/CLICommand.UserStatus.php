<?php

/**
 * Show CLI status: current user and database mode.
 *
 * Usage: radaptor user:status
 */
class CLICommandUserStatus extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show user status';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Show CLI status: current user and database mode.

			Usage: radaptor user:status [--json]

			Examples:
			  radaptor user:status
			  radaptor user:status --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		CLIOutput::showStatus();
	}
}
