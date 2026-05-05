<?php

declare(strict_types=1);

class CLICommandSiteExport extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Export site snapshot';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Export database-backed site content and identity data into a JSON snapshot.

			Usage: radaptor site:export --output <file> --uploads-backed-up [--json]

			Examples:
			  radaptor site:export --output tmp/site-snapshot.json --uploads-backed-up --json
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
			['name' => 'output', 'label' => 'Output file', 'type' => 'option', 'required' => true, 'default' => 'tmp/site-snapshot.json'],
			['name' => 'uploads-backed-up', 'label' => 'Uploaded files backed up', 'type' => 'flag'],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor site:export --output <file> --uploads-backed-up [--json]';
		$output = CLIOptionHelper::getRequiredOption('output', $usage);
		$uploads_backed_up = Request::hasArg('uploads-backed-up');
		$json = CLIOptionHelper::isJson();

		try {
			$result = CmsSiteSnapshotService::writeSnapshot($output, $uploads_backed_up);
			$payload = ['status' => 'success'] + $result;
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Site snapshot export failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($payload);

			return;
		}

		echo "Site snapshot exported: {$payload['output']}\n";
		echo "Uploaded files in manifest: {$payload['upload_count']}\n";
	}
}
