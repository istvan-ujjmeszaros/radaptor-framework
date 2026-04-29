<?php

declare(strict_types=1);

class McpTokenPanelView
{
	/**
	 * @param array<string, mixed>|null $new_token
	 * @return array<string, mixed>
	 */
	public static function propsForUser(int $user_id, ?array $new_token = null, ?string $error = null): array
	{
		return [
			'tokens' => McpTokenService::listTokensForUser($user_id),
			'new_token' => $new_token,
			'error' => $error,
			'default_days' => McpTokenService::DEFAULT_EXPIRY_DAYS,
			'create_url' => Url::getAjaxUrl('mcp.token-create'),
			'list_url' => Url::getAjaxUrl('mcp.token-list'),
			'revoke_url' => Url::getAjaxUrl('mcp.token-revoke'),
			'mcp_url' => self::getMcpEndpointUrl(),
		];
	}

	/**
	 * @return array<string, string>
	 */
	public static function buildStrings(): array
	{
		return [
			'mcp.tokens.title' => self::translate('mcp.tokens.title', 'MCP tokens'),
			'mcp.tokens.description' => self::translate('mcp.tokens.description', 'Create and revoke personal MCP access tokens for external clients.'),
			'mcp.tokens.endpoint' => self::translate('mcp.tokens.endpoint', 'MCP endpoint'),
			'mcp.tokens.create_title' => self::translate('mcp.tokens.create_title', 'Create token'),
			'mcp.tokens.name' => self::translate('mcp.tokens.name', 'Name'),
			'mcp.tokens.name_placeholder' => self::translate('mcp.tokens.name_placeholder', 'Claude Desktop - local'),
			'mcp.tokens.expiry' => self::translate('mcp.tokens.expiry', 'Expiry'),
			'mcp.tokens.expiry_30' => self::translate('mcp.tokens.expiry_30', '30 days'),
			'mcp.tokens.expiry_90' => self::translate('mcp.tokens.expiry_90', '90 days'),
			'mcp.tokens.expiry_365' => self::translate('mcp.tokens.expiry_365', '1 year'),
			'mcp.tokens.expiry_never' => self::translate('mcp.tokens.expiry_never', 'No expiry'),
			'mcp.tokens.create' => self::translate('mcp.tokens.create', 'Create token'),
			'mcp.tokens.created_title' => self::translate('mcp.tokens.created_title', 'Token created'),
			'mcp.tokens.created_help' => self::translate('mcp.tokens.created_help', 'Copy this token now. It will not be shown again.'),
			'mcp.tokens.existing_title' => self::translate('mcp.tokens.existing_title', 'Existing tokens'),
			'mcp.tokens.empty' => self::translate('mcp.tokens.empty', 'No MCP tokens yet.'),
			'mcp.tokens.col.name' => self::translate('mcp.tokens.col.name', 'Name'),
			'mcp.tokens.col.prefix' => self::translate('mcp.tokens.col.prefix', 'Prefix'),
			'mcp.tokens.col.status' => self::translate('mcp.tokens.col.status', 'Status'),
			'mcp.tokens.col.created' => self::translate('mcp.tokens.col.created', 'Created'),
			'mcp.tokens.col.expires' => self::translate('mcp.tokens.col.expires', 'Expires'),
			'mcp.tokens.col.last_used' => self::translate('mcp.tokens.col.last_used', 'Last used'),
			'mcp.tokens.col.actions' => self::translate('mcp.tokens.col.actions', 'Actions'),
			'mcp.tokens.revoke' => self::translate('mcp.tokens.revoke', 'Revoke'),
			'mcp.tokens.revoke_confirm' => self::translate('mcp.tokens.revoke_confirm', 'Revoke this MCP token?'),
			'mcp.tokens.status.active' => self::translate('mcp.tokens.status.active', 'Active'),
			'mcp.tokens.status.expired' => self::translate('mcp.tokens.status.expired', 'Expired'),
			'mcp.tokens.status.revoked' => self::translate('mcp.tokens.status.revoked', 'Revoked'),
			'mcp.tokens.never' => self::translate('mcp.tokens.never', 'Never'),
		];
	}

	/**
	 * @param array<string, mixed>|null $new_token
	 */
	public static function renderPanelForUser(int $user_id, ?array $new_token = null, ?string $error = null): void
	{
		WebpageView::header('Content-Type: text/html; charset=UTF-8');

		$template = new Template('mcpTokenPanel');
		$template->props = self::propsForUser($user_id, $new_token, $error);
		$template->strings = self::buildStrings();
		$template->render();
	}

	private static function getMcpEndpointUrl(): string
	{
		$port = getenv('APP_MCP_PORT') ?: '9512';

		return "http://127.0.0.1:{$port}/mcp";
	}

	private static function translate(string $key, string $fallback): string
	{
		$translated = t($key);

		return $translated === $key ? $fallback : $translated;
	}
}
