<?php

declare(strict_types=1);

/*
 * Compatibility backfill for branch-local databases that already recorded the
 * earlier developer-page repair migrations before explicit site-context page
 * creation was enforced.
 *
 * Keep this migration idempotent and separate while those applied migration
 * rows may exist. The canonical fresh-install repair path is 125000.
 */
class Migration_20260504_127000_repair_configured_site_pages_explicitly
{
	public function getDescription(): string
	{
		return 'Repair configured-site login and developer pages with explicit site context.';
	}

	public function run(): void
	{
		$root_id = ResourceTreeHandler::ensureConfiguredSiteRoot();

		if (!is_int($root_id) || $root_id <= 0) {
			throw new RuntimeException('Unable to normalize the configured CMS site root.');
		}

		$site_context = CmsSiteContext::getConfiguredSiteKey();
		$this->ensureLoginPage($site_context);
		$this->ensureWidgetPage('CLIRunner', $site_context);
		$this->ensureWidgetPage('PhpInfoFrame', $site_context);
	}

	private function ensureLoginPage(string $site_context): void
	{
		$this->assertWidgetAvailable(WidgetList::FORM);

		$existing = ResourceTreeHandler::getResourceTreeEntryData('/', 'login.html', $site_context);
		$page_id = is_array($existing)
			? (int) $existing['node_id']
			: ResourceTreeHandler::createResourceTreeEntryFromPath('/', 'login.html', 'webpage', 'admin_login', $site_context);

		if (!is_int($page_id) || $page_id <= 0) {
			throw new RuntimeException('Failed to create configured-site login webpage.');
		}

		ResourceTreeHandler::updateResourceTreeEntry(['layout' => 'admin_login'], $page_id);

		if ((ResourceTypeWebpage::getResourceData($page_id)['layout'] ?? null) !== 'admin_login') {
			throw new RuntimeException('Failed to persist admin_login layout for configured-site login webpage.');
		}

		$connection_id = Widget::getWidgetConnectionId($page_id, ResourceTypeWebpage::DEFAULT_SLOT_NAME, WidgetList::FORM);

		if ($connection_id === null) {
			$connection_id = ResourceTypeWebpage::placeWidgetToWebpage($page_id, WidgetList::FORM);
		}

		if (!is_int($connection_id) || $connection_id <= 0) {
			throw new RuntimeException('Configured-site login form widget connection not found.');
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

	private function ensureWidgetPage(string $widget_name, string $site_context): void
	{
		$this->assertWidgetAvailable($widget_name);

		$widget_class_name = 'Widget' . $widget_name;
		$path_data = $widget_class_name::getDefaultPathForCreation();

		if (!isset($path_data['path'], $path_data['resource_name'], $path_data['layout'])) {
			throw new RuntimeException("Bad default path metadata for widget: {$widget_name}.");
		}

		$existing = ResourceTreeHandler::getResourceTreeEntryData(
			(string) $path_data['path'],
			(string) $path_data['resource_name'],
			$site_context
		);
		$page_id = is_array($existing)
			? (int) $existing['node_id']
			: ResourceTreeHandler::createResourceTreeEntryFromPath(
				(string) $path_data['path'],
				(string) $path_data['resource_name'],
				'webpage',
				(string) $path_data['layout'],
				$site_context
			);

		if (!is_int($page_id) || $page_id <= 0) {
			throw new RuntimeException("Failed to create configured-site developer webpage for {$widget_name}.");
		}

		ResourceTreeHandler::updateResourceTreeEntry(['layout' => 'admin_default'], $page_id);

		if (Widget::getWidgetConnectionId($page_id, ResourceTypeWebpage::DEFAULT_SLOT_NAME, $widget_name) === null) {
			ResourceTypeWebpage::placeWidgetToWebpage($page_id, $widget_name);
		}
	}

	private function assertWidgetAvailable(string $widget_name): void
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
