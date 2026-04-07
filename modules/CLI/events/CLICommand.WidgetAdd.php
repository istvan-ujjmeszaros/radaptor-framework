<?php

class CLICommandWidgetAdd extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Add widget to slot';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Add a widget connection to a webpage slot.

			Usage: radaptor widget:add <path> --slot <slot> --widget <WidgetName> [--seq <n>] [--attributes-json <json>] [--settings-json <json>] [--json]

			Examples:
			  radaptor widget:add /login.html --slot content --widget Form --attributes-json '{"form_id":"UserLogin"}'
			  radaptor widget:add /comparison/ --slot content --widget PlainHtml --settings-json '{"content":"<h1>Hello</h1>"}' --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor widget:add <path> --slot <slot> --widget <WidgetName> [--seq <n>] [--attributes-json <json>] [--settings-json <json>] [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$slot = CLIOptionHelper::getRequiredOption('slot', $usage);
		$widget = CLIOptionHelper::getRequiredOption('widget', $usage);
		$seq = CLIOptionHelper::getNullableIntOption('seq');
		$attributes = CLIOptionHelper::getJsonOptionAsArray('attributes-json');
		$settings = CLIOptionHelper::getJsonOptionAsArray('settings-json');
		$json = CLIOptionHelper::isJson();

		try {
			$result = [
				'status' => 'success',
				'connection' => CmsResourceSpecService::addWidget($path, $slot, $widget, $seq, $attributes, $settings),
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Widget add failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Widget added as connection #{$result['connection']['connection_id']}.\n";
	}
}
