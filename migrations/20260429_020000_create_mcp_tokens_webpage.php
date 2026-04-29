<?php

declare(strict_types=1);

class Migration_20260429_020000_create_mcp_tokens_webpage
{
	public function getDescription(): string
	{
		return 'Create MCP token management webpage.';
	}

	public function run(): void
	{
		if (!class_exists(ResourceTypeWebpage::class) || !class_exists(WidgetMcpTokens::class)) {
			return;
		}

		$existing = ResourceTreeHandler::getResourceTreeEntryData('/account/mcp-tokens/', 'index.html');
		$page_id = is_array($existing)
			? (int) $existing['node_id']
			: ResourceTypeWebpage::generateWebpageForWidget('McpTokens');

		if (!is_int($page_id) || $page_id <= 0) {
			throw new RuntimeException('Failed to create MCP token management webpage.');
		}

		if (Widget::getWidgetConnectionId($page_id, ResourceTypeWebpage::DEFAULT_SLOT_NAME, 'McpTokens') === null) {
			ResourceTypeWebpage::placeWidgetToWebpage($page_id, 'McpTokens');
		}

		AttributeHandler::addAttribute(
			new AttributeResourceIdentifier(ResourceNames::RESOURCE_DATA, (string) $page_id),
			[
				'title' => 'MCP tokens',
				'description' => 'Personal MCP token management.',
			]
		);
	}
}
