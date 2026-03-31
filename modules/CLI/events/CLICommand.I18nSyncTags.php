<?php

/**
 * Sync tag slugs and tag i18n message rows.
 *
 * Usage: radaptor i18n:sync-tags [--dry-run] [--json]
 */
class CLICommandI18nSyncTags extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Sync tag i18n messages';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Sync tag slugs and tag i18n message rows.

			Usage: radaptor i18n:sync-tags [--dry-run] [--json]

			Examples:
			  radaptor i18n:sync-tags
			  radaptor i18n:sync-tags --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$dryRun = Request::hasArg('dry-run');
		$json = Request::hasArg('json');

		$summary = EntityTag::syncAllTagI18nMessages($dryRun);

		if ($json) {
			echo json_encode([
				'dry_run' => $dryRun,
				'summary' => $summary,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

			return;
		}

		$prefix = $dryRun ? '[dry-run] ' : '';

		echo "{$prefix}Processed: {$summary['processed']}\n";
		echo "{$prefix}Slug backfilled: {$summary['slug_backfilled']}\n";
		echo "{$prefix}Messages created: {$summary['messages_created']}\n";
		echo "{$prefix}Messages updated: {$summary['messages_updated']}\n";
		echo "{$prefix}Unchanged: {$summary['unchanged']}\n";

		if ($dryRun) {
			echo "(dry-run — no changes written)\n";
		}
	}
}
