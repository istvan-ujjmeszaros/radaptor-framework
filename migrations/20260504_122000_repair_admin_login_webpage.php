<?php

declare(strict_types=1);

class Migration_20260504_122000_repair_admin_login_webpage
{
	public function getDescription(): string
	{
		return 'Repair the admin login webpage layout and form widget attributes.';
	}

	public function run(): void
	{
		if (
			!class_exists(ResourceTypeWebpage::class)
			|| !class_exists(Widget::class)
			|| !class_exists(AttributeHandler::class)
		) {
			throw new RuntimeException('CMS classes required to repair the admin login webpage are not available.');
		}

		$page_id = ResourceTypeWebpage::getWebpageIdByFormType(FormList::USERLOGIN);

		if ($page_id <= 0) {
			throw new RuntimeException('Admin login webpage not found.');
		}

		ResourceTreeHandler::updateResourceTreeEntry([
			'layout' => 'admin_login',
		], $page_id);

		$connection_id = Widget::getWidgetConnectionId($page_id, ResourceTypeWebpage::DEFAULT_SLOT_NAME, WidgetList::FORM);

		if ($connection_id === null) {
			$connection_id = ResourceTypeWebpage::placeWidgetToWebpage($page_id, WidgetList::FORM);
		}

		if (!is_int($connection_id) || $connection_id <= 0) {
			throw new RuntimeException('Admin login form widget connection not found.');
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
