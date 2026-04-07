<?php

class CLICommandResourceRename extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Rename resource';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Rename a resource or folder by path.

			Usage: radaptor resource:rename <path> --name <new_name> [--json]

			Examples:
			  radaptor resource:rename /comparison/ --name compare
			  radaptor resource:rename /foo/bar.html --name baz.html --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor resource:rename <path> --name <new_name> [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$new_name = CLIOptionHelper::getRequiredOption('name', $usage);
		$json = CLIOptionHelper::isJson();

		try {
			$resource = CmsPathHelper::resolveResource($path);

			if (!is_array($resource)) {
				throw new RuntimeException("Resource not found: {$path}");
			}

			if (($resource['node_type'] ?? '') === 'root') {
				throw new RuntimeException('The domain root cannot be renamed from this command.');
			}

			ResourceTreeHandler::updateResourceTreeEntry([
				'resource_name' => $new_name,
			], (int) $resource['node_id']);

			$result = [
				'status' => 'success',
				'resource' => CmsResourceSpecService::exportResourceSpec(
					ResourceTreeHandler::getPathFromId((int) $resource['node_id'])
				),
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Resource rename failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Renamed to: {$result['resource']['path']}\n";
	}
}
