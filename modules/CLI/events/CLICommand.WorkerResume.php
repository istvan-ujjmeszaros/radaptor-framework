<?php

declare(strict_types=1);

class CLICommandWorkerResume extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Resume runtime workers';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Release the active pause request for a worker scope.

			Usage: radaptor worker:resume --type <worker-type> --queue <queue-name> [--json]

			Examples:
			  radaptor worker:resume --type email_queue --queue transactional_email --json
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
			['name' => 'type', 'label' => 'Worker type', 'type' => 'option', 'required' => true, 'default' => EmailQueueWorker::WORKER_TYPE],
			['name' => 'queue', 'label' => 'Queue name', 'type' => 'option', 'required' => true, 'default' => EmailQueueWorker::QUEUE_NAME],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor worker:resume --type <worker-type> --queue <queue-name> [--json]';
		$worker_type = CLIOptionHelper::getRequiredOption('type', $usage);
		$queue_name = CLIOptionHelper::getRequiredOption('queue', $usage);
		$json = CLIOptionHelper::isJson();
		$result = RuntimeWorkerPauseControl::resume($worker_type, $queue_name);

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Worker pause released: {$worker_type}/{$queue_name}\n";
		echo 'Requests released: ' . (int) ($result['released'] ?? 0) . "\n";
	}
}
