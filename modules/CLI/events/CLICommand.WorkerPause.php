<?php

declare(strict_types=1);

class CLICommandWorkerPause extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Pause runtime workers';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Request a runtime worker pause and optionally wait until active workers confirm it.

			Usage: radaptor worker:pause --type <worker-type> --queue <queue-name> [--reason <reason>] [--context <context>] [--wait] [--timeout <seconds>] [--allow-stale-workers] [--json]

			Examples:
			  radaptor worker:pause --type email_queue --queue transactional_email --reason site_migration_export --wait --json
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
			['name' => 'reason', 'label' => 'Reason', 'type' => 'option', 'default' => 'manual'],
			['name' => 'context', 'label' => 'Context', 'type' => 'option'],
			['name' => 'wait', 'label' => 'Wait for confirmation', 'type' => 'flag'],
			['name' => 'timeout', 'label' => 'Timeout seconds', 'type' => 'option', 'default' => '30'],
			['name' => 'allow-stale-workers', 'label' => 'Allow stale workers', 'type' => 'flag'],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor worker:pause --type <worker-type> --queue <queue-name> [--reason <reason>] [--context <context>] [--wait] [--timeout <seconds>] [--allow-stale-workers] [--json]';
		$worker_type = CLIOptionHelper::getRequiredOption('type', $usage);
		$queue_name = CLIOptionHelper::getRequiredOption('queue', $usage);
		$reason = CLIOptionHelper::getOption('reason', 'manual');
		$context = CLIOptionHelper::getOption('context', '');
		$timeout = CLIOptionHelper::getNullableIntOption('timeout') ?? 30;
		$wait = Request::hasArg('wait');
		$allow_stale_workers = Request::hasArg('allow-stale-workers');
		$json = CLIOptionHelper::isJson();
		$result = RuntimeWorkerPauseControl::requestPause($worker_type, $queue_name, $reason, $context);

		if ($wait && is_string($result['pause_request_id'] ?? null)) {
			$result['confirmation'] = RuntimeWorkerPauseControl::waitForPauseConfirmation(
				$worker_type,
				$queue_name,
				(string) $result['pause_request_id'],
				$timeout,
				$allow_stale_workers,
				$this->resolveStaleAfterSeconds($worker_type, $queue_name)
			);
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Worker pause requested: {$worker_type}/{$queue_name}\n";
		echo 'Request: ' . (string) ($result['pause_request_id'] ?? 'n/a') . "\n";

		if (isset($result['confirmation']) && is_array($result['confirmation'])) {
			echo 'Confirmed: ' . (($result['confirmation']['confirmed'] ?? false) ? 'yes' : 'no') . "\n";
		}
	}

	private function resolveStaleAfterSeconds(string $worker_type, string $queue_name): int
	{
		if ($worker_type === EmailQueueWorker::WORKER_TYPE && $queue_name === EmailQueueWorker::QUEUE_NAME) {
			return EmailQueueWorker::getStaleAfterSeconds();
		}

		return 30;
	}
}
