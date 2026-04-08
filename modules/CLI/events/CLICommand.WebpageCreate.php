<?php

class CLICommandWebpageCreate extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Create webpage';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Create a webpage by path and layout.

			Usage: radaptor webpage:create <path> --layout <layout_id> [--title <title>] [--description <text>] [--keywords <text>] [--catcher 0|1] [--json]

			Examples:
			  radaptor webpage:create /comparison/ --layout portal_marketing --title "Technical Comparison"
			  radaptor webpage:create /foo/bar.html --layout public_empty --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor webpage:create <path> --layout <layout_id> [--title <title>] [--description <text>] [--keywords <text>] [--catcher 0|1] [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$layout = CLIOptionHelper::getRequiredOption('layout', $usage);
		$json = CLIOptionHelper::isJson();

		try {
			if (CmsPathHelper::resolveWebpage($path) !== null) {
				throw new RuntimeException("Webpage already exists: {$path}");
			}

			$page_id = CmsResourceSpecService::upsertWebpage([
				'path' => $path,
				'layout' => $layout,
				'catcher' => CLIOptionHelper::getOption('catcher') === '1',
				'attributes' => array_filter([
					'title' => CLIOptionHelper::getOption('title'),
					'description' => CLIOptionHelper::getOption('description'),
					'keywords' => CLIOptionHelper::getOption('keywords'),
				], static fn (string $value): bool => trim($value) !== ''),
			]);

			$result = [
				'status' => 'success',
				'page_id' => $page_id,
				'spec' => CmsResourceSpecService::exportWebpageSpec($path),
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Webpage create failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Webpage created: {$result['spec']['path']} (node {$page_id})\n";
	}
}
