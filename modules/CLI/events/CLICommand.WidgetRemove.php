<?php

class CLICommandWidgetRemove extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Remove widget connection';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Remove one or more widget placements from a webpage slot.

			Usage: radaptor widget:remove <path> --slot <slot> (--connection-id <id> | --widget <WidgetName>) [--all] [--dry-run] [--json]

			Examples:
			  radaptor widget:remove /login.html --slot content --widget AdminMenu
			  radaptor widget:remove /foo/ --slot content --widget PlainHtml --all --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor widget:remove <path> --slot <slot> (--connection-id <id> | --widget <WidgetName>) [--all] [--dry-run] [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$slot = CLIOptionHelper::getRequiredOption('slot', $usage);
		$connection_id = CLIOptionHelper::getNullableIntOption('connection-id');
		$widget_name = CLIOptionHelper::getOption('widget');
		$all = Request::hasArg('all');
		$dry_run = Request::hasArg('dry-run');
		$json = CLIOptionHelper::isJson();

		if ($connection_id === null && $widget_name === '') {
			Kernel::abort($usage);
		}

		try {
			$removed = $dry_run
				? []
				: CmsResourceSpecService::removeWidget($path, $slot, $connection_id, $widget_name !== '' ? $widget_name : null, $all);
			$result = [
				'status' => 'success',
				'dry_run' => $dry_run,
				'removed' => $removed,
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Widget remove failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo ($dry_run ? '[dry-run] ' : '') . "Removed " . count($result['removed']) . " widget connection(s).\n";
	}
}
