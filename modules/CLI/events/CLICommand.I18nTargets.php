<?php

class CLICommandI18nTargets extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'List i18n seed targets';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			List all shipped i18n seed targets discovered for the current app/runtime.

			Usage: radaptor i18n:targets [--all-packages] [--json]
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function run(): void
	{
		$json = Request::hasArg('json');
		$all_packages = Request::hasArg('all-packages');
		$targets = I18nSeedTargetDiscovery::describeTargets([
			'all_packages' => $all_packages,
		]);

		if ($json) {
			echo json_encode([
				'status' => 'success',
				'scope' => $all_packages ? 'all_packages' : 'active',
				'targets' => $targets,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

			return;
		}

		echo 'i18n seed targets (' . ($all_packages ? 'all packages audit' : 'active sync scope') . '): ' . count($targets) . "\n";

		foreach ($targets as $target) {
			$locales = implode(',', $target['locales'] ?? []);
			echo "{$target['group_type']}:{$target['group_id']} {$target['status']} files={$target['files']} rows={$target['rows']} locales={$locales}\n";
			echo "  {$target['input_dir']}\n";
		}
	}
}
