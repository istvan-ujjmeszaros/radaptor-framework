<?php

class CLICommandResourceExportSpec extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Export resource spec';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Export a folder or webpage into the JSON seed-spec shape.

			Usage: radaptor resource:export-spec <path> [--json]

			Examples:
			  radaptor resource:export-spec /comparison/
			  radaptor resource:export-spec /admin/ --json
			DOC;
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor resource:export-spec <path> [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$json = CLIOptionHelper::isJson();

		try {
			$result = [
				'status' => 'success',
				'spec' => CmsResourceSpecService::exportResourceSpec($path),
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Resource export failed: {$exception->getMessage()}\n";

			return;
		}

		CLIOptionHelper::writeJson($result);
	}
}
