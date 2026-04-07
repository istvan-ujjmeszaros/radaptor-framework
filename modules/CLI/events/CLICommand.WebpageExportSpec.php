<?php

class CLICommandWebpageExportSpec extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Export webpage spec';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Export one webpage to the JSON seed-spec shape.

			Usage: radaptor webpage:export-spec <path> [--json]

			Examples:
			  radaptor webpage:export-spec /login.html
			  radaptor webpage:export-spec /comparison/ --json
			DOC;
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor webpage:export-spec <path> [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);

		try {
			$result = [
				'status' => 'success',
				'spec' => CmsResourceSpecService::exportWebpageSpec($path),
			];
		} catch (Throwable $exception) {
			CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

			return;
		}

		CLIOptionHelper::writeJson($result);
	}
}
