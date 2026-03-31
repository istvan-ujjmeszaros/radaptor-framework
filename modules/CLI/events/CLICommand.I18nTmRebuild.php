<?php

/**
 * Rebuild translation memory entries from existing i18n_translations rows.
 *
 * Usage:
 *   radaptor i18n:tm-rebuild
 *   radaptor i18n:tm-rebuild locale=hu_HU
 *
 * Rebuild removes existing TM rows for the affected target locale(s), then
 * repopulates them from current translations where:
 *   - source locale is en_US
 *   - source_text is not empty
 *   - translation text is not empty
 */
class CLICommandI18nTmRebuild extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Rebuild translation memory';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Rebuild translation memory entries from existing i18n_translations rows.

			Usage: radaptor i18n:tm-rebuild [locale=hu_HU]

			Examples:
			  radaptor i18n:tm-rebuild
			  radaptor i18n:tm-rebuild locale=hu_HU
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

	public function run(): void
	{
		$locale = Request::getArg('locale');
		$locale = is_string($locale) ? trim($locale) : '';

		$count = I18nTm::rebuildFromTranslations($locale !== '' ? $locale : null);

		if ($locale !== '') {
			echo "Rebuilt {$count} TM entries for {$locale}.\n";

			return;
		}

		echo "Rebuilt {$count} TM entries.\n";
	}
}
