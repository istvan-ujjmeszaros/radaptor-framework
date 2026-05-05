<?php

declare(strict_types=1);

class CLICommandSiteImport extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Import site snapshot';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Validate or restore a JSON site snapshot. Defaults to dry-run; pass --apply --replace to mutate the database.
			A successful apply runs build:all after the data has been restored.

			Usage: radaptor site:import <file> [--dry-run|--apply] [--replace] [--json]

			Examples:
			  radaptor site:import tmp/site-snapshot.json --dry-run --json
			  radaptor site:import tmp/site-snapshot.json --apply --replace --json
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

	public function getWebTimeout(): int
	{
		return 120;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'Snapshot file', 'type' => 'main_arg', 'required' => true],
			['name' => 'apply', 'label' => 'Apply import', 'type' => 'flag'],
			['name' => 'replace', 'label' => 'Replace current data', 'type' => 'flag'],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor site:import <file> [--dry-run|--apply] [--replace] [--json]';
		$file = CLIOptionHelper::getMainArgOrAbort($usage);
		$apply = Request::hasArg('apply');
		$replace = Request::hasArg('replace');
		$json = CLIOptionHelper::isJson();

		try {
			$snapshot = CmsSiteSnapshotService::loadSnapshotFile($file);
			$payload = CmsSiteSnapshotService::importSnapshot($snapshot, !$apply, $replace);
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Site snapshot import failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($payload);

			return;
		}

		echo 'Site snapshot import ' . ($payload['applied'] ? 'applied' : 'checked') . ": {$payload['status']}\n";
		echo 'Tables: ' . count($payload['summary']) . "\n";
		echo 'Uploads: ' . ($payload['uploads']['ok'] ? 'OK' : 'ERROR') . " ({$payload['uploads']['present']}/{$payload['uploads']['total']} present)\n";

		if (($payload['post_import_build']['ran'] ?? false) === true) {
			echo 'Post-import build: ' . (($payload['post_import_build']['success'] ?? false) ? 'OK' : 'ERROR') . "\n";
		}

		foreach ($payload['errors'] as $error) {
			echo "ERROR: {$error}\n";
		}
	}
}
