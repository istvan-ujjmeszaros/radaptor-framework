<?php

/**
 * Run all pending migrations.
 *
 * Usage: radaptor migrate:run [--dry-run] [--sandbox] [--json]
 *
 * Examples:
 *   radaptor migrate:run
 *   radaptor migrate:run --dry-run
 *   radaptor migrate:run --dry-run --sandbox
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

			Usage: radaptor migrate:run [--dry-run] [--sandbox] [--json]

			Examples:
			  radaptor migrate:run
			  radaptor migrate:run --dry-run
			  radaptor migrate:run --dry-run --sandbox
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
			['name' => 'dry-run', 'label' => 'Dry run', 'type' => 'flag'],
			['name' => 'sandbox', 'label' => 'Sandbox proof', 'type' => 'flag'],
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
		$dry_run = Request::hasArg('dry-run');
		$sandbox = Request::hasArg('sandbox');
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
			if ($sandbox && !$dry_run) {
				if ($json_mode) {
					echo json_encode([
						'status' => 'error',
						'message' => '--sandbox requires --dry-run',
					], JSON_PRETTY_PRINT) . "\n";
				} else {
					echo "Error: --sandbox requires --dry-run.\n";
				}

				return;
			}

			$pending = $dry_run
				? MigrationRunner::getPendingMigrationsForDryRun()
				: MigrationRunner::getPendingMigrations();

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

			if ($dry_run) {
				$this->renderDryRun($pending, $sandbox, $json_mode);

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

	/**
	 * @param array<int, array<string, mixed>> $pending
	 */
	private function renderDryRun(array $pending, bool $sandbox, bool $json_mode): void
	{
		$result = $sandbox
			? MigrationRunner::provePendingMigrationsInSandbox($pending)
			: MigrationRunner::checkPendingMigrations($pending);
		$status = ($result['success'] ?? false) === true ? 'success' : 'error';
		$pending_summary = array_map(
			static fn (array $migration): array => [
				'module' => (string) ($migration['module'] ?? ''),
				'filename' => (string) ($migration['filename'] ?? ''),
			],
			$pending
		);

		if ($json_mode) {
			echo json_encode([
				'status' => $status,
				'dry_run' => true,
				'sandbox' => $sandbox,
				'pending' => $pending_summary,
				'result' => $result,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		$mode = $sandbox ? 'sandbox proof' : 'preflight';
		echo "[dry-run] Migration {$mode}: {$status}\n";
		echo "[dry-run] {$result['message']}\n";
		echo "[dry-run] Pending migrations: " . count($pending_summary) . "\n";

		foreach ($pending_summary as $migration) {
			echo "[dry-run] - {$migration['module']} / {$migration['filename']}\n";
		}
	}
}
