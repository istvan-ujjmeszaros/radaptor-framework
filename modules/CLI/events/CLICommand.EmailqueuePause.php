<?php

declare(strict_types=1);

class CLICommandEmailqueuePause extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Pause email queue workers';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Request a transactional email queue worker pause.

			Usage: radaptor emailqueue:pause [--reason <reason>] [--context <context>] [--wait] [--timeout <seconds>] [--allow-stale-workers] [--json]
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
		$reason = CLIOptionHelper::getOption('reason', 'manual');
		$context = CLIOptionHelper::getOption('context', '');
		$timeout = CLIOptionHelper::getNullableIntOption('timeout') ?? 30;
		$wait = Request::hasArg('wait');
		$allow_stale_workers = Request::hasArg('allow-stale-workers');
		$json = CLIOptionHelper::isJson();
		$result = RuntimeWorkerPauseControl::requestPause(EmailQueueWorker::WORKER_TYPE, EmailQueueWorker::QUEUE_NAME, $reason, $context);

		if ($wait && is_string($result['pause_request_id'] ?? null)) {
			$result['confirmation'] = RuntimeWorkerPauseControl::waitForPauseConfirmation(
				EmailQueueWorker::WORKER_TYPE,
				EmailQueueWorker::QUEUE_NAME,
				(string) $result['pause_request_id'],
				$timeout,
				$allow_stale_workers
			);
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo 'Email queue pause request: ' . (string) ($result['pause_request_id'] ?? 'n/a') . "\n";

		if (isset($result['confirmation']) && is_array($result['confirmation'])) {
			echo 'Confirmed: ' . (($result['confirmation']['confirmed'] ?? false) ? 'yes' : 'no') . "\n";
		}
	}
}
