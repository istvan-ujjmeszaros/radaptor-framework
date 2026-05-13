<?php

declare(strict_types=1);

class CLICommandEmailqueueStatus extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show email queue worker status';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Show transactional email queue worker status.

			Usage: radaptor emailqueue:status [--json]
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
		$json = CLIOptionHelper::isJson();
		$payload = RuntimeWorkerPauseControl::getScopeState(EmailQueueWorker::WORKER_TYPE, EmailQueueWorker::QUEUE_NAME);

		if ($json) {
			CLIOptionHelper::writeJson($payload);

			return;
		}

		echo 'Email queue worker status: ' . (string) ($payload['status'] ?? 'unknown') . "\n";
		echo 'Instances: ' . count($payload['instances'] ?? []) . "\n";
	}
}
