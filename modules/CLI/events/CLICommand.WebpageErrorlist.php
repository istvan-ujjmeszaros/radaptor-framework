<?php

/**
 * List all webpages with rendering errors.
 *
 * Usage: radaptor webpage:errorlist [path] [--json]
 *
 * Examples:
 *   radaptor webpage:errorlist
 *   radaptor webpage:errorlist /admin/
 *   radaptor webpage:errorlist --json
 *
 * Exit codes:
 *   0 - No errors found
 *   1 - One or more pages have errors (for CI/CD integration)
 */
class CLICommandWebpageErrorlist extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'List webpage rendering errors';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			List all webpages with rendering errors. Exits with code 1 if errors found (CI/CD).

			Usage: radaptor webpage:errorlist [path] [--json]

			Examples:
			  radaptor webpage:errorlist
			  radaptor webpage:errorlist /admin/
			  radaptor webpage:errorlist --json
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
		$json_mode = Request::hasArg('json');
		$base_path = CLIWebpageHelper::normalizePath(Request::getMainArg());

		// Check prerequisites for page rendering
		CLIWebpageHelper::checkRenderPrerequisites();

		// Get all webpages under the base path
		$pages = CLIWebpageHelper::getWebpagesUnderPath($base_path);
		$total_pages = count($pages);

		// Collect pages with errors
		/** @var array<int, array{path: string, errors: string[]}> $pages_with_errors */
		$pages_with_errors = [];

		foreach ($pages as $page) {
			$node_id = (int) $page['node_id'];
			$path = ResourceTreeHandler::getPathFromId($node_id);

			// Render page and collect errors
			$result = CLIWebpageHelper::renderPage($node_id);

			$errors = [];

			// Add exception error if present
			if (isset($result['error'])) {
				$errors[] = $result['error'];
			}

			// Add render errors
			if (!empty($result['render_errors'])) {
				$errors = array_merge($errors, $result['render_errors']);
			}

			if (!empty($errors)) {
				$pages_with_errors[] = [
					'path' => $path,
					'errors' => $errors,
				];
			}
		}

		$error_count = count($pages_with_errors);

		// Output results
		if ($json_mode) {
			$this->_outputJson($base_path, $total_pages, $pages_with_errors);
		} else {
			$this->_outputText($base_path, $total_pages, $pages_with_errors);
		}

		// Exit with code 1 if errors found (for CI/CD)
		if ($error_count > 0) {
			exit(1);
		}
	}

	/**
	 * Output results in JSON format.
	 *
	 * @param array<int, array{path: string, errors: string[]}> $pages_with_errors
	 */
	private function _outputJson(string $base_path, int $total, array $pages_with_errors): void
	{
		$result = [
			'base_path' => $base_path,
			'total' => $total,
			'error_count' => count($pages_with_errors),
			'pages' => $pages_with_errors,
		];

		echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
	}

	/**
	 * Output results in text format.
	 *
	 * @param array<int, array{path: string, errors: string[]}> $pages_with_errors
	 */
	private function _outputText(string $base_path, int $total, array $pages_with_errors): void
	{
		echo "Checked {$total} webpage(s) under {$base_path}\n\n";

		if (empty($pages_with_errors)) {
			echo CLIOutput::GREEN . "No errors found." . CLIOutput::RESET . "\n";

			return;
		}

		foreach ($pages_with_errors as $page) {
			echo CLIOutput::RED . $page['path'] . CLIOutput::RESET . "\n";

			foreach ($page['errors'] as $error) {
				echo "  - {$error}\n";
			}

			echo "\n";
		}

		$error_count = count($pages_with_errors);
		echo CLIOutput::RED . "{$error_count} page(s) with errors" . CLIOutput::RESET . "\n";
	}
}
