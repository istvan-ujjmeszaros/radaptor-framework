<?php

declare(strict_types=1);

class Migration_20260504_125000_normalize_site_root_and_repair_active_site_pages
{
	public function getDescription(): string
	{
		return 'Normalize the configured CMS site root and repair active-site login/developer pages.';
	}

	public function run(): void
	{
		$root_id = ResourceTreeHandler::ensureConfiguredSiteRoot();

		if (!is_int($root_id) || $root_id <= 0) {
			throw new RuntimeException('Unable to normalize the configured CMS site root.');
		}

		$site_context = CmsSiteContext::getConfiguredSiteKey();
		$this->ensureLoginPage($site_context);
		$this->ensureWidgetPage('CLIRunner', '/admin/developer/', 'cli-runner.html', $site_context);
		$this->ensureWidgetPage('PhpInfoFrame', '/admin/developer/', 'phpinfo.html', $site_context);
	}

	private function ensureLoginPage(string $site_context): void
	{
		$this->assertCmsAvailable(WidgetList::FORM);

		$existing = ResourceTreeHandler::getResourceTreeEntryData('/', 'login.html', $site_context);
		$page_id = is_array($existing)
			? (int) $existing['node_id']
			: ResourceTreeHandler::createResourceTreeEntryFromPath('/', 'login.html', 'webpage', 'admin_login', $site_context);

		if (!is_int($page_id) || $page_id <= 0) {
			throw new RuntimeException('Failed to create active-site login webpage.');
		}

		ResourceTreeHandler::updateResourceTreeEntry([
			'layout' => 'admin_login',
		], $page_id);

		$connection_id = Widget::getWidgetConnectionId($page_id, ResourceTypeWebpage::DEFAULT_SLOT_NAME, WidgetList::FORM);

		if ($connection_id === null) {
			$connection_id = ResourceTypeWebpage::placeWidgetToWebpage($page_id, WidgetList::FORM);
		}

		if (!is_int($connection_id) || $connection_id <= 0) {
			throw new RuntimeException('Active-site login form widget connection not found.');
		}

		AttributeHandler::addAttribute(
			new AttributeResourceIdentifier(ResourceNames::WIDGET_CONNECTION, (string) $connection_id),
			[
				'form_id' => FormList::USERLOGIN,
				'margin-left' => 'auto',
				'margin-right' => 'auto',
				'width' => 'min(100%, 28rem)',
			]
		);
	}

	private function ensureWidgetPage(string $widget_name, string $path, string $resource_name, string $site_context): void
	{
		$this->assertCmsAvailable($widget_name);

		$existing = ResourceTreeHandler::getResourceTreeEntryData($path, $resource_name, $site_context);
		$page_id = is_array($existing)
			? (int) $existing['node_id']
			: $this->createWidgetPage($widget_name, $site_context);

		if (!is_int($page_id) || $page_id <= 0) {
			throw new RuntimeException("Failed to create active-site developer tool webpage for {$widget_name}.");
		}

		ResourceTreeHandler::updateResourceTreeEntry([
			'layout' => 'admin_default',
		], $page_id);

		if (Widget::getWidgetConnectionId($page_id, ResourceTypeWebpage::DEFAULT_SLOT_NAME, $widget_name) === null) {
			ResourceTypeWebpage::placeWidgetToWebpage($page_id, $widget_name);
		}
	}

	private function createWidgetPage(string $widget_name, string $site_context): false|int
	{
		$widget_class_name = 'Widget' . $widget_name;
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

	private function assertCmsAvailable(string $widget_name): void
	{
		$widget_class_name = 'Widget' . $widget_name;

		if (
			!class_exists(ResourceTypeWebpage::class)
			|| !class_exists($widget_class_name)
			|| !is_subclass_of($widget_class_name, AbstractWidget::class)
		) {
			throw new RuntimeException("Required widget is not available: {$widget_name}.");
		}
	}
}
