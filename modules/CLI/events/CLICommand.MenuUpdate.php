<?php

class CLICommandMenuUpdate extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Update menu entry';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Update a main/admin menu entry.

			Usage: radaptor menu:update <id> --type main|admin [--title <title>] [--page <path> | --url <url>] [--json]

			Examples:
			  radaptor menu:update 4 --type admin --title "User management"
			  radaptor menu:update 4 --type main --url https://example.com --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor menu:update <id> --type main|admin [--title <title>] [--page <path> | --url <url>] [--json]';
		$id = (int) CLIOptionHelper::getMainArgOrAbort($usage);
		$json = CLIOptionHelper::isJson();

		try {
			$changes = [];

			if (CLIOptionHelper::getOption('title') !== '') {
				$changes['title'] = CLIOptionHelper::getOption('title');
			}

			if (CLIOptionHelper::getOption('page') !== '') {
				$changes['page_path'] = CLIOptionHelper::getOption('page');
			}

			if (CLIOptionHelper::getOption('url') !== '') {
				$changes['url'] = CLIOptionHelper::getOption('url');
			}

			$result = [
				'status' => 'success',
				'item' => CmsMenuService::update(
					CLIOptionHelper::getRequiredOption('type', $usage),
					$id,
					$changes
				),
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Menu update failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Menu entry updated: #{$result['item']['node_id']}\n";
	}
}
