<?php

declare(strict_types=1);

class CLICommandResourceSpecSync extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Sync resource tree spec';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Apply a repo-managed resource spec. Defaults to dry-run; pass --apply to mutate the database.

			Usage: radaptor resource-spec:sync <spec-file> [--dry-run|--apply] [--json]

			Examples:
			  radaptor resource-spec:sync app/resource-specs/site.php
			  radaptor resource-spec:sync app/resource-specs/site.php --apply --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor resource-spec:sync <spec-file> [--dry-run|--apply] [--json]';
		CLIOptionHelper::assertNoApplyDryRunConflict($usage);
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$dry_run = !Request::hasArg('apply');
		$json = CLIOptionHelper::isJson();

		try {
			$spec = CmsResourceTreeSpecService::loadSpecFile($path);
			$result = $dry_run
				? CmsResourceTreeSpecService::syncSpec($spec, true)
				: CmsMutationAuditService::withContext(
					'resource-spec:sync',
					['path' => $path],
					static fn (): array => CmsResourceTreeSpecService::syncSpec($spec, false)
				);
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Resource spec sync failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		$mode = $dry_run ? 'dry-run' : 'apply';
		echo "Resource spec sync ({$mode}): {$result['status']}\n";

		if (isset($result['summary'])) {
			echo 'Summary: ' . json_encode($result['summary'], JSON_UNESCAPED_SLASHES) . "\n";
		} elseif (isset($result['after']['summary'])) {
			echo 'Summary: ' . json_encode($result['after']['summary'], JSON_UNESCAPED_SLASHES) . "\n";
		}

		if (isset($result['destructive_summary'])) {
			echo 'Destructive summary: ' . json_encode($result['destructive_summary'], JSON_UNESCAPED_SLASHES) . "\n";
		}
	}
}
