<?php

declare(strict_types=1);

class EventRuntimeDiagnostics extends AbstractEvent implements iBrowserEventDocumentable
{
	public function authorize(PolicyContext $policyContext): PolicyDecision
	{
		return RuntimeDiagnosticsAccessPolicy::authorize($policyContext);
	}

	public static function describeBrowserEvent(): array
	{
		return [
			'event_name' => 'runtime.diagnostics',
			'group' => 'Runtime',
			'name' => 'Show runtime diagnostics',
			'summary' => 'Returns curated, redacted runtime diagnostics for developers.',
			'description' => 'Exposes safe effective runtime state without dumping raw config or secrets.',
			'request' => [
				'method' => 'GET',
				'params' => [],
			],
			'response' => [
				'kind' => 'json',
				'content_type' => 'application/json',
				'description' => 'Returns grouped environment, email, database, Redis, MCP, package, and warning data.',
			],
			'authorization' => [
				'visibility' => 'role:system_developer',
				'description' => 'Requires the system developer role.',
			],
			'notes' => BrowserEventDocumentationHelper::lines(
				'No raw full config dump is exposed.',
				'Secret-like values and DSN credentials are redacted.'
			),
			'side_effects' => [],
			'mcp' => [
				'enabled' => true,
				'tool_name' => 'radaptor.runtime.diagnostics',
				'risk' => 'read',
				'idempotent' => true,
				'open_world' => false,
			],
		];
	}

	public function run(): void
	{
		ApiResponse::renderSuccess(RuntimeDiagnosticsReadModel::getSummary());
	}
}
