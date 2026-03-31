<?php

/**
 * Export translations to CSV.
 *
 * Usage: radaptor i18n:export [--format normalized|wide] [--locale hu_HU] [--output translations.csv]
 *
 * Without --output, writes CSV to stdout (suitable for shell redirection):
 *   radaptor i18n:export --format wide --locale hu_HU > hu_HU-wide.csv
 *
 * Examples:
 *   radaptor i18n:export                                 # normalized, all locales → stdout
 *   radaptor i18n:export --locale hu_HU                  # normalized, one locale → stdout
 *   radaptor i18n:export --format wide --output translations.csv # wide, all locales → file
 */
class CLICommandI18nExport extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Export translations';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Export translations to CSV.

			Usage: radaptor i18n:export [--locale hu_HU] [--output translations.csv] [--format normalized|wide]

			Examples:
			  radaptor i18n:export
			  radaptor i18n:export --locale hu_HU
			  radaptor i18n:export --format wide --output translations.csv
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getRiskLevel(): string
	{
		return 'build';
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'locale', 'label' => 'Locale', 'type' => 'option'],
			['name' => 'output', 'label' => 'Output file', 'type' => 'option'],
			['name' => 'format', 'label' => 'Format', 'type' => 'option'],
		];
	}

	public function run(): void
	{
		$format     = $this->_getCliOption('format', 'normalized');
		$locale     = $this->_getCliOption('locale', '');
		$outputFile = $this->_getCliOption('output', '');
		$dataset = new ImportExportDatasetI18nTranslations();
		$csv = $dataset->export([
			'format' => $format,
			'locale' => $locale,
		]);

		if ($outputFile !== '') {
			file_put_contents($outputFile, $csv);
			$label = $locale !== '' ? $locale : 'all locales';
			echo "Exported {$format} {$label} → {$outputFile}\n";
		} else {
			echo $csv;
		}
	}

	private function _getCliOption(string $name, string $default = ''): string
	{
		global $argv;

		foreach ($argv as $idx => $arg) {
			if ($arg === "--{$name}") {
				$value = $argv[$idx + 1] ?? null;

				return is_string($value) && !str_starts_with($value, '--') ? trim($value) : $default;
			}
		}

		$keyValue = Request::getArg($name);

		if (!is_null($keyValue) && trim($keyValue) !== '') {
			return trim($keyValue);
		}

		return $default;
	}
}
