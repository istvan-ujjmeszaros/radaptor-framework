<?php

class CLICommandIntegritySummary extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show CMS integrity summary';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Show read-only CMS integrity status for registered layouts, forms, and widgets.

			Usage: radaptor integrity:summary [--json]
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		try {
			if (!class_exists(CmsIntegrityInspector::class)) {
				throw new RuntimeException('CMS integrity inspector is not available. Install or enable core:cms to use integrity diagnostics.');
			}

			$result = CmsIntegrityInspector::inspectSummary();
		} catch (Throwable $exception) {
			self::renderError($exception);

			return;
		}

		if (Request::hasArg('json')) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "CMS integrity: {$result['status']}\n";

		foreach ($result['checks'] as $check) {
			echo "  - {$check['name']}: {$check['status']} ({$check['ok']} ok, {$check['warning']} warning, {$check['error']} error)\n";
		}
	}

	private static function renderError(Throwable $exception): void
	{
		if (Request::hasArg('json')) {
			CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

			return;
		}

		echo "Integrity summary failed: {$exception->getMessage()}\n";
	}
}
