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

			Usage: radaptor widget:remove <path> --slot <slot> (--connection-id <id> | --widget <WidgetName>) [--all] [--dry-run|--apply] [--json]

			Examples:
			  radaptor widget:remove /login.html --slot content --widget AdminMenu
			  radaptor widget:remove /foo/ --slot content --widget PlainHtml --all --apply --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor widget:remove <path> --slot <slot> (--connection-id <id> | --widget <WidgetName>) [--all] [--dry-run|--apply] [--json]';
		CLIOptionHelper::assertNoApplyDryRunConflict($usage);
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$slot = CLIOptionHelper::getRequiredOption('slot', $usage);
		$connection_id = CLIOptionHelper::getNullableIntOption('connection-id');
		$widget_name = CLIOptionHelper::getOption('widget');
		$all = Request::hasArg('all');
		$dry_run = !Request::hasArg('apply');
		$json = CLIOptionHelper::isJson();

		if ($connection_id === null && $widget_name === '') {
			Kernel::abort($usage);
		}

		try {
			if ($dry_run) {
				$result = CmsResourceSpecService::previewRemoveWidget($path, $slot, $connection_id, $widget_name !== '' ? $widget_name : null, $all);
			} else {
				$removed = CmsMutationAuditService::withContext(
					'widget:remove',
					[
						'path' => $path,
						'slot' => $slot,
						'connection_id' => $connection_id,
						'widget' => $widget_name,
						'all' => $all,
					],
					static fn (): array => CmsResourceSpecService::removeWidget($path, $slot, $connection_id, $widget_name !== '' ? $widget_name : null, $all)
				);
				$result = [
					'status' => 'success',
					'dry_run' => false,
					'removed' => $removed,
					'summary' => [
						'touched_pages' => 1,
						'touched_slots' => 1,
						'deleted_widgets' => count($removed),
					],
				];
			}
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

		$count = (int) ($result['summary']['deleted_widgets'] ?? count($result['removed'] ?? []));
		echo ($dry_run ? '[dry-run] ' : '') . "Remove {$count} widget connection(s).\n";
	}
}
