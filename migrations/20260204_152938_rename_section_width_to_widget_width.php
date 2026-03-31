<?php

/**
 * Migration: Rename section_width attribute to widget_width.
 *
 * This migration updates the attribute name from the legacy 'section_width'
 * to 'widget_width' for consistency with the widget terminology cleanup.
 */
class Migration_20260204_152938_rename_section_width_to_widget_width
{
	public function run(): void
	{
		DbHelper::runCustomQuery(
			"UPDATE attributes SET param_name = ? WHERE param_name = ?",
			['widget_width', 'section_width']
		);
	}

	public function getDescription(): string
	{
		return 'Rename section_width attribute to widget_width for terminology cleanup';
	}
}
