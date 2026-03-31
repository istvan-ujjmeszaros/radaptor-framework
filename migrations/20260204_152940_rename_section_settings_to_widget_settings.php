<?php

/**
 * Migration: Rename _section_settings resource to _widget_settings.
 *
 * This migration updates the resource_name from the legacy '_section_settings'
 * to '_widget_settings' for consistency with the widget terminology cleanup.
 */
class Migration_20260204_152940_rename_section_settings_to_widget_settings
{
	public function run(): void
	{
		DbHelper::runCustomQuery(
			"UPDATE attributes SET resource_name = ? WHERE resource_name = ?",
			['_widget_settings', '_section_settings']
		);
	}

	public function getDescription(): string
	{
		return 'Rename _section_settings resource to _widget_settings for terminology cleanup';
	}
}
