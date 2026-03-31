<?php

class CLICommandBuildAssets extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Build package assets';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Symlink managed public assets declared by installed core/theme packages.

			Usage: radaptor build:assets [--dry-run] [--json]
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}
	public function getRiskLevel(): string
	{
		return 'build';
	}

	public function run(): void
	{
		$dry_run = Request::hasArg('dry-run');
		$json = Request::hasArg('json');

		try {
			$result = PackageAssetsBuilder::build($dry_run);
		} catch (Throwable $e) {
			if ($json) {
				echo json_encode([
					'status' => 'error',
					'message' => $e->getMessage(),
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

				return;
			}

			echo "Asset build failed: {$e->getMessage()}\n";

			return;
		}

		if ($json) {
			echo json_encode([
				'status' => 'success',
				...$result,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		$prefix = $dry_run ? '[dry-run] ' : '';
		echo "{$prefix}Links created: {$result['links_created']}\n";
		echo "{$prefix}Links removed: {$result['links_removed']}\n";
		echo "{$prefix}Links unchanged: {$result['links_unchanged']}\n";

		foreach ($result['links'] as $link) {
			echo "{$prefix}{$link['action']}: {$link['target']} -> {$link['source']}\n";
		}

		if ($dry_run) {
			echo "(dry-run — no changes written)\n";
		}
	}
}
