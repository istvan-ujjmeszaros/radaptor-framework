<?php

declare(strict_types=1);

class CLICommandSiteUploadsCheck extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Check site uploaded files';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Check physical uploaded files against the current database or a site snapshot manifest.

			Usage: radaptor site:uploads-check [--snapshot <file>] [--details] [--json]

			Examples:
			  radaptor site:uploads-check --json
			  radaptor site:uploads-check --details --json
			  radaptor site:uploads-check --snapshot tmp/site-snapshot.json --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebTimeout(): int
	{
		return 120;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'snapshot', 'label' => 'Snapshot file', 'type' => 'option', 'required' => false],
			['name' => 'details', 'label' => 'Include all files', 'type' => 'flag'],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$snapshot_path = CLIOptionHelper::getOption('snapshot');
		$details = Request::hasArg('details');
		$json = CLIOptionHelper::isJson();

		try {
			$snapshot = $snapshot_path !== '' ? CmsSiteSnapshotService::loadSnapshotFile($snapshot_path) : null;
			$payload = ['status' => 'success'] + CmsSiteSnapshotService::summarizeUploadsReport(
				CmsSiteSnapshotService::checkUploads($snapshot),
				$details
			);

			if (!$payload['ok']) {
				$payload['status'] = 'error';
			}
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Uploaded files check failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($payload);

			return;
		}

		echo 'Uploaded files: ' . ($payload['ok'] ? 'OK' : 'ERROR') . "\n";
		echo "Directory: {$payload['upload_directory']}\n";
		echo "Present: {$payload['present']} / {$payload['total']}\n";
		echo "Missing: {$payload['missing']}\n";
		echo "Mismatched: {$payload['mismatched']}\n";
	}
}
