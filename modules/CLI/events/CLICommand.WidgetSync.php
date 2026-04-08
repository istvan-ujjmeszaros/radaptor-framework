<?php

class CLICommandWidgetSync extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Sync slot widgets';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Reconcile one slot against a JSON widget spec list.

			Usage: radaptor widget:sync <path> --slot <slot> --spec-json <json> [--dry-run] [--json]

			Examples:
			  radaptor widget:sync /login.html --slot content --spec-json '[{"widget":"Form","attributes":{"form_id":"UserLogin"}}]'
			  radaptor widget:sync /comparison/ --slot content --spec-json '[{"widget":"PlainHtml","settings":{"content":"<h1>Hello</h1>"}}]' --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor widget:sync <path> --slot <slot> --spec-json <json> [--dry-run] [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$slot = CLIOptionHelper::getRequiredOption('slot', $usage);
		$spec = CLIOptionHelper::getJsonOptionAsArray('spec-json', true, $usage);
		$dry_run = Request::hasArg('dry-run');
		$json = CLIOptionHelper::isJson();

		try {
			$connections = $dry_run ? [] : CmsResourceSpecService::syncWidgetSlot($path, $slot, array_values($spec));
			$result = [
				'status' => 'success',
				'dry_run' => $dry_run,
				'connections' => $connections,
			];
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Widget sync failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo ($dry_run ? '[dry-run] ' : '') . "Slot {$slot} synced.\n";
	}
}
