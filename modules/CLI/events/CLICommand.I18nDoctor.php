<?php

class CLICommandI18nDoctor extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Diagnose i18n workflow health';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Run the i18n seed linter, fallback literal scanner and coverage summary.

			Usage: radaptor i18n:doctor [--all-packages] [--json]
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebTimeout(): int
	{
		return 90;
	}

	public function run(): void
	{
		$json = Request::hasArg('json');
		$all_packages = Request::hasArg('all-packages');
		$lint = I18nSeedLintService::lint(I18nSeedTargetDiscovery::discoverTargets([
			'all_packages' => $all_packages,
		]), [
			'check_global_duplicates' => !$all_packages,
		]);
		$literals = I18nFallbackLiteralScanner::scan([
			'all_packages' => $all_packages,
		]);
		$coverage = I18nCoverageService::summarize();
		$missing_total = array_sum(array_map(
			static fn (array $locale): int => (int) ($locale['missing'] ?? 0),
			$coverage['locales']
		));
		$failed = $lint['errors'] > 0 || $literals['issues'] > 0 || $missing_total > 0;
		$result = [
			'status' => $failed ? 'error' : 'success',
			'scope' => $all_packages ? 'all_packages' : 'active',
			'lint' => $lint,
			'literals' => $literals,
			'coverage' => $coverage,
		];

		if ($json) {
			echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

			if ($failed) {
				exit(1);
			}

			return;
		}

		echo "Seed lint: {$lint['errors']} errors, {$lint['warnings']} warnings\n";
		echo 'Scope: ' . ($all_packages ? 'all packages audit' : 'active sync scope') . "\n";
		echo "Fallback literals: {$literals['issues']} issues, {$literals['allowed_literals']} allowed literals\n";
		echo "Coverage missing translations: {$missing_total}\n";

		foreach ($coverage['locales'] as $locale) {
			echo "{$locale['locale']}: {$locale['translated']}/{$locale['total']} translated ({$locale['translated_percent']}%), missing {$locale['missing']}, stale {$locale['stale']}\n";
		}

		if ($failed) {
			exit(1);
		}
	}
}
