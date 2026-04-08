<?php

/**
 * Run the transactional email queue worker.
 *
 * Usage:
 *   radaptor emailqueue:run [--once]
 */
class CLICommandEmailqueueRun extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Run email queue workers';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Run the transactional email queue worker.

			Usage: radaptor emailqueue:run [--once]

			Examples:
			  radaptor emailqueue:run
			  radaptor emailqueue:run --once
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'once', 'label' => 'Run once', 'type' => 'flag', 'default' => '1'],
		];
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$runOnce = Request::hasArg('once');

		echo 'Queue worker mode: transactional' . ($runOnce ? " (once)\n" : " (forever)\n");

		if ($runOnce) {
			EmailQueueWorker::runOnce();
			echo "Queue worker run finished.\n";

			return;
		}

		EmailQueueWorker::runForever();
	}
}
