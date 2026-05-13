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

			Usage: radaptor widget:sync <path> --slot <slot> --spec-json <json> [--dry-run|--apply] [--json]

			Examples:
			  radaptor widget:sync /login.html --slot content --spec-json '[{"widget":"Form","attributes":{"form_id":"UserLogin"}}]'
			  radaptor widget:sync /comparison/ --slot content --spec-json '[{"widget":"PlainHtml","settings":{"content":"<h1>Hello</h1>"}}]' --apply --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor widget:sync <path> --slot <slot> --spec-json <json> [--dry-run|--apply] [--json]';
		CLIOptionHelper::assertNoApplyDryRunConflict($usage);
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$slot = CLIOptionHelper::getRequiredOption('slot', $usage);
		$spec = CLIOptionHelper::getJsonOptionAsArray('spec-json', true, $usage);
		$dry_run = !Request::hasArg('apply');
		$json = CLIOptionHelper::isJson();

		try {
			if ($dry_run) {
				$result = CmsResourceSpecService::previewWidgetSlotSync($path, $slot, array_values($spec));
			} else {
				$result = CmsResourceSpecService::syncWidgetSlotWithSummary($path, $slot, array_values($spec));
			}
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

		echo ($dry_run ? '[dry-run] ' : '') . "Slot {$slot} sync " . ($dry_run ? 'previewed' : 'applied') . ".\n";

		if (isset($result['summary'])) {
			echo 'Summary: ' . json_encode($result['summary'], JSON_UNESCAPED_SLASHES) . "\n";
		}
	}
}
