<?php

class CLICommandResourceList extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'List resources';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			List direct child resources under a folder/resource path.

			Usage: radaptor resource:list [path] [--json]

			Examples:
			  radaptor resource:list
			  radaptor resource:list /admin/
			  radaptor resource:list /comparison/ --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'Path', 'type' => 'main_arg', 'required' => false],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$path = Request::getMainArg() ?? '/';
		$json = CLIOptionHelper::isJson();

		try {
			$result = [
				'status' => 'success',
				'path' => CmsPathHelper::normalizePath($path),
				'resources' => CmsResourceSpecService::listResources($path),
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Resource list failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		foreach ($result['resources'] as $resource) {
			echo "{$resource['node_id']}\t{$resource['node_type']}\t{$resource['path']}{$resource['resource_name']}\n";
		}
	}
}
