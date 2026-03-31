<?php

/**
 * List all webpages with their metadata and widget assignments.
 *
 * Usage: radaptor webpage:list [path] [--json] [--deep]
 *
 * Examples:
 *   radaptor webpage:list
 *   radaptor webpage:list /admin/
 *   radaptor webpage:list --json
 *   radaptor webpage:list /admin/ --json
 *   radaptor webpage:list --deep
 *   radaptor webpage:list /admin/ --deep --json
 */
class CLICommandWebpageList extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'List webpages';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			List all webpages with their metadata and widget assignments.

			Usage: radaptor webpage:list [path] [--json] [--deep]

			Examples:
			  radaptor webpage:list
			  radaptor webpage:list /admin/
			  radaptor webpage:list --json
			  radaptor webpage:list /admin/ --deep --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'Path', 'type' => 'main_arg', 'required' => false],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
			['name' => 'deep', 'label' => 'Deep scan', 'type' => 'flag', 'default' => '0'],
		];
	}

	private bool $_deep_mode = false;

	public function run(): void
	{
		$json_mode = Request::hasArg('json');
		$this->_deep_mode = Request::hasArg('deep');
		$base_path = CLIWebpageHelper::normalizePath(Request::getMainArg());

		// Check prerequisites for deep scan
		if ($this->_deep_mode) {
			CLIWebpageHelper::checkRenderPrerequisites();
		}

		// Get all webpages under the base path
		$pages = CLIWebpageHelper::getWebpagesUnderPath($base_path);

		// Build result with metadata and widgets for each page
		$result = [
			'base_path' => $base_path,
			'count' => count($pages),
			'pages' => [],
		];

		foreach ($pages as $page) {
			$result['pages'][] = $this->_getPageDetails((int) $page['node_id']);
		}

		// Output
		if ($json_mode) {
			echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
		} else {
			$this->_outputText($result);
		}
	}

	/**
	 * Get detailed information about a page.
	 *
	 * @return array{path: string, node_id: int, layout: ?string, title: ?string, attributes: array<string, mixed>, slots: array<string, array<int, array{widget: string, seq: int, attributes: array<string, mixed>}>>, libraries?: array{constants: string[], css: string[], js: string[], render_errors: string[], error?: string}}
	 */
	private function _getPageDetails(int $node_id): array
	{
		$path = ResourceTreeHandler::getPathFromId($node_id);

		// Get page attributes
		$attributes = AttributeHandler::getAttributes(
			new AttributeResourceIdentifier(ResourceNames::RESOURCE_DATA, (string) $node_id)
		);

		// Get widget assignments by slot
		$connections = DbHelper::selectMany('widget_connections', [
			'page_id' => $node_id,
		]);

		$slots = [];

		foreach ($connections as $conn) {
			$slot_name = $conn['slot_name'] ?? 'default';

			$slot_attrs = AttributeHandler::getAttributes(
				new AttributeResourceIdentifier(
					ResourceNames::WIDGET_CONNECTION,
					(string) $conn['connection_id']
				)
			);

			$slots[$slot_name][] = [
				'widget' => $conn['widget_name'],
				'seq' => (int) ($conn['seq'] ?? 0),
				'attributes' => $slot_attrs,
			];
		}

		// Sort widgets within each slot by seq
		foreach ($slots as &$widgets) {
			usort($widgets, fn ($a, $b) => $a['seq'] <=> $b['seq']);
		}
		unset($widgets);

		$result = [
			'path' => $path,
			'node_id' => $node_id,
			'layout' => $attributes['layout'] ?? null,
			'title' => $attributes['title'] ?? null,
			'attributes' => $attributes,
			'slots' => $slots,
		];

		// Deep scan: render page and extract libraries
		if ($this->_deep_mode) {
			$result['libraries'] = CLIWebpageHelper::renderPage($node_id);
		}

		return $result;
	}

	/**
	 * Output results in text format.
	 *
	 * @param array{base_path: string, count: int, pages: array<int, array>} $result
	 */
	private function _outputText(array $result): void
	{
		$count = $result['count'];
		$base = $result['base_path'];

		echo "Found {$count} webpage(s) under {$base}\n\n";

		foreach ($result['pages'] as $page) {
			echo "{$page['path']}\n";
			echo "  Layout: " . ($page['layout'] ?? '(none)') . "\n";

			if (!empty($page['slots'])) {
				echo "  Slots:\n";

				foreach ($page['slots'] as $slot => $widgets) {
					$widget_names = array_map(fn ($w) => $w['widget'], $widgets);
					echo "    {$slot}: " . implode(', ', $widget_names) . "\n";
				}
			}

			// Output libraries if deep scan
			if (isset($page['libraries'])) {
				$libs = $page['libraries'];

				if (isset($libs['error'])) {
					echo "  Libraries: (error: {$libs['error']})\n";
				} elseif (!empty($libs['constants'])) {
					echo "  Libraries:\n";

					foreach ($libs['constants'] as $constant) {
						echo "    - {$constant}\n";
					}
				} else {
					echo "  Libraries: (none)\n";
				}

				// Output render errors if any
				if (!empty($libs['render_errors'])) {
					echo "  Render errors:\n";

					foreach ($libs['render_errors'] as $error) {
						echo "    - {$error}\n";
					}
				}
			}

			echo "\n";
		}
	}
}
