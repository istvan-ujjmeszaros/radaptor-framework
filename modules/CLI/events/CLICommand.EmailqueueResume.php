<?php

declare(strict_types=1);

class CLICommandEmailqueueResume extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Resume email queue workers';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Release the active transactional email queue worker pause.

			Usage: radaptor emailqueue:resume [--json]
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

	public function getWebParams(): array
	{
		return [
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$json = CLIOptionHelper::isJson();
		$result = RuntimeWorkerPauseControl::resume(EmailQueueWorker::WORKER_TYPE, EmailQueueWorker::QUEUE_NAME);

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo 'Email queue pause requests released: ' . (int) ($result['released'] ?? 0) . "\n";
	}
}
