<?php

/**
 * CLI helper for webpage-related commands.
 *
 * Provides shared functionality for webpage:list, webpage:info, and webpage:check commands.
 */
class CLIWebpageHelper
{
	/**
	 * Resolve a CLI path argument to a resource_tree node.
	 *
	 * The lookup accepts both webpage paths (`/foo/bar/`) and folder-style paths.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function resolveNodeFromPath(string $path): ?array
	{
		$normalized_path = self::normalizePath($path);
		$path_parts = explode('/', trim($normalized_path, '/'));
		$resource_name = array_pop($path_parts);

		if (empty($resource_name)) {
			$resource_name = 'index.html';
		}

		$folder = '/' . implode('/', $path_parts);

		if ($folder !== '/') {
			$folder .= '/';
		}

		$node = ResourceTreeHandler::getResourceTreeEntryData($folder, $resource_name);

		if ($node !== null) {
			return $node;
		}

		if (empty($path_parts)) {
			return null;
		}

		$resource_name = array_pop($path_parts);
		$folder = '/' . implode('/', $path_parts);

		if ($folder !== '/') {
			$folder .= '/';
		}

		return ResourceTreeHandler::getResourceTreeEntryData($folder, $resource_name);
	}

	/**
	 * Get all webpages under the given path.
	 *
	 * @return array<int, array{node_id: int, resource_name: string, path: string, node_type: string}>
	 */
	public static function getWebpagesUnderPath(string $base_path): array
	{
		$domain_context = Config::APP_DOMAIN_CONTEXT->value();

		// Get domain root
		$root_id = ResourceTreeHandler::getDomainRoot($domain_context);

		if (is_null($root_id)) {
			return [];
		}

		$parent_id = $root_id;

		if ($base_path !== '/') {
			$node = self::resolveNodeFromPath($base_path);

			if ($node === null) {
				Kernel::abort("Path not found: {$base_path}");
			}

			$parent_id = (int) $node['node_id'];
		}

		// Get parent node data for lft/rgt bounds
		$parent_data = ResourceTreeHandler::getResourceTreeEntryDataById($parent_id);

		if (is_null($parent_data)) {
			return [];
		}

		// Query all webpages under this node
		$stmt = DbHelper::prexecute(
			"SELECT node_id, resource_name, path, node_type
			 FROM resource_tree
			 WHERE lft >= ? AND rgt <= ? AND node_type = 'webpage'
			 ORDER BY path, resource_name",
			[$parent_data['lft'], $parent_data['rgt']]
		);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Find every widget placement for the given widget type.
	 *
	 * @return array<int, array{page_id: int, path: string, slot: string, seq: int, connection_id: int}>
	 */
	public static function getWidgetPlacements(string $widget_name): array
	{
		$rows = DbHelper::selectMany('widget_connections', [
			'widget_name' => $widget_name,
		]);

		if (empty($rows)) {
			return [];
		}

		$placements = [];

		foreach ($rows as $row) {
			$page_id = (int) $row['page_id'];
			$path = self::resolveWebpageUrl($page_id);

			if ($path === '') {
				continue;
			}

			$placements[] = [
				'page_id' => $page_id,
				'path' => $path,
				'slot' => (string) ($row['slot_name'] ?? ''),
				'seq' => (int) ($row['seq'] ?? 0),
				'connection_id' => (int) $row['connection_id'],
			];
		}

		usort(
			$placements,
			static fn (array $a, array $b): int => [$a['path'], $a['slot'], $a['seq']]
				<=> [$b['path'], $b['slot'], $b['seq']]
		);

		return $placements;
	}

	/**
	 * Resolve widget placements to unique target webpages.
	 *
	 * @return array<int, array{page_id: int, path: string, placement_count: int}>
	 */
	public static function getWidgetTargetPages(string $widget_name): array
	{
		$placements = self::getWidgetPlacements($widget_name);

		if (empty($placements)) {
			return [];
		}

		$pages_by_id = [];

		foreach ($placements as $placement) {
			$page_id = $placement['page_id'];

			if (!isset($pages_by_id[$page_id])) {
				$pages_by_id[$page_id] = [
					'page_id' => $page_id,
					'path' => $placement['path'],
					'placement_count' => 0,
				];
			}

			++$pages_by_id[$page_id]['placement_count'];
		}

		$pages = array_values($pages_by_id);
		usort($pages, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

		return $pages;
	}

	/**
	 * List every webpage URL that lives under the selected subtree.
	 *
	 * @return array<int, array{page_id: int, path: string}>
	 */
	public static function listWebpagesInSubtree(int $node_id): array
	{
		$node = ResourceTreeHandler::getResourceTreeEntryDataById($node_id);

		if ($node === null) {
			Kernel::abort("Resource node not found: {$node_id}");
		}

		$stmt = DbHelper::prexecute(
			"SELECT node_id
			 FROM resource_tree
			 WHERE lft >= ? AND rgt <= ? AND node_type = 'webpage'
			 ORDER BY lft ASC",
			[$node['lft'], $node['rgt']]
		);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$targets = [];

		foreach ($rows as $row) {
			$page_id = (int) $row['node_id'];
			$targets[] = [
				'page_id' => $page_id,
				'path' => self::resolveWebpageUrl($page_id),
			];
		}

		return $targets;
	}

	private static function resolveWebpageUrl(int $page_id): string
	{
		return Url::getSeoUrl($page_id, false) ?? ResourceTreeHandler::getPathFromId($page_id);
	}

	/**
	 * Check prerequisites for page rendering in CLI.
	 *
	 * @throws \RuntimeException via Kernel::abort() if prerequisites not met
	 */
	public static function checkRenderPrerequisites(): void
	{
		// Check debug mode
		if (!Config::DEV_APP_DEBUG_INFO->value()) {
			Kernel::abort(
				"Page rendering requires DEV_APP_DEBUG_INFO to be enabled.\n"
				. "Set DEV_APP_DEBUG_INFO=true in config/ApplicationConfig.php"
			);
		}

		// Check user is logged in
		$currentUser = User::getCurrentUser();

		if (!$currentUser) {
			Kernel::abort(
				"Page rendering requires a logged-in CLI user.\n"
				. "Run: radaptor user:login"
			);
		}

		// Check SYSTEM_DEVELOPER role
		if (!Roles::hasRole(RoleList::ROLE_SYSTEM_DEVELOPER)) {
			Kernel::abort(
				"Page rendering requires SYSTEM_DEVELOPER role.\n"
				. "Current user: {$currentUser['username']}\n"
				. "Run: radaptor user:login (with a developer account)"
			);
		}
	}

	/**
	 * Render a page and extract libraries and errors.
	 *
	 * @return array{constants: string[], css: string[], js: string[], render_errors: string[], error?: string}
	 */
	public static function renderPage(int $node_id): array
	{
		try {
			// Set up $_SERVER for CLI context based on the page being rendered
			self::setupServerContext($node_id);

			// Create webpage resource and view
			$resource = ResourceTypeFactory::Factory($node_id);

			if (!($resource instanceof ResourceTypeWebpage)) {
				return ['constants' => [], 'css' => [], 'js' => [], 'render_errors' => []];
			}

			$view = $resource->getView();

			// Capture any output/errors during composition
			ob_start();
			(string) $view;
			$output = ob_get_clean();

			// Parse render errors from captured output
			$render_errors = self::parseRenderErrors($output);

			// Extract registered libraries
			$libraries = $view->getRegisteredLibraries();
			$libraries['render_errors'] = $render_errors;

			return $libraries;
		} catch (Throwable $e) {
			return [
				'error' => $e->getMessage(),
				'constants' => [],
				'css' => [],
				'js' => [],
				'render_errors' => [],
			];
		}
	}

	/**
	 * Set up $_SERVER variables for CLI context based on the page being rendered.
	 * This allows Url::getCurrentUrl() and similar methods to work in CLI.
	 */
	private static function setupServerContext(int $node_id): void
	{
		$path = ResourceTreeHandler::getPathFromId($node_id);
		$domain = Config::APP_DOMAIN_CONTEXT->value();

		$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['HTTP_HOST'] = $domain;
		$_SERVER['REQUEST_URI'] = $path;
		$_SERVER['HTTPS'] = '';
	}

	/**
	 * Parse error messages from rendered HTML output.
	 *
	 * @return string[]
	 */
	public static function parseRenderErrors(string $output): array
	{
		$errors = [];

		// Match error divs and capture content up to closing </div>
		// Format: <div style="background-color:yellow;">Error X, message<br><i>file</i>, line N<br>...===<br>...</div>
		if (preg_match_all('/<div style="background-color:yellow;">(.+?)<\/div>/is', $output, $matches)) {
			foreach ($matches[1] as $errorBlock) {
				// Extract error message (first line before <br>)
				if (preg_match('/^([^<]+)<br>/i', $errorBlock, $msgMatch)) {
					$message = trim($msgMatch[1]);

					// Extract all stack frames (file:line)
					if (preg_match_all('/<i>([^<]+)<\/i>, line (\d+)/i', $errorBlock, $frameMatches, PREG_SET_ORDER)) {
						$frames = [];

						foreach ($frameMatches as $frame) {
							$frames[] = $frame[1] . ':' . $frame[2];
						}

						// Show as: error message → file:line → file:line → ...
						$message .= "\n      → " . implode(' → ', $frames);
					}

					if (!empty($message)) {
						$errors[] = $message;
					}
				}
			}
		}

		return array_unique($errors);
	}

	/**
	 * Normalize a path argument to standard format (with leading/trailing slashes).
	 */
	public static function normalizePath(?string $path): string
	{
		if (is_null($path)) {
			return '/';
		}

		$path = '/' . trim($path, '/');

		return $path === '/' ? '/' : $path . '/';
	}

	/**
	 * Summarize how many resource_tree nodes live under the resolved subtree.
	 *
	 * @return array{total_nodes: int, folders: int, webpages: int, other_nodes: int}
	 */
	public static function summarizeSubtree(int $node_id): array
	{
		$node = ResourceTreeHandler::getResourceTreeEntryDataById($node_id);

		if ($node === null) {
			Kernel::abort("Resource node not found: {$node_id}");
		}

		$stmt = DbHelper::prexecute(
			"SELECT node_type, COUNT(*) AS total
			 FROM resource_tree
			 WHERE lft >= ? AND rgt <= ?
			 GROUP BY node_type",
			[$node['lft'], $node['rgt']]
		);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$summary = [
			'total_nodes' => 0,
			'folders' => 0,
			'webpages' => 0,
			'other_nodes' => 0,
		];

		foreach ($rows as $row) {
			$type = (string) ($row['node_type'] ?? '');
			$total = (int) ($row['total'] ?? 0);
			$summary['total_nodes'] += $total;

			if ($type === 'folder') {
				$summary['folders'] += $total;
			} elseif ($type === 'webpage') {
				$summary['webpages'] += $total;
			} else {
				$summary['other_nodes'] += $total;
			}
		}

		return $summary;
	}

	/**
	 * Delete a resource_tree subtree without interactive ACL checks.
	 *
	 * This is intended for explicit CLI maintenance commands where the operator
	 * already chose the target path.
	 *
	 * @return array{success: bool, erroneous: int, folder: int, webpage: int}
	 */
	public static function deleteSubtreeWithoutAcl(int $node_id): array
	{
		$folder_count = 0;
		$webpage_count = 0;
		$erroneous_count = 0;
		$node_data = NestedSet::getNodeInfo('resource_tree', $node_id);

		if ($node_data === null) {
			return [
				'success' => false,
				'erroneous' => 1,
				'folder' => 0,
				'webpage' => 0,
			];
		}

		$lft = (int) $node_data['lft'];
		$rgt = (int) $node_data['rgt'];

		if ($rgt - $lft === 1) {
			$success = self::deleteNodeWithoutAcl($node_id);

			if ($success && ($node_data['node_type'] ?? '') === 'webpage') {
				++$webpage_count;
			} elseif ($success && ($node_data['node_type'] ?? '') === 'folder') {
				++$folder_count;
			} else {
				++$erroneous_count;
			}
		} else {
			$stmt = Db::instance()->prepare(
				"SELECT node_id, node_type, (rgt-lft) AS rgtlft
				 FROM resource_tree
				 WHERE lft >= ? AND rgt <= ?
				 ORDER BY rgtlft ASC"
			);
			$stmt->execute([$lft, $rgt]);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

			foreach ($rows as $row) {
				$current = ResourceTreeHandler::getResourceTreeEntryDataById((int) $row['node_id']);

				if ($current === null) {
					++$erroneous_count;

					continue;
				}

				if (((int) $current['rgt'] - (int) $current['lft']) !== 1) {
					++$erroneous_count;

					continue;
				}

				$success = self::deleteNodeWithoutAcl((int) $row['node_id']);

				if ($success && ($row['node_type'] ?? '') === 'webpage') {
					++$webpage_count;
				} elseif ($success && ($row['node_type'] ?? '') === 'folder') {
					++$folder_count;
				} else {
					++$erroneous_count;
				}
			}
		}

		return [
			'success' => $erroneous_count === 0,
			'erroneous' => $erroneous_count,
			'folder' => $folder_count,
			'webpage' => $webpage_count,
		];
	}

	private static function deleteNodeWithoutAcl(int $node_id): bool
	{
		ResourceTreeHandler::clearCatcherPage($node_id);
		Cache::flush();

		return NestedSet::deleteNode('resource_tree', $node_id);
	}
}
