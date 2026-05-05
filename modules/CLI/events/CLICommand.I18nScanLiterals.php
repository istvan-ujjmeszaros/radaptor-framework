<?php

class CLICommandI18nScanLiterals extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Scan i18n fallback literals';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Scan PHP/templates for keyed fallback literals whose i18n rows are missing.

			Usage: radaptor i18n:scan-literals [--locale en_US,hu_HU] [--all-packages] [--json]
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
		$result = I18nFallbackLiteralScanner::scan([
			'locales' => CLIOptionHelper::getCsvListOption('locale'),
			'all_packages' => $all_packages,
		]);
		$failed = $result['issues'] > 0;

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

		echo "Files scanned: {$result['files_scanned']}\n";
		echo 'Scope: ' . ($all_packages ? 'all packages audit' : 'active sync scope') . "\n";
		echo "Fallback occurrences: {$result['occurrences']}\n";
		echo "Issues: {$result['issues']}\n";
		echo "Allowed literals: {$result['allowed_literals']}\n";

		foreach ($result['results'] as $row) {
			if ($row['severity'] !== 'error') {
				continue;
			}

			$missing = empty($row['missing_locales']) ? '' : ' missing=' . implode(',', $row['missing_locales']);
			echo "ERROR {$row['file']}:{$row['line']} {$row['full_key']} fallback=\"{$row['fallback']}\" {$row['code']}{$missing}\n";
		}

		if ($failed) {
			exit(1);
		}
	}
}
