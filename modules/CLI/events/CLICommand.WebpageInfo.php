<?php

/**
 * Get detailed info about a webpage.
 *
 * Usage: radaptor webpage:info <path> [--json]
 *
 * Examples:
 *   radaptor webpage:info /contact-persons/
 *   radaptor webpage:info / --json
 */
class CLICommandWebpageInfo extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show webpage details';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Get detailed info about a webpage including attributes, slots, and widgets.

			Usage: radaptor webpage:info <path> [--json]

			Examples:
			  radaptor webpage:info /contact-persons/
			  radaptor webpage:info / --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'Path', 'type' => 'main_arg', 'required' => true],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$path = Request::getMainArg();

		if (is_null($path)) {
			Kernel::abort("Usage: radaptor webpage:info <path> [--json]");
		}

		// Normalize path
		$path = '/' . trim($path, '/');

		if ($path !== '/') {
			$path .= '/';
		}

		// Parse path into folder + resource_name
		$path_parts = explode('/', trim($path, '/'));
		$resource_name = array_pop($path_parts);

		if (empty($resource_name)) {
			$resource_name = 'index.html';
		}

		$folder = '/' . implode('/', $path_parts);

		if ($folder !== '/') {
			$folder .= '/';
		}

		// Get page data from ResourceTreeHandler
		$page_data = ResourceTreeHandler::getResourceTreeEntryData($folder, $resource_name);

		// If not found, try with folder itself (for folder paths)
		if (is_null($page_data) && !empty($path_parts)) {
			$resource_name = array_pop($path_parts);
			$folder = '/' . implode('/', $path_parts);

			if ($folder !== '/') {
				$folder .= '/';
			}

			$page_data = ResourceTreeHandler::getResourceTreeEntryData($folder, $resource_name);
		}

		$json_mode = Request::hasArg('json');

		if (is_null($page_data)) {
			if ($json_mode) {
				echo json_encode([
					'error' => 'Page not found',
					'path' => $path,
				], JSON_PRETTY_PRINT) . "\n";
			} else {
				echo "Page not found: {$path}\n";
			}

			return;
		}

		$node_id = (int) $page_data['node_id'];

		// Get attributes for the page
		$attributes = AttributeHandler::getAttributes(
			new AttributeResourceIdentifier(ResourceNames::RESOURCE_DATA, (string) $node_id)
		);

		// Get widget connections
		$connections = DbHelper::selectMany('widget_connections', [
			'page_id' => $node_id,
		]);

		// Group by slot
		$slots = [];

		foreach ($connections as $conn) {
			$slot_name = $conn['slot_name'] ?? 'default';

			if (!isset($slots[$slot_name])) {
				$slots[$slot_name] = [];
			}

			// Get connection-specific attributes
			$connection_attrs = AttributeHandler::getAttributes(
				new AttributeResourceIdentifier(ResourceNames::WIDGET_CONNECTION, (string) $conn['connection_id'])
			);

			$slots[$slot_name][] = [
				'connection_id' => (int) $conn['connection_id'],
				'widget' => $conn['widget_name'],
				'seq' => (int) ($conn['seq'] ?? 0),
				'attributes' => $connection_attrs,
			];
		}

		// Sort each slot by seq
		foreach ($slots as &$slot_widgets) {
			usort($slot_widgets, fn ($a, $b) => $a['seq'] <=> $b['seq']);
		}
		unset($slot_widgets);

		$result = [
			'path' => ResourceTreeHandler::getPathFromId($node_id),
			'node_id' => $node_id,
			'node_type' => $page_data['node_type'],
			'resource_name' => $page_data['resource_name'],
			'layout' => $attributes['layout'] ?? null,
			'title' => $attributes['title'] ?? null,
			'attributes' => $attributes,
			'slots' => $slots,
		];

		if ($json_mode) {
			echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
		} else {
			echo "Page: {$result['path']}\n";
			echo "  node_id: {$result['node_id']}\n";
			echo "  node_type: {$result['node_type']}\n";

			if ($result['layout']) {
				echo "  layout: {$result['layout']}\n";
			}

			if ($result['title']) {
				echo "  title: {$result['title']}\n";
			}

			echo "\n";

			if (empty($slots)) {
				echo "No slots found.\n";
			} else {
				echo "Slots:\n";

				foreach ($slots as $slot_name => $widgets) {
					echo "  [{$slot_name}]\n";

					foreach ($widgets as $widget) {
						$widget_info = "{$widget['widget']} (connection_id: {$widget['connection_id']}";

						// Add form_id if present
						if (isset($widget['attributes']['form_id'])) {
							$widget_info .= ", form_id: {$widget['attributes']['form_id']}";
						}

						$widget_info .= ")";
						echo "    {$widget['seq']}. {$widget_info}\n";
					}
				}
			}
		}
	}
}
