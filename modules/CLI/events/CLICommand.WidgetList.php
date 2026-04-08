<?php

class CLICommandWidgetList extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'List widget placements';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			List widget connections for a webpage, optionally filtered by slot.

			Usage: radaptor widget:list <path> [--slot <slot>] [--json]

			Examples:
			  radaptor widget:list /login.html
			  radaptor widget:list /admin/index.html --slot content --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'Path', 'type' => 'main_arg', 'required' => true],
			['name' => 'slot', 'label' => 'Slot', 'type' => 'option', 'required' => false],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor widget:list <path> [--slot <slot>] [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$slot = CLIOptionHelper::getOption('slot');
		$json = CLIOptionHelper::isJson();

		try {
			$result = [
				'status' => 'success',
				'path' => CmsPathHelper::normalizePath($path),
				'widgets' => CmsResourceSpecService::listWidgets($path, $slot !== '' ? $slot : null),
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Widget list failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		foreach ($result['widgets'] as $widget) {
			echo "{$widget['slot']}\t{$widget['seq']}\t{$widget['widget']}\t#{$widget['connection_id']}\n";
		}
	}
}
