<?php

/**
 * Find all pages where a widget is placed.
 *
 * Usage: radaptor widget:urls <WidgetName> [--json]
 *
 * Examples:
 *   radaptor widget:urls ContactPersonList
 *   radaptor widget:urls Form --json
 */
class CLICommandWidgetUrls extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Find pages using a widget';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Find all pages where a widget is placed.

			Usage: radaptor widget:urls <WidgetName> [--json]

			Examples:
			  radaptor widget:urls ContactPersonList
			  radaptor widget:urls Form --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'Widget name', 'type' => 'main_arg', 'required' => true],
		];
	}

	public function run(): void
	{
		$widget_name = Request::getMainArg();

		if (is_null($widget_name)) {
			Kernel::abort("Usage: radaptor widget:urls <WidgetName> [--json]");
		}

		$json_mode = Request::hasArg('json');
		$pages = CLIWebpageHelper::getWidgetPlacements($widget_name);

		if (empty($pages)) {
			if ($json_mode) {
				echo json_encode([
					'widget' => $widget_name,
					'pages' => [],
				], JSON_PRETTY_PRINT) . "\n";
			} else {
				echo "Widget \"{$widget_name}\" not found on any pages.\n";
			}

			return;
		}

		if ($json_mode) {
			echo json_encode([
				'widget' => $widget_name,
				'pages' => $pages,
			], JSON_PRETTY_PRINT) . "\n";
		} else {
			echo "Widget \"{$widget_name}\" found on " . count($pages) . " page(s):\n";

			foreach ($pages as $page) {
				echo "  - {$page['path']} (page_id: {$page['page_id']}, slot: {$page['slot']}, seq: {$page['seq']})\n";
			}
		}
	}
}
