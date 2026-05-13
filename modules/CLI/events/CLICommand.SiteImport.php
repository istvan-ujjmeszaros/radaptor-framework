<?php

declare(strict_types=1);

class CLICommandSiteImport extends AbstractCLICommand
{
	private const string PROFILE_DISASTER_RECOVERY = 'disaster_recovery';
	private const string PROFILE_SITE_MIGRATION = 'site_migration';

	public function getName(): string
	{
		return 'Import site snapshot';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Validate or restore a JSON site snapshot. Defaults to dry-run; pass --apply --replace to mutate the database.
			A successful apply runs post-import maintenance after the data has been restored:
			tag i18n sync, shipped i18n sync, translation-memory rebuild, build:all, and cache flush.

			Usage: radaptor site:import <file> [--dry-run|--apply] [--replace] [--allow-environment-mismatch] [--pause-target-workers|--skip-target-worker-pause] [--json]

			Examples:
			  radaptor site:import tmp/site-snapshot.json --dry-run --json
			  radaptor site:import tmp/site-snapshot.json --apply --replace --json
			  radaptor site:import tmp/site-migration.json --apply --replace --pause-target-workers --allow-environment-mismatch --json
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
			['name' => 'allow-environment-mismatch', 'label' => 'Allow environment mismatch', 'type' => 'flag'],
			['name' => 'pause-target-workers', 'label' => 'Pause target workers', 'type' => 'flag'],
			['name' => 'skip-target-worker-pause', 'label' => 'Skip target worker pause', 'type' => 'flag'],
			['name' => 'allow-stale-workers', 'label' => 'Allow stale workers', 'type' => 'flag'],
			['name' => 'pause-timeout', 'label' => 'Pause timeout seconds', 'type' => 'option', 'default' => '30'],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor site:import <file> [--dry-run|--apply] [--replace] [--allow-environment-mismatch] [--pause-target-workers|--skip-target-worker-pause] [--json]';
		CLIOptionHelper::assertNoApplyDryRunConflict($usage);
		$file = CLIOptionHelper::getMainArgOrAbort($usage);
		$apply = Request::hasArg('apply');
		$replace = Request::hasArg('replace');
		$allow_environment_mismatch = Request::hasArg('allow-environment-mismatch');
		$pause_target_workers = Request::hasArg('pause-target-workers');
		$skip_target_worker_pause = Request::hasArg('skip-target-worker-pause');
		$json = CLIOptionHelper::isJson();

		try {
			$snapshot = CmsSiteSnapshotService::loadSnapshotFile($file);
			$profile = (string) ($snapshot['profile'] ?? self::PROFILE_DISASTER_RECOVERY);

			if ($profile === self::PROFILE_SITE_MIGRATION && $apply && $pause_target_workers === $skip_target_worker_pause) {
				Kernel::abort('Site migration restore requires exactly one of --pause-target-workers or --skip-target-worker-pause when applying.');
			}

			$options = [
				'pause_target_workers' => $apply && $pause_target_workers,
				'allow_stale_workers' => Request::hasArg('allow-stale-workers'),
				'pause_timeout_seconds' => CLIOptionHelper::getNullableIntOption('pause-timeout') ?? 30,
				'pause_context' => $file,
			];
			$payload = !$apply
				? CmsSiteSnapshotService::importSnapshot($snapshot, true, $replace, $allow_environment_mismatch, $options)
				: CmsMutationAuditService::withContext(
					'site:import',
					['file' => $file, 'replace' => $replace, 'allow_environment_mismatch' => $allow_environment_mismatch],
					static fn (): array => CmsSiteSnapshotService::importSnapshot($snapshot, false, $replace, $allow_environment_mismatch, $options)
				);
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
		echo 'Profile: ' . (string) ($payload['profile'] ?? 'unknown') . "\n";
		echo 'Tables: ' . count($payload['summary']) . "\n";
		echo 'Uploads: ' . ($payload['uploads']['ok'] ? 'OK' : 'ERROR') . " ({$payload['uploads']['present']}/{$payload['uploads']['total']} present)\n";
		echo 'Environment: ' . (string) ($payload['environment_check']['status'] ?? 'unknown') . "\n";

		if (($payload['post_import_maintenance']['ran'] ?? false) === true) {
			echo 'Post-import maintenance: ' . (($payload['post_import_maintenance']['success'] ?? false) ? 'OK' : 'ERROR') . "\n";

			foreach (($payload['post_import_maintenance']['steps'] ?? []) as $step) {
				if (!is_array($step) || ($step['ran'] ?? false) !== true) {
					continue;
				}

				echo '  - ' . (string) ($step['command'] ?? 'post-import step') . ': '
					. (($step['success'] ?? false) ? 'OK' : 'ERROR') . "\n";
			}
		} elseif (($payload['post_import_build']['ran'] ?? false) === true) {
			echo 'Post-import build: ' . (($payload['post_import_build']['success'] ?? false) ? 'OK' : 'ERROR') . "\n";
		}

		foreach ($payload['errors'] as $error) {
			echo "ERROR: {$error}\n";
		}
	}
}
