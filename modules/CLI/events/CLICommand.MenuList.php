<?php

class CLICommandMenuList extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'List menu tree';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			List main or admin menu entries.

			Usage: radaptor menu:list --type main|admin [--json]

			Examples:
			  radaptor menu:list --type admin
			  radaptor menu:list --type main --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'type', 'label' => 'Menu type', 'type' => 'option', 'required' => true, 'choices' => ['main' => 'main', 'admin' => 'admin']],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor menu:list --type main|admin [--json]';
		$type = CLIOptionHelper::getRequiredOption('type', $usage);
		$json = CLIOptionHelper::isJson();

		try {
			$result = [
				'status' => 'success',
				'type' => CmsMenuService::normalizeType($type),
				'items' => CmsMenuService::list($type),
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Menu list failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		foreach ($result['items'] as $item) {
			echo "{$item['node_id']}\t{$item['parent_id']}\t{$item['node_name']}\n";
		}
	}
}
