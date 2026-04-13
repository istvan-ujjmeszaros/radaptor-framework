<?php

class CLICommandResourceMove extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Move resource';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Move a resource under a target parent folder.

			Usage: radaptor resource:move <path> --parent <target_path> [--position <n>] [--dry-run] [--json]

			Examples:
			  radaptor resource:move /comparison/ --parent /
			  radaptor resource:move /request-access/ --parent /archive/ --position 0 --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor resource:move <path> --parent <target_path> [--position <n>] [--dry-run] [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$parent_path = CLIOptionHelper::getRequiredOption('parent', $usage);
		$position = CLIOptionHelper::getNullableIntOption('position');
		$dry_run = Request::hasArg('dry-run');
		$json = CLIOptionHelper::isJson();

		try {
			$resource = CmsPathHelper::resolveResource($path);
			$parent = CmsPathHelper::resolveFolder($parent_path);

			if (!is_array($resource)) {
				throw new RuntimeException("Resource not found: {$path}");
			}

			if (!is_array($parent)) {
				throw new RuntimeException("Target parent folder not found: {$parent_path}");
			}

			if (($parent['node_type'] ?? '') === 'webpage') {
				throw new RuntimeException('Target parent must be a folder or root.');
			}

			$position ??= ResourceTreeHandler::countChildren((int) $parent['node_id']);

			if (!$dry_run && !ResourceTreeHandler::moveResourceEntryToPosition((int) $resource['node_id'], (int) $parent['node_id'], $position)) {
				throw new RuntimeException("Unable to move {$path}.");
			}

			$result = [
				'status' => 'success',
				'dry_run' => $dry_run,
				'resource_id' => (int) $resource['node_id'],
				'target_parent_id' => (int) $parent['node_id'],
				'position' => $position,
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Resource move failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo ($dry_run ? '[dry-run] ' : '') . "Move prepared for resource {$result['resource_id']}.\n";
	}
}
