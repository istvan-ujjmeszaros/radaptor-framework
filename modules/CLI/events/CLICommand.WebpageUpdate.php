<?php

class CLICommandWebpageUpdate extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Update webpage';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Update webpage metadata, layout, or catcher flag.

			Usage: radaptor webpage:update <path> [--layout <layout_id>] [--title <title>] [--description <text>] [--keywords <text>] [--catcher 0|1] [--json]

			Examples:
			  radaptor webpage:update /comparison/ --title "Technical Comparison"
			  radaptor webpage:update /request-access/ --description "Placeholder" --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor webpage:update <path> [--layout <layout_id>] [--title <title>] [--description <text>] [--keywords <text>] [--catcher 0|1] [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$json = CLIOptionHelper::isJson();

		try {
			if (CmsPathHelper::resolveWebpage($path) === null) {
				throw new RuntimeException("Webpage not found: {$path}");
			}

			$spec = [
				'path' => $path,
				'attributes' => [],
			];

			$layout = CLIOptionHelper::getOption('layout');

			if ($layout !== '') {
				$spec['layout'] = $layout;
			}

			foreach (['title', 'description', 'keywords'] as $attribute_name) {
				$value = CLIOptionHelper::getOption($attribute_name);

				if ($value !== '') {
					$spec['attributes'][$attribute_name] = $value;
				}
			}

			$catcher = CLIOptionHelper::getOption('catcher');

			if ($catcher !== '') {
				$spec['catcher'] = $catcher === '1';
			}

			$page_id = CmsResourceSpecService::upsertWebpage($spec);
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

			echo "Webpage update failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Webpage updated: {$result['spec']['path']}\n";
	}
}
