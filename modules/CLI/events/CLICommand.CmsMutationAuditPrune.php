<?php

declare(strict_types=1);

class CLICommandCmsMutationAuditPrune extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Prune CMS mutation audit rows';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Prune cms_mutation_audit rows older than the retention window. Defaults to dry-run; pass --apply to delete.

			Usage: radaptor cms:mutation-audit-prune [--days <days>] [--dry-run|--apply] [--json]

			Examples:
			  radaptor cms:mutation-audit-prune --json
			  radaptor cms:mutation-audit-prune --days 180 --apply --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor cms:mutation-audit-prune [--days <days>] [--dry-run|--apply] [--json]';
		CLIOptionHelper::assertNoApplyDryRunConflict($usage);
		$days = CLIOptionHelper::getNullableIntOption('days') ?? 180;
		$dry_run = !Request::hasArg('apply');
		$json = CLIOptionHelper::isJson();

		try {
			$result = $dry_run
				? CmsMutationAuditService::prune($days, true)
				: CmsMutationAuditService::withContext(
					'cms:mutation-audit-prune',
					['days' => $days],
					static fn (): array => CmsMutationAuditService::prune($days, false)
				);
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "CMS mutation audit prune failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo ($dry_run ? '[dry-run] ' : '') . "Matched {$result['matched_rows']} audit row(s), deleted {$result['deleted_rows']}.\n";
	}
}
