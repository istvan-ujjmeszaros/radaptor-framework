<?php

class CLICommandWidgetUpdate extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Update widget connection';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Update one widget connection by ID.

			Usage: radaptor widget:update <connection-id> [--slot <slot>] [--seq <n>] [--attributes-json <json>] [--settings-json <json>] [--json]

			Examples:
			  radaptor widget:update 12 --attributes-json '{"form_id":"UserLogin"}'
			  radaptor widget:update 12 --slot content --seq 0 --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor widget:update <connection-id> [--slot <slot>] [--seq <n>] [--attributes-json <json>] [--settings-json <json>] [--json]';
		$main_arg = CLIOptionHelper::getMainArgOrAbort($usage);
		$connection_id = is_numeric($main_arg) ? (int) $main_arg : CLIOptionHelper::getNullableIntOption('connection-id');
		$json = CLIOptionHelper::isJson();

		if (!is_int($connection_id) || $connection_id <= 0) {
			Kernel::abort($usage);
		}

		try {
			$attributes = CLIOptionHelper::getOption('attributes-json') !== ''
				? CLIOptionHelper::getJsonOptionAsArray('attributes-json')
				: null;
			$settings = CLIOptionHelper::getOption('settings-json') !== ''
				? CLIOptionHelper::getJsonOptionAsArray('settings-json')
				: null;
			$result = [
				'status' => 'success',
				'connection' => CmsResourceSpecService::updateWidgetConnection(
					$connection_id,
					CLIOptionHelper::getOption('slot') !== '' ? CLIOptionHelper::getOption('slot') : null,
					CLIOptionHelper::getNullableIntOption('seq'),
					$attributes,
					$settings
				),
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Widget update failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Widget connection updated: #{$result['connection']['connection_id']}\n";
	}
}
