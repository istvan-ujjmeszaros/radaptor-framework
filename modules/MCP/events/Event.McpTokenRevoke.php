<?php

declare(strict_types=1);

class EventMcpTokenRevoke extends AbstractEvent implements iBrowserEventDocumentable
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
			'event_name' => 'mcp.token-revoke',
			'group' => 'MCP',
			'name' => 'Revoke personal MCP token',
			'summary' => 'Revokes one current-user MCP token.',
			'description' => 'Marks a token revoked without deleting it. Users can revoke only their own tokens.',
			'request' => [
				'method' => 'POST',
				'params' => [
					BrowserEventDocumentationHelper::param('token_id', 'body', 'int', true, 'MCP token row id.'),
					BrowserEventDocumentationHelper::param('format', 'body', 'string', false, 'Use json for JSON output; defaults to HTML panel.'),
				],
			],
			'response' => [
				'kind' => 'json-or-html',
				'content_type' => 'application/json or text/html',
				'description' => 'Returns revoke status for JSON callers or the refreshed token panel for the GUI.',
			],
			'authorization' => [
				'visibility' => 'current-user',
				'description' => 'Any logged-in user can revoke their own MCP token.',
			],
			'side_effects' => BrowserEventDocumentationHelper::lines('Sets mcp_tokens.revoked_at.'),
		];
	}

	public function run(): void
	{
		$user_id = User::getCurrentUserId();
		$token_id = (int) Request::_POST('token_id', Request::_GET('token_id', 0));

		if ($token_id <= 0) {
			self::respondError($user_id, 'MCP_TOKEN_REVOKE_INVALID_ID', 'Missing token id.');

			return;
		}

		if (!McpTokenService::revokeTokenForUser($user_id, $token_id)) {
			self::respondError($user_id, 'MCP_TOKEN_REVOKE_FAILED', 'Token not found.');

			return;
		}

		if (self::wantsJson()) {
			ApiResponse::renderSuccess(['revoked' => true, 'token_id' => $token_id]);

			return;
		}

		McpTokenPanelView::renderPanelForUser($user_id);
	}

	private static function respondError(int $user_id, string $code, string $message): void
	{
		if (self::wantsJson()) {
			ApiResponse::renderError($code, $message, 400);

			return;
		}

		McpTokenPanelView::renderPanelForUser($user_id, null, $message);
	}

	private static function wantsJson(): bool
	{
		return Request::_GET('format', '') === 'json'
			|| Request::_POST('format', '') === 'json';
	}
}
