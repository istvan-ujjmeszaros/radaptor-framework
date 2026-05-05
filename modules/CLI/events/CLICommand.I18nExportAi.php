<?php

class CLICommandI18nExportAi extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Export AI translation CSV';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Export a normalized one-locale CSV for AI-assisted translation.

			Usage: radaptor i18n:export-ai --locale hu_HU [--missing-only] [--unreviewed-only] [--domain admin] [--key-prefix menu.] [--output file.csv] [--json]
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function run(): void
	{
		$locale = CLIOptionHelper::getOption('locale');
		$output = CLIOptionHelper::getOption('output');
		$json = Request::hasArg('json');

		if ($locale === '') {
			Kernel::abort('Usage: radaptor i18n:export-ai --locale <locale> [--output file.csv]');
		}

		$csv = I18nAiCsvService::exportForLocale($locale, [
			'domain' => CLIOptionHelper::getOption('domain'),
			'key_prefix' => CLIOptionHelper::getOption('key-prefix'),
			'missing_only' => Request::hasArg('missing-only'),
			'unreviewed_only' => Request::hasArg('unreviewed-only'),
		]);
		$rows = max(0, substr_count($csv, "\n") - 1);

		if ($output !== '') {
			if (file_put_contents($output, $csv) === false) {
				Kernel::abort("Unable to write file: {$output}");
			}

			$result = [
				'status' => 'success',
				'locale' => $locale,
				'output' => $output,
				'rows' => $rows,
			];

			if ($json) {
				echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

				return;
			}

			echo "Exported {$rows} rows to {$output}\n";

			return;
		}

		if ($json) {
			echo json_encode([
				'status' => 'success',
				'locale' => $locale,
				'rows' => $rows,
				'csv' => $csv,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

			return;
		}

		echo $csv;
	}
}
