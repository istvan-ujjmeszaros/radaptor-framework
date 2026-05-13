<?php

declare(strict_types=1);

class CLICommandSiteExport extends AbstractCLICommand
{
	private const string PROFILE_DISASTER_RECOVERY = 'disaster_recovery';
	private const string PROFILE_SITE_MIGRATION = 'site_migration';

	public function getName(): string
	{
		return 'Export site snapshot';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Export database-backed site content and identity data into a JSON snapshot.

			Usage: radaptor site:export --output <file> --uploads-backed-up [--profile disaster_recovery|site_migration] [--pause-source-workers|--skip-source-worker-pause] [--json]

			Examples:
			  radaptor site:export --output tmp/site-snapshot.json --uploads-backed-up --json
			  radaptor site:export --output tmp/site-migration.json --uploads-backed-up --profile site_migration --pause-source-workers --json
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
			['name' => 'output', 'label' => 'Output file', 'type' => 'option', 'required' => true, 'default' => 'tmp/site-snapshot.json'],
			['name' => 'profile', 'label' => 'Snapshot profile', 'type' => 'option', 'default' => self::PROFILE_DISASTER_RECOVERY],
			['name' => 'uploads-backed-up', 'label' => 'Uploaded files backed up', 'type' => 'flag'],
			['name' => 'pause-source-workers', 'label' => 'Pause source workers', 'type' => 'flag'],
			['name' => 'skip-source-worker-pause', 'label' => 'Skip source worker pause', 'type' => 'flag'],
			['name' => 'allow-stale-workers', 'label' => 'Allow stale workers', 'type' => 'flag'],
			['name' => 'pause-timeout', 'label' => 'Pause timeout seconds', 'type' => 'option', 'default' => '30'],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor site:export --output <file> --uploads-backed-up [--profile disaster_recovery|site_migration] [--pause-source-workers|--skip-source-worker-pause] [--json]';
		$output = CLIOptionHelper::getRequiredOption('output', $usage);
		$uploads_backed_up = Request::hasArg('uploads-backed-up');
		$profile = CLIOptionHelper::getOption('profile', self::PROFILE_DISASTER_RECOVERY);
		$pause_source_workers = Request::hasArg('pause-source-workers');
		$skip_source_worker_pause = Request::hasArg('skip-source-worker-pause');
		$json = CLIOptionHelper::isJson();

		if (!in_array($profile, [self::PROFILE_DISASTER_RECOVERY, self::PROFILE_SITE_MIGRATION], true)) {
			Kernel::abort('Snapshot profile must be disaster_recovery or site_migration.');
		}

		if ($profile === self::PROFILE_SITE_MIGRATION && $pause_source_workers === $skip_source_worker_pause) {
			Kernel::abort('Site migration export requires exactly one of --pause-source-workers or --skip-source-worker-pause.');
		}

		try {
			$result = CmsSiteSnapshotService::writeSnapshot($output, $uploads_backed_up, $profile, [
				'pause_source_workers' => $pause_source_workers,
				'allow_stale_workers' => Request::hasArg('allow-stale-workers'),
				'pause_timeout_seconds' => CLIOptionHelper::getNullableIntOption('pause-timeout') ?? 30,
				'pause_context' => $output,
			]);
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
		echo "Profile: {$payload['profile']}\n";
		echo "Uploaded files in manifest: {$payload['upload_count']}\n";
	}
}
