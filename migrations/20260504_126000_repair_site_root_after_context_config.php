<?php

declare(strict_types=1);

/*
 * Compatibility backfill for branch-local databases that already recorded
 * 20260504_125000 before APP_SITE_CONTEXT became the canonical site key.
 *
 * Keep this migration idempotent and separate while those applied migration
 * rows may exist. The canonical fresh-install repair path is 125000.
 */
class Migration_20260504_126000_repair_site_root_after_context_config
{
	public function getDescription(): string
	{
		return 'Repair site root normalization after logical site context configuration.';
	}

	public function run(): void
	{
		$root_id = ResourceTreeHandler::ensureConfiguredSiteRoot();

		if (!is_int($root_id) || $root_id <= 0) {
			throw new RuntimeException('Unable to normalize the configured CMS site root.');
		}

		$this->ensureLoginPage(CmsSiteContext::getConfiguredSiteKey());
	}

	private function ensureLoginPage(string $site_context): void
	{
		if (
			!class_exists(ResourceTypeWebpage::class)
			|| !class_exists(WidgetForm::class)
			|| !is_subclass_of(WidgetForm::class, AbstractWidget::class)
		) {
			throw new RuntimeException('Required login form widget is not available.');
		}

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
}
