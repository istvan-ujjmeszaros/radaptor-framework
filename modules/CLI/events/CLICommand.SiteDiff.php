<?php

declare(strict_types=1);

class CLICommandSiteDiff extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Diff site snapshot';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Compare a baseline site snapshot against another snapshot or the current live database.

			Usage: radaptor site:diff <baseline-file> [--current <file>|--against <file>|--live] [--json]

			Examples:
			  radaptor site:diff tmp/site-snapshot.json --live --json
			  radaptor site:diff baseline.json --current current.json --json
			DOC;
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor site:diff <baseline-file> [--current <file>|--against <file>|--live] [--json]';
		$baseline_path = CLIOptionHelper::getMainArgOrAbort($usage);
		$against_path = CLIOptionHelper::getOption('against');
		$current_path = CLIOptionHelper::getOption('current');
		$live = Request::hasArg('live');
		$json = CLIOptionHelper::isJson();

		if ($against_path !== '' && $current_path !== '') {
			Kernel::abort("--current and --against are aliases; use only one.\n{$usage}");
		}

		$current_path = $current_path !== '' ? $current_path : $against_path;

		if ($live && $current_path !== '') {
			Kernel::abort("--live and --current are mutually exclusive.\n{$usage}");
		}

		if (!$live && $current_path === '') {
			Kernel::abort($usage);
		}

		try {
			$baseline = CmsSiteSnapshotService::loadSnapshotFile($baseline_path);
			$result = $live
				? CmsSiteDiffService::diffLive($baseline)
				: CmsSiteDiffService::diffSnapshots($baseline, CmsSiteSnapshotService::loadSnapshotFile($current_path));
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Site diff failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Site diff: {$result['status']}\n";
		echo 'Summary: ' . json_encode($result['summary'], JSON_UNESCAPED_SLASHES) . "\n";
	}
}
