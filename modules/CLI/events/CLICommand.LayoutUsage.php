<?php

class CLICommandLayoutUsage extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Find layout usage';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Find webpages that use a layout, or list usage counts for all layouts.

			Usage: radaptor layout:usage [layout_id] [--json]

			Examples:
			  radaptor layout:usage admin_nomenu
			  radaptor layout:usage --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'Layout id', 'type' => 'main_arg', 'required' => false],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$layout = Request::getMainArg();
		$layout = is_string($layout) && !str_starts_with($layout, '--') ? $layout : null;
		$result = CmsUsageInspector::inspectLayoutUsage(is_string($layout) ? $layout : null);

		if (Request::hasArg('json')) {
			echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		if (($result['layout'] ?? null) === null) {
			echo "Layout usage counts:\n";

			foreach ($result['layout_counts'] as $row) {
				echo "  - {$row['layout']}: {$row['count']}\n";
			}

			return;
		}

		echo "Layout \"{$result['layout']}\" is used on {$result['count']} page(s).\n";

		foreach ($result['pages'] as $page) {
			echo "  - {$page['path']} (page_id: {$page['page_id']})\n";
		}
	}
}
