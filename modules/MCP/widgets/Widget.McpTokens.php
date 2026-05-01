<?php

declare(strict_types=1);

class WidgetMcpTokens extends AbstractWidget
{
	public const string ID = 'mcp_tokens';

	public static function getName(): string
	{
		return McpTokenPanelView::buildStrings()['mcp.tokens.title'];
	}

	public static function getDescription(): string
	{
		return McpTokenPanelView::buildStrings()['mcp.tokens.description'];
	}

	public static function getListVisibility(): bool
	{
		return User::getCurrentUserId() > 0;
	}

	public static function getDefaultPathForCreation(): array
	{
		return [
			'path' => '/account/mcp-tokens/',
			'resource_name' => 'index.html',
			'layout' => 'admin_default',
		];
	}

	protected function buildAuthorizedTree(iTreeBuildContext $tree_build_context, WidgetConnection $connection, array $build_context = []): array
	{
		return $this->createComponentTree(
			'mcpTokens',
			McpTokenPanelView::propsForUser(User::getCurrentUserId()),
			strings: McpTokenPanelView::buildStrings()
		);
	}

	public function canAccess(iTreeBuildContext $tree_build_context, WidgetConnection $connection): bool
	{
		return User::getCurrentUserId() > 0;
	}
}
