<?php

/**
 * List all migrations and their status.
 *
 * Usage: radaptor migrate:status [--json]
 *
 * Examples:
 *   radaptor migrate:status
 *   radaptor migrate:status --json
 */
class CLICommandMigrateStatus extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show migration status';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			List all migrations and their status (applied / pending).

			Usage: radaptor migrate:status [--json]

			Examples:
			  radaptor migrate:status
			  radaptor migrate:status --json
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
		$status = MigrationRunner::getStatus();
		$json_mode = Request::hasArg('json');

		if ($json_mode) {
			echo json_encode([
				'migrations' => $status,
				'summary' => [
					'total' => count($status),
					'applied' => count(array_filter($status, fn ($m) => $m['status'] === 'applied')),
					'pending' => count(array_filter($status, fn ($m) => $m['status'] === 'pending')),
				],
			], JSON_PRETTY_PRINT) . "\n";

			return;
		}

		if (empty($status)) {
			echo "No migrations found.\n";
			echo "\nMigration directories:\n";
			echo "  - " . DEPLOY_ROOT . "radaptor/radaptor-framework/migrations/\n";
			echo "  - " . DEPLOY_ROOT . "core/dev/*/migrations/\n";
			echo "  - " . DEPLOY_ROOT . "core/registry/*/migrations/\n";
			echo "  - " . DEPLOY_ROOT . "themes/dev/*/migrations/\n";
			echo "  - " . DEPLOY_ROOT . "themes/registry/*/migrations/\n";
			echo "  - " . DEPLOY_ROOT . "plugins/dev/*/migrations/\n";
			echo "  - " . DEPLOY_ROOT . "plugins/registry/*/migrations/\n";
			echo "  - " . DEPLOY_ROOT . "app/migrations/\n";

			return;
		}

		$pending_count = count(array_filter($status, fn ($m) => $m['status'] === 'pending'));
		$applied_count = count(array_filter($status, fn ($m) => $m['status'] === 'applied'));

		echo "Migration Status\n";
		echo str_repeat("=", 96) . "\n\n";

		echo str_pad("Status", 10);
		echo str_pad("Module", 18);
		echo str_pad("Applied At", 22);
		echo "Migration\n";
		echo str_repeat("-", 96) . "\n";

		foreach ($status as $migration) {
			$status_indicator = $migration['status'] === 'applied' ? '[OK]' : '[--]';
			echo str_pad($status_indicator, 10);
			echo str_pad($migration['module'], 18);
			echo str_pad($migration['applied_at'] ?? '-', 22);
			echo $migration['filename'] . "\n";
		}

		echo str_repeat("-", 96) . "\n";
		echo "Total: " . count($status) . " | Applied: {$applied_count} | Pending: {$pending_count}\n";

		if ($pending_count > 0) {
			echo "\nRun 'radaptor migrate:run' to apply pending migrations.\n";
		}
	}
}
