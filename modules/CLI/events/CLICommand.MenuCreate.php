<?php

class CLICommandMenuCreate extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Create menu entry';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Create a main/admin menu entry.

			Usage: radaptor menu:create --type main|admin --title <title> [--parent-id <id>] [--page <path> | --url <url>] [--position <n>] [--json]

			Examples:
			  radaptor menu:create --type admin --title "Users" --page /admin/user-list.html
			  radaptor menu:create --type main --title "External docs" --url https://example.com --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor menu:create --type main|admin --title <title> [--parent-id <id>] [--page <path> | --url <url>] [--position <n>] [--json]';
		$json = CLIOptionHelper::isJson();

		try {
			$result = [
				'status' => 'success',
				'item' => CmsMenuService::create(
					CLIOptionHelper::getRequiredOption('type', $usage),
					CLIOptionHelper::getRequiredOption('title', $usage),
					CLIOptionHelper::getNullableIntOption('parent-id') ?? 0,
					CLIOptionHelper::getOption('page') !== '' ? CLIOptionHelper::getOption('page') : null,
					CLIOptionHelper::getOption('url') !== '' ? CLIOptionHelper::getOption('url') : null,
					CLIOptionHelper::getNullableIntOption('position')
				),
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Menu create failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Menu entry created: #{$result['item']['node_id']}\n";
	}
}
