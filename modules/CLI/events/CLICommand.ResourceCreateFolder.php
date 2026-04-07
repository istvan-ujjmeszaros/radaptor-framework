<?php

class CLICommandResourceCreateFolder extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Create folder';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Create or ensure a folder exists by path.

			Usage: radaptor resource:create-folder <path> [--json]

			Examples:
			  radaptor resource:create-folder /comparison/
			  radaptor resource:create-folder /docs/reference/ --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor resource:create-folder <path> [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$json = CLIOptionHelper::isJson();

		try {
			$folder_id = CmsResourceSpecService::upsertFolder(['path' => $path]);
			$result = [
				'status' => 'success',
				'folder_id' => $folder_id,
				'resource' => CmsResourceSpecService::exportFolderSpec($path),
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Folder creation failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Folder ensured: {$result['resource']['path']} (node {$folder_id})\n";
	}
}
