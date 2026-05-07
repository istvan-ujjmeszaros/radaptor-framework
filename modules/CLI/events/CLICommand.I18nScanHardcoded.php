<?php

class CLICommandI18nScanHardcoded extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Scan hardcoded UI literals';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Scan supported templates for hardcoded UI literals outside the i18n workflow.

			Usage: radaptor i18n:scan-hardcoded [--all-packages] [--json] [--strict]
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
		$all_packages = Request::hasArg('all-packages');
		$strict = Request::hasArg('strict');
		$result = I18nHardcodedUiScanner::scan([
			'all_packages' => $all_packages,
		]);
		$failed = $strict && $result['issues'] > 0;

		if ($json) {
			echo json_encode([
				...$result,
				'status' => $failed ? 'error' : ($result['issues'] > 0 ? 'warning' : 'success'),
				'scope' => $all_packages ? 'all_packages' : 'active',
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

			if ($failed) {
				exit(1);
			}

			return;
		}

		echo "Files scanned: {$result['files_scanned']}\n";
		echo 'Scope: ' . ($all_packages ? 'all packages audit' : 'active sync scope') . "\n";
		echo "Hardcoded UI warnings: {$result['issues']}\n";

		foreach ($result['results'] as $row) {
			echo "WARNING {$row['file']}:{$row['line']} literal=\"{$row['literal']}\" {$row['code']}\n";
		}

		if ($failed) {
			exit(1);
		}
	}
}
