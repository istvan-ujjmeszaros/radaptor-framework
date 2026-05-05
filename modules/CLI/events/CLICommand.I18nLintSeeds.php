<?php

class CLICommandI18nLintSeeds extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Lint i18n seed files';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Lint shipped i18n seed CSV files.

			Usage: radaptor i18n:lint-seeds [--all-packages] [--strict] [--json]
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebTimeout(): int
	{
		return 60;
	}

	public function run(): void
	{
		$json = Request::hasArg('json');
		$strict = Request::hasArg('strict');
		$all_packages = Request::hasArg('all-packages');
		$result = I18nSeedLintService::lint(I18nSeedTargetDiscovery::discoverTargets([
			'all_packages' => $all_packages,
		]), [
			'check_global_duplicates' => !$all_packages,
		]);
		$failed = $result['errors'] > 0 || ($strict && $result['warnings'] > 0);

		if ($json) {
			echo json_encode([
				'status' => $failed ? 'error' : 'success',
				'scope' => $all_packages ? 'all_packages' : 'active',
				...$result,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

			if ($failed) {
				exit(1);
			}

			return;
		}

		echo "Targets: {$result['targets_checked']}\n";
		echo 'Scope: ' . ($all_packages ? 'all packages audit' : 'active sync scope') . "\n";
		echo "Files: {$result['files_checked']}\n";
		echo "Rows: {$result['rows_checked']}\n";
		echo "Errors: {$result['errors']}\n";
		echo "Warnings: {$result['warnings']}\n";

		foreach ($result['issues'] as $issue) {
			$line = ((int) $issue['line']) > 0 ? ':' . $issue['line'] : '';
			echo strtoupper($issue['severity']) . " {$issue['file']}{$line} {$issue['code']}: {$issue['message']}\n";
		}

		if ($failed) {
			exit(1);
		}
	}
}
