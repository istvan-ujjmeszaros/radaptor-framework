<?php

class CLICommandMenuDelete extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Delete menu entry';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Delete a main/admin menu entry, optionally recursively.

			Usage: radaptor menu:delete <id> --type main|admin [--recursive] [--dry-run|--apply] [--json]

			Examples:
			  radaptor menu:delete 7 --type admin
			  radaptor menu:delete 3 --type main --recursive --apply --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor menu:delete <id> --type main|admin [--recursive] [--dry-run|--apply] [--json]';
		CLIOptionHelper::assertNoApplyDryRunConflict($usage);
		$id = (int) CLIOptionHelper::getMainArgOrAbort($usage);
		$type = CLIOptionHelper::getRequiredOption('type', $usage);
		$recursive = Request::hasArg('recursive');
		$dry_run = !Request::hasArg('apply');
		$json = CLIOptionHelper::isJson();

		try {
			$result = [
				'status' => 'success',
				'dry_run' => $dry_run,
				'deleted' => $dry_run
					? false
					: CmsMutationAuditService::withContext(
						'menu:delete',
						['type' => $type, 'id' => $id, 'recursive' => $recursive],
						static fn (): bool => CmsMenuService::delete($type, $id, $recursive)
					),
				'summary' => [
					'deleted_menu_entries' => $dry_run ? 0 : 1,
				],
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Menu delete failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo ($dry_run ? '[dry-run] ' : '') . "Menu entry delete prepared.\n";
	}
}
