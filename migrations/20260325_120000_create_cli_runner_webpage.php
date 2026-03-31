<?php

/**
 * Migration: Create the CLI Runner webpage at /admin/developer/cli-runner.html.
 *
 * Uses CMS API to create the /admin/developer/ folder (if missing) and the
 * webpage with the CLIRunner widget assigned to the content slot.
 */
class Migration_20260325_120000_create_cli_runner_webpage
{
	public function run(): void
	{
		// Check if the page already exists
		$existing = ResourceTreeHandler::getResourceTreeEntryData('/admin/developer/', 'cli-runner.html');

		if ($existing !== null) {
			return; // Already exists
		}

		$page_id = ResourceTypeWebpage::generateWebpageForWidget('CLIRunner');

		if ($page_id === false || $page_id === null) {
			throw new RuntimeException('Failed to create CLI Runner webpage');
		}

		ResourceTypeWebpage::placeWidgetToWebpage($page_id, 'CLIRunner');
	}
}
