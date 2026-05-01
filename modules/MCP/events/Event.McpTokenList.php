<?php

declare(strict_types=1);

class EventMcpTokenList extends AbstractEvent implements iBrowserEventDocumentable
{
	public function authorize(PolicyContext $policyContext): PolicyDecision
	{
		return User::getCurrentUserId() > 0
			? PolicyDecision::allow()
			: PolicyDecision::deny('login required');
	}

	public static function describeBrowserEvent(): array
	{
		return [
			'event_name' => 'mcp.token-list',
			'group' => 'MCP',
			'name' => 'List personal MCP tokens',
			'summary' => 'Returns the current user personal MCP tokens.',
			'description' => 'Lists token metadata only. Full token secrets are never returned after creation.',
			'request' => [
				'method' => 'GET',
				'params' => [
					BrowserEventDocumentationHelper::param('format', 'query', 'string', false, 'Use json for JSON output; defaults to HTML panel.'),
				],
			],
			'response' => [
				'kind' => 'json-or-html',
				'content_type' => 'application/json or text/html',
				'description' => 'Returns token metadata for JSON callers or the token panel partial for the GUI.',
			],
			'authorization' => [
				'visibility' => 'current-user',
				'description' => 'Any logged-in user can list their own MCP tokens.',
			],
			'side_effects' => [],
		];
	}

	public function run(): void
	{
		$user_id = User::getCurrentUserId();

		if (self::wantsJson()) {
			ApiResponse::renderSuccess([
				'tokens' => McpTokenService::listTokensForUser($user_id),
			]);

			return;
		}

		McpTokenPanelView::renderPanelForUser($user_id);
	}

	private static function wantsJson(): bool
	{
		return Request::_GET('format', '') === 'json'
			|| Request::_POST('format', '') === 'json';
	}
}
