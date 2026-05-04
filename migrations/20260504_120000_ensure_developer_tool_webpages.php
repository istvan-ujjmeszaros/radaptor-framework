<?php

declare(strict_types=1);

class Migration_20260504_120000_ensure_developer_tool_webpages
{
	public function getDescription(): string
	{
		return 'Ensure developer tool webpages exist and contain their widgets.';
	}

	public function run(): void
	{
		$site_context = $this->getConfiguredSiteContext();
		$root_id = $this->ensureConfiguredSiteRootIfSupported();

		if ($root_id !== null && $root_id <= 0) {
			throw new RuntimeException('Unable to normalize the configured CMS site root.');
		}

		$this->ensureWidgetPage('CLIRunner', '/admin/developer/', 'cli-runner.html', $site_context);
		$this->ensureWidgetPage('PhpInfoFrame', '/admin/developer/', 'phpinfo.html', $site_context);
	}

	private function ensureConfiguredSiteRootIfSupported(): ?int
	{
		if (!class_exists(CmsSiteContext::class) || !method_exists(ResourceTreeHandler::class, 'ensureConfiguredSiteRoot')) {
			return null;
		}

		return ResourceTreeHandler::ensureConfiguredSiteRoot();
	}

	private function getConfiguredSiteContext(): string
	{
		if (class_exists(CmsSiteContext::class) && method_exists(CmsSiteContext::class, 'getConfiguredSiteKey')) {
			return CmsSiteContext::getConfiguredSiteKey();
		}

		return (string) Config::APP_DOMAIN_CONTEXT->value();
	}

	private function ensureWidgetPage(string $widget_name, string $path, string $resource_name, string $site_context): void
	{
		$widget_class_name = 'Widget' . $widget_name;

		if (
			!class_exists(ResourceTypeWebpage::class)
			|| !class_exists($widget_class_name)
			|| !is_subclass_of($widget_class_name, AbstractWidget::class)
		) {
			throw new RuntimeException("Developer tool widget is not available: {$widget_name}.");
		}

		$existing = ResourceTreeHandler::getResourceTreeEntryData($path, $resource_name, $site_context);
		$page_id = is_array($existing)
			? (int) $existing['node_id']
			: $this->createWidgetPage($widget_class_name, $site_context);

		if (!is_int($page_id) || $page_id <= 0) {
			throw new RuntimeException("Failed to create developer tool webpage for {$widget_name}.");
		}

		ResourceTreeHandler::updateResourceTreeEntry([
			'layout' => 'admin_default',
		], $page_id);

		if (Widget::getWidgetConnectionId($page_id, ResourceTypeWebpage::DEFAULT_SLOT_NAME, $widget_name) === null) {
			ResourceTypeWebpage::placeWidgetToWebpage($page_id, $widget_name);
		}
	}

	private function createWidgetPage(string $widget_class_name, string $site_context): false|int
	{
		$path_data = $widget_class_name::getDefaultPathForCreation();

		if (!isset($path_data['path'], $path_data['resource_name'], $path_data['layout'])) {
			return false;
		}

		return ResourceTreeHandler::createResourceTreeEntryFromPath(
			(string) $path_data['path'],
			(string) $path_data['resource_name'],
			'webpage',
			(string) $path_data['layout'],
			$site_context
		) ?? false;
	}
}
