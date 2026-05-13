<?php

declare(strict_types=1);

class CLICommandWorkerStatus extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show runtime worker status';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Show registered runtime worker instances for a worker scope.

			Usage: radaptor worker:status --type <worker-type> --queue <queue-name> [--json]

			Examples:
			  radaptor worker:status --type email_queue --queue transactional_email --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'type', 'label' => 'Worker type', 'type' => 'option', 'required' => true, 'default' => EmailQueueWorker::WORKER_TYPE],
			['name' => 'queue', 'label' => 'Queue name', 'type' => 'option', 'required' => true, 'default' => EmailQueueWorker::QUEUE_NAME],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor worker:status --type <worker-type> --queue <queue-name> [--json]';
		$worker_type = CLIOptionHelper::getRequiredOption('type', $usage);
		$queue_name = CLIOptionHelper::getRequiredOption('queue', $usage);
		$json = CLIOptionHelper::isJson();
		$payload = RuntimeWorkerPauseControl::getScopeState($worker_type, $queue_name);
		$payload['worker_type'] = $worker_type;
		$payload['queue_name'] = $queue_name;

		if ($json) {
			CLIOptionHelper::writeJson($payload);

			return;
		}

		echo "Worker scope: {$worker_type}/{$queue_name}\n";
		echo 'Status: ' . (string) ($payload['status'] ?? 'unknown') . "\n";
		echo 'Instances: ' . count($payload['instances'] ?? []) . "\n";
	}
}
