<?php

/**
 * Build compiled i18n catalog files from non-empty translations.
 *
 * Usage: radaptor i18n:build [--locale hu_HU | locale=hu_HU]
 *
 * Examples:
 *   radaptor i18n:build                    # all locales
 *   radaptor i18n:build --locale hu_HU    # single locale
 *   radaptor i18n:build locale=hu_HU      # single locale (key=value form)
 */
class CLICommandI18nBuild extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Build i18n catalogs';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Build compiled i18n catalog files from non-empty translations.

			Usage: radaptor i18n:build [--locale hu_HU]

			Examples:
			  radaptor i18n:build
			  radaptor i18n:build --locale hu_HU
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
		];
	}

	public function run(): void
	{
		$localeFilter = $this->_getCliOption('locale', '');
		$locales = I18nCatalogBuilder::build($localeFilter);

		if (empty($locales)) {
			echo "No locales found.\n";

			return;
		}

		$outputDir = DEPLOY_ROOT . 'generated/i18n/';

		foreach ($locales as $locale) {
			$path = $outputDir . $locale . '.php';
			$count = count(include $path);
			echo "Built {$locale}: {$count} keys → {$path}\n";
		}
	}

	/**
	 * Read a named CLI option, supporting both --name value and name=value forms.
	 */
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

		$fallback = $_GET[$name] ?? null;

		if (is_string($fallback) && trim($fallback) !== '') {
			return trim($fallback);
		}

		return $default;
	}
}
