<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/MCP/classes/class.McpToolSchemaBuilder.php';

final class McpToolSchemaBuilderTest extends TestCase
{
	public function testToolNameUsesExplicitOverride(): void
	{
		$meta = [
			'event_name' => 'mcp.token-create',
			'mcp' => ['tool_name' => 'radaptor.tokens.create'],
		];

		$this->assertSame('radaptor.tokens.create', McpToolSchemaBuilder::getToolName($meta));
	}

	public function testToolNameFallsBackToEventName(): void
	{
		$meta = ['event_name' => 'user_create'];

		$this->assertSame('radaptor.user.create', McpToolSchemaBuilder::getToolName($meta));
	}

	public function testToolIncludesTitleAtTopAndInAnnotations(): void
	{
		$tool = McpToolSchemaBuilder::buildTool([
			'event_name' => 'demo.echo',
			'name' => 'Echo Demo',
			'mcp' => ['enabled' => true],
		]);

		$this->assertSame('Echo Demo', $tool['title'] ?? null);
		$this->assertSame('Echo Demo', $tool['annotations']['title'] ?? null);
	}

	public function testTitleOmittedWhenMetaNameMissing(): void
	{
		$tool = McpToolSchemaBuilder::buildTool([
			'event_name' => 'demo.echo',
			'mcp' => ['enabled' => true],
		]);

		$this->assertArrayNotHasKey('title', $tool);
		$this->assertArrayNotHasKey('title', $tool['annotations']);
	}

	public function testReadOnlyToolGetsDestructiveFalse(): void
	{
		$tool = McpToolSchemaBuilder::buildTool([
			'event_name' => 'demo.read',
			'mcp' => ['risk' => 'read'],
		]);

		$this->assertTrue($tool['annotations']['readOnlyHint']);
		$this->assertFalse($tool['annotations']['destructiveHint']);
	}

	public function testReadOnlyToolIgnoresDestructiveOverride(): void
	{
		$tool = McpToolSchemaBuilder::buildTool([
			'event_name' => 'demo.read',
			'mcp' => ['risk' => 'read', 'destructive' => true],
		]);

		$this->assertTrue($tool['annotations']['readOnlyHint']);
		$this->assertFalse($tool['annotations']['destructiveHint'], 'read-only must always win over the destructive override');
	}

	public function testNonReadonlyDefaultsToDestructiveTrue(): void
	{
		$tool = McpToolSchemaBuilder::buildTool([
			'event_name' => 'demo.write',
			'mcp' => ['risk' => 'write'],
		]);

		$this->assertFalse($tool['annotations']['readOnlyHint']);
		$this->assertTrue($tool['annotations']['destructiveHint']);
	}

	public function testNonReadonlyDestructiveOverrideToFalse(): void
	{
		$tool = McpToolSchemaBuilder::buildTool([
			'event_name' => 'demo.write',
			'mcp' => ['risk' => 'write', 'destructive' => false],
		]);

		$this->assertFalse($tool['annotations']['destructiveHint']);
	}

	public function testIdempotentHintDefaultsFalseAndOverridesTrue(): void
	{
		$default_tool = McpToolSchemaBuilder::buildTool([
			'event_name' => 'demo',
			'mcp' => [],
		]);
		$this->assertFalse($default_tool['annotations']['idempotentHint']);

		$override_tool = McpToolSchemaBuilder::buildTool([
			'event_name' => 'demo',
			'mcp' => ['idempotent' => true],
		]);
		$this->assertTrue($override_tool['annotations']['idempotentHint']);
	}

	public function testOpenWorldHintDefaultsTrueAndOverridesFalse(): void
	{
		$default_tool = McpToolSchemaBuilder::buildTool([
			'event_name' => 'demo',
			'mcp' => [],
		]);
		$this->assertTrue($default_tool['annotations']['openWorldHint']);

		$override_tool = McpToolSchemaBuilder::buildTool([
			'event_name' => 'demo',
			'mcp' => ['open_world' => false],
		]);
		$this->assertFalse($override_tool['annotations']['openWorldHint']);
	}

	public function testOutputSchemaEmittedOnlyWhenDeclared(): void
	{
		$without = McpToolSchemaBuilder::buildTool([
			'event_name' => 'demo',
			'mcp' => [],
		]);
		$this->assertArrayNotHasKey('outputSchema', $without);

		$schema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]];
		$with = McpToolSchemaBuilder::buildTool([
			'event_name' => 'demo',
			'mcp' => ['output_schema' => $schema],
		]);
		$this->assertSame($schema, $with['outputSchema']);
	}

	public function testInputSchemaIncludesRequiredAndProperties(): void
	{
		$tool = McpToolSchemaBuilder::buildTool([
			'event_name' => 'demo',
			'mcp' => [],
			'request' => [
				'params' => [
					['name' => 'token_id', 'type' => 'integer', 'required' => true, 'description' => 'Token row id.'],
					['name' => 'format', 'type' => 'string', 'required' => false],
				],
			],
		]);

		$this->assertSame('object', $tool['inputSchema']['type']);
		$this->assertFalse($tool['inputSchema']['additionalProperties']);
		$this->assertSame(['token_id'], $tool['inputSchema']['required']);
		$this->assertSame(['type' => 'integer', 'description' => 'Token row id.'], $tool['inputSchema']['properties']['token_id']);
		$this->assertSame(['type' => 'string'], $tool['inputSchema']['properties']['format']);
	}
}
