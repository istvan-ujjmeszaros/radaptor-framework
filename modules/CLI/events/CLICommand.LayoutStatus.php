<?php

class CLICommandLayoutStatus extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show layout integrity status';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Show registered layout files and their render contract status.

			Usage: radaptor layout:status [layout_id] [--json]

			Examples:
			  radaptor layout:status
			  radaptor layout:status admin_default --json
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

		try {
			if (!class_exists(CmsIntegrityInspector::class)) {
				throw new RuntimeException('CMS integrity inspector is not available. Install or enable core:cms to use layout diagnostics.');
			}

			$result = CmsIntegrityInspector::inspectLayouts($layout);
		} catch (Throwable $exception) {
			self::renderError($exception);

			return;
		}

		if (Request::hasArg('json')) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Layout status: {$result['status']}\n";

		foreach ($result['layouts'] as $layout_row) {
			echo "  - {$layout_row['layout']}: {$layout_row['status']} ({$layout_row['template_count']} template file(s), {$layout_row['usage_count']} page(s))\n";
		}
	}

	private static function renderError(Throwable $exception): void
	{
		if (Request::hasArg('json')) {
			CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

			return;
		}

		echo "Layout status check failed: {$exception->getMessage()}\n";
	}
}
