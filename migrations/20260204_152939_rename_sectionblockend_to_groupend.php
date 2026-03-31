<?php

/**
 * Migration: Rename SectionBlockEnd widget to GroupEnd.
 *
 * This migration updates the widget_name from the legacy 'SectionBlockEnd'
 * to 'GroupEnd' for consistency with the widget terminology cleanup.
 */
class Migration_20260204_152939_rename_sectionblockend_to_groupend
{
	public function run(): void
	{
		DbHelper::runCustomQuery(
			"UPDATE widget_connections SET widget_name = ? WHERE widget_name = ?",
			['GroupEnd', 'SectionBlockEnd']
		);
	}

	public function getDescription(): string
	{
		return 'Rename SectionBlockEnd widget to GroupEnd for terminology cleanup';
	}
}
