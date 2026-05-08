<?php

class CLICommandI18nImportAi extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Import AI translation CSV';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Import a normalized AI translation CSV using upsert mode and source_text validation.

			Usage: radaptor i18n:import-ai <file.csv> [--expect-locale hu-HU] [--dry-run] [--json]
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
		return 60;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'CSV file', 'type' => 'main_arg', 'required' => true],
			['name' => 'expect-locale', 'label' => 'Expected locale', 'type' => 'option'],
			['name' => 'dry-run', 'label' => 'Dry run', 'type' => 'flag'],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$file = CLIOptionHelper::getMainArgOrAbort('Usage: radaptor i18n:import-ai <file.csv> [--expect-locale hu-HU] [--dry-run]');
		$json = Request::hasArg('json');
		$dry_run = Request::hasArg('dry-run');

		if (!is_file($file)) {
			Kernel::abort("File not found: {$file}");
		}

		$csv = file_get_contents($file);

		if ($csv === false) {
			Kernel::abort("Unable to read file: {$file}");
		}

		$result = I18nAiCsvService::importCsv($csv, [
			'expect_locale' => CLIOptionHelper::getOption('expect-locale'),
			'dry_run' => $dry_run,
		]);

		if ($json) {
			echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

			if ($result['status'] !== 'success') {
				exit(1);
			}

			return;
		}

		foreach (($result['errors'] ?? []) as $error) {
			echo "ERROR: {$error}\n";
		}

		foreach (($result['warnings'] ?? []) as $warning) {
			echo "WARNING: {$warning}\n";
		}

		echo ($dry_run ? '[dry-run] ' : '') . "Processed: " . (int) ($result['processed'] ?? 0) . "\n";
		echo ($dry_run ? '[dry-run] ' : '') . "Imported: " . (int) ($result['imported'] ?? 0) . "\n";
		echo ($dry_run ? '[dry-run] ' : '') . "Inserted: " . (int) ($result['inserted'] ?? 0) . "\n";
		echo ($dry_run ? '[dry-run] ' : '') . "Updated: " . (int) ($result['updated'] ?? 0) . "\n";
		echo ($dry_run ? '[dry-run] ' : '') . "Skipped: " . (int) ($result['skipped'] ?? 0) . "\n";

		if ($result['status'] !== 'success') {
			exit(1);
		}
	}
}
