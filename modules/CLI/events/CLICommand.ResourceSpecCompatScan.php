<?php

declare(strict_types=1);

class CLICommandResourceSpecCompatScan extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Scan resource specs for slot-sync compatibility risks';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Read-only helper for CMS 0.1.24+ resource-spec slot semantics.

			BREAKING: slots is now partial by default: only mentioned slots are touched,
			omitted slots are preserved. Add replace_slots: true for the previous
			wipe-on-omit behavior.

			Usage: radaptor resource-spec:compat-scan <file-or-directory> [--json]

			Examples:
			  radaptor resource-spec:compat-scan app/resource-specs --json
			DOC;
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor resource-spec:compat-scan <file-or-directory> [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$json = CLIOptionHelper::isJson();

		try {
			$result = CmsResourceSpecCompatScanService::scan($path);
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Resource spec compat scan failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Scanned {$result['scanned_files']} file(s). Potential legacy specs: {$result['potential_legacy_specs']}.\n";

		foreach ($result['issues'] as $issue) {
			echo "  - {$issue['file']}: {$issue['path']}\n";
		}
	}
}
