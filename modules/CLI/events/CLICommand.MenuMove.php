<?php

class CLICommandMenuMove extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Move menu entry';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Move a menu entry under a new parent at a target position.

			Usage: radaptor menu:move <id> --type main|admin --parent-id <id> [--position <n>] [--dry-run|--apply] [--json]

			Examples:
			  radaptor menu:move 4 --type admin --parent-id 1 --position 0
			  radaptor menu:move 2 --type main --parent-id 0 --apply --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor menu:move <id> --type main|admin --parent-id <id> [--position <n>] [--dry-run|--apply] [--json]';
		CLIOptionHelper::assertNoApplyDryRunConflict($usage);
		$id = (int) CLIOptionHelper::getMainArgOrAbort($usage);
		$parent_id = CLIOptionHelper::getNullableIntOption('parent-id');
		$position = CLIOptionHelper::getNullableIntOption('position') ?? 0;
		$dry_run = !Request::hasArg('apply');
		$json = CLIOptionHelper::isJson();

		if ($parent_id === null) {
			Kernel::abort($usage);
		}

		try {
			$type = CLIOptionHelper::getRequiredOption('type', $usage);
			$result = [
				'status' => 'success',
				'dry_run' => $dry_run,
				'item' => $dry_run
					? ['node_id' => $id, 'parent_id' => $parent_id, 'position' => $position]
					: CmsMutationAuditService::withContext(
						'menu:move',
						['type' => $type, 'id' => $id, 'parent_id' => $parent_id, 'position' => $position],
						static fn (): array => CmsMenuService::move($type, $id, $parent_id, $position)
					),
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Menu move failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo ($dry_run ? '[dry-run] ' : '') . "Menu entry move prepared.\n";
	}
}
