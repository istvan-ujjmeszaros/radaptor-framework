<?php

/**
 * Delete a webpage or folder subtree from the resource tree.
 *
 * Usage:
 *   radaptor webpage:delete <path> [--dry-run] [--json]
 *   radaptor webpage:delete --widget <WidgetName> [--dry-run] [--json]
 *
 * Examples:
 *   radaptor webpage:delete /learn/hello-world/
 *   radaptor webpage:delete --widget HelloWorldPluginDemo --dry-run
 *   radaptor webpage:delete /learn/ --dry-run --json
 */
class CLICommandWebpageDelete extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Delete webpage or folder';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Delete a webpage or folder subtree from the resource tree.

			Usage:
			  radaptor webpage:delete <path> [--dry-run] [--json]
			  radaptor webpage:delete --widget <WidgetName> [--dry-run] [--json]

			Examples:
			  radaptor webpage:delete /learn/hello-world/
			  radaptor webpage:delete --widget HelloWorldPluginDemo --dry-run
			  radaptor webpage:delete /learn/ --dry-run --json
			DOC;
	}

	public function run(): void
	{
		$json_mode = Request::hasArg('json');
		$dry_run = Request::hasArg('dry-run');
		$widget_name = $this->getWidgetArgument();
		$path_arg = Request::getMainArg();

		if ($widget_name !== null && $widget_name !== '') {
			if ($path_arg !== null && !$this->looksLikeCliOption($path_arg)) {
				Kernel::abort("Use either a <path> argument or --widget <WidgetName>, not both.");
			}

			$this->runWidgetMode($widget_name, $dry_run, $json_mode);

			return;
		}

		if ($path_arg === null || $this->looksLikeCliOption($path_arg)) {
			Kernel::abort("Usage: radaptor webpage:delete <path> [--dry-run] [--json]\n   or: radaptor webpage:delete --widget <WidgetName> [--dry-run] [--json]");
		}

		$path = CLIWebpageHelper::normalizePath($path_arg);
		$node = CLIWebpageHelper::resolveNodeFromPath($path);

		if ($node === null) {
			$this->output([
				'path' => $path,
				'error' => 'Path not found',
			], $json_mode);

			return;
		}

		if (($node['node_type'] ?? null) === 'root') {
			$this->output([
				'path' => $path,
				'error' => 'Refusing to delete the root node',
			], $json_mode);

			return;
		}

		$node_id = (int) $node['node_id'];
		$summary = CLIWebpageHelper::summarizeSubtree($node_id);
		$resolved_path = ResourceTreeHandler::getPathFromId($node_id);
		$targets = CLIWebpageHelper::listWebpagesInSubtree($node_id);
		$result = [
			'mode' => 'path',
			'path' => $path,
			'resolved_path' => $resolved_path,
			'dry_run' => $dry_run,
			'node_id' => $node_id,
			'node_type' => (string) ($node['node_type'] ?? ''),
			'resource_name' => (string) ($node['resource_name'] ?? ''),
			'summary' => $summary,
			'targets' => $targets,
			'deleted' => false,
		];

		if (!$dry_run) {
			$deletion = CLIWebpageHelper::deleteSubtreeWithoutAcl($node_id);
			$result['deleted'] = $deletion['success'];
			$result['deletion'] = $deletion;
		}

		$this->output($result, $json_mode);
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function output(array $result, bool $json_mode): void
	{
		if ($json_mode) {
			echo json_encode($result, JSON_PRETTY_PRINT) . "\n";

			return;
		}

		if (isset($result['error'])) {
			echo "Error: {$result['error']}\n";

			return;
		}

		if (($result['mode'] ?? '') === 'widget') {
			echo "Widget: {$result['widget']}\n";
			echo "Target pages: {$result['summary']['pages']} unique page(s), {$result['summary']['placements']} placement(s)\n";
		} else {
			echo "Path: {$result['resolved_path']}\n";
			echo "Node: #{$result['node_id']} ({$result['node_type']}, {$result['resource_name']})\n";
			echo "Summary: {$result['summary']['total_nodes']} node(s), "
				. "{$result['summary']['folders']} folder(s), "
				. "{$result['summary']['webpages']} webpage(s), "
				. "{$result['summary']['other_nodes']} other node(s)\n";
		}

		if (!empty($result['targets'])) {
			echo "Webpage URLs:\n";

			foreach ($result['targets'] as $target) {
				echo "  - {$target['path']}\n";
			}
		}

		if ($result['dry_run']) {
			echo "Dry run only. No changes were written.\n";

			return;
		}

		echo($result['deleted'] ? "Deleted successfully.\n" : "Delete completed with errors.\n");

		if (isset($result['deletion'])) {
			echo "Deleted folders: {$result['deletion']['folder']}\n";
			echo "Deleted webpages: {$result['deletion']['webpage']}\n";
			echo "Errors: {$result['deletion']['erroneous']}\n";
		}
	}

	private function runWidgetMode(string $widget_name, bool $dry_run, bool $json_mode): void
	{
		$targets = CLIWebpageHelper::getWidgetTargetPages($widget_name);

		if (empty($targets)) {
			$this->output([
				'mode' => 'widget',
				'widget' => $widget_name,
				'dry_run' => $dry_run,
				'error' => 'Widget not found on any pages',
				'targets' => [],
			], $json_mode);

			return;
		}

		$result = [
			'mode' => 'widget',
			'widget' => $widget_name,
			'dry_run' => $dry_run,
			'summary' => [
				'pages' => count($targets),
				'placements' => array_sum(array_column($targets, 'placement_count')),
			],
			'targets' => $targets,
			'deleted' => false,
		];

		if (!$dry_run) {
			$deletion = [
				'success' => true,
				'erroneous' => 0,
				'folder' => 0,
				'webpage' => 0,
			];

			foreach ($targets as $target) {
				$current = CLIWebpageHelper::deleteSubtreeWithoutAcl((int) $target['page_id']);
				$deletion['success'] = $deletion['success'] && $current['success'];
				$deletion['erroneous'] += $current['erroneous'];
				$deletion['folder'] += $current['folder'];
				$deletion['webpage'] += $current['webpage'];
			}

			$result['deleted'] = (bool) $deletion['success'];
			$result['deletion'] = $deletion;
		}

		$this->output($result, $json_mode);
	}

	private function getWidgetArgument(): ?string
	{
		$widget_name = $_GET['widget'] ?? null;

		if (is_string($widget_name) && $widget_name !== '') {
			return $widget_name;
		}

		$widget_name = Request::getArg('widget');

		return ($widget_name !== null && $widget_name !== '') ? $widget_name : null;
	}

	private function looksLikeCliOption(string $arg): bool
	{
		return str_starts_with($arg, '--') || str_contains($arg, '=');
	}
}
