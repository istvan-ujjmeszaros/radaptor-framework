<?php

/**
 * Run all pending migrations.
 *
 * Usage: radaptor migrate:run [--json]
 *
 * Examples:
 *   radaptor migrate:run
 *   radaptor migrate:run --json
 */
class CLICommandMigrateRun extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Run pending migrations';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Run all pending database migrations in chronological order.

			Usage: radaptor migrate:run [--json]

			Examples:
			  radaptor migrate:run
			  radaptor migrate:run --json
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

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$json_mode = Request::hasArg('json');
		$original_db_mode = Db::getCLIDatabaseMode();
		$switched_to_normal = false;

		if ($original_db_mode === 'test' && Kernel::getEnvironment() !== 'test') {
			CLIStorage::save('CLI_DATABASE_MODE', 'normal');
			$switched_to_normal = true;

			if (!$json_mode) {
				echo "\033[33mDetected TEST DB mode; temporarily switching to NORMAL for migrations.\033[0m\n";
			}
		}

		try {
			$pending = MigrationRunner::getPendingMigrations();

			if (empty($pending)) {
				if ($json_mode) {
					echo json_encode([
						'status' => 'success',
						'message' => 'No pending migrations',
						'migrations' => [],
					], JSON_PRETTY_PRINT) . "\n";
				} else {
					echo "No pending migrations.\n";
				}

				return;
			}

			if (!$json_mode) {
				echo "Running " . count($pending) . " pending migration(s)...\n\n";
			}

			$results = MigrationRunner::runAllPending();

			if ($json_mode) {
				$all_success = empty(array_filter($results, fn ($r) => !$r['success']));
				echo json_encode([
					'status' => $all_success ? 'success' : 'error',
					'migrations' => $results,
				], JSON_PRETTY_PRINT) . "\n";

				return;
			}

			foreach ($results as $result) {
				$status_icon = $result['success'] ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m";
				echo "{$status_icon} {$result['message']}\n";

				if (!empty($result['description'])) {
					echo "  └─ {$result['description']}\n";
				}

				if (!$result['success']) {
					echo "\n\033[31mMigration failed. Stopping.\033[0m\n";
					echo "\nFix the issue and run 'radaptor migrate:run' again.\n";

					return;
				}
			}

			echo "\n\033[32mAll migrations applied successfully.\033[0m\n";
			echo "Schema files updated automatically.\n";
		} finally {
			if ($switched_to_normal) {
				CLIStorage::save('CLI_DATABASE_MODE', $original_db_mode);

				if (!$json_mode) {
					echo "\033[33mRestored CLI DB mode to TEST.\033[0m\n";
				}
			}
		}
	}
}
