<?php

class CLICommandWidgetStatus extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show widget integrity status';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Show registered widgets and their URL placement status.

			Usage: radaptor widget:status [WidgetName] [--json]
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'Widget name', 'type' => 'main_arg', 'required' => false],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$widget = Request::getMainArg();
		$widget = is_string($widget) && !str_starts_with($widget, '--') ? $widget : null;

		try {
			if (!class_exists(CmsIntegrityInspector::class)) {
				throw new RuntimeException('CMS integrity inspector is not available. Install or enable core:cms to use widget diagnostics.');
			}

			$result = CmsIntegrityInspector::inspectWidgets($widget);
		} catch (Throwable $exception) {
			self::renderError($exception);

			return;
		}

		if (Request::hasArg('json')) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Widget status: {$result['status']}\n";

		foreach ($result['widgets'] as $widget_row) {
			$url = $widget_row['url'] ?? '(no URL)';
			echo "  - {$widget_row['widget']}: {$widget_row['status']} {$url}\n";
		}
	}

	private static function renderError(Throwable $exception): void
	{
		if (Request::hasArg('json')) {
			CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

			return;
		}

		echo "Widget status check failed: {$exception->getMessage()}\n";
	}
}
