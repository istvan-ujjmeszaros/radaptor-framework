<?php

/**
 * Run queue workers for email and generic queued jobs.
 *
 * Usage:
 *   radaptor emailqueue:run [mode=all|transactional|bulk|general] [--once]
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
			Run queue workers for email and generic queued jobs.

			Usage: radaptor emailqueue:run [mode=all|transactional|bulk|general] [--once]

			Examples:
			  radaptor emailqueue:run
			  radaptor emailqueue:run mode=transactional --once
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'mode', 'label' => 'Mode', 'type' => 'option', 'default' => 'all'],
			['name' => 'once', 'label' => 'Run once', 'type' => 'flag', 'default' => '1'],
		];
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$mode = (string) (Request::getArg('mode') ?? 'all');
		$runOnce = Request::hasArg('once');

		$processTransactional = in_array($mode, ['all', 'transactional'], true);
		$processBulk = in_array($mode, ['all', 'bulk'], true);
		$processGeneral = in_array($mode, ['all', 'general'], true);

		echo "Queue worker mode: {$mode}" . ($runOnce ? " (once)" : " (forever)") . "\n";

		if ($runOnce) {
			EmailQueueWorker::runOnce($processTransactional, $processBulk, $processGeneral);
			echo "Queue worker run finished.\n";

			return;
		}

		EmailQueueWorker::runForever($processTransactional, $processBulk, $processGeneral);
	}
}
