<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/MCP/classes/class.McpHttpHeaders.php';

final class McpHttpHeadersTest extends TestCase
{
	public function testFirstScalarReturnsValueForScalarHeader(): void
	{
		$this->assertSame('Bearer abc', McpHttpHeaders::firstScalar(['Authorization' => 'Bearer abc'], 'authorization'));
	}

	public function testFirstScalarUnwrapsListValue(): void
	{
		$this->assertSame('http://localhost', McpHttpHeaders::firstScalar(['Origin' => ['http://localhost']], 'origin'));
	}

	public function testFirstScalarReturnsNullForNestedArrayValue(): void
	{
		$this->assertNull(McpHttpHeaders::firstScalar(['Origin' => [['http://localhost']]], 'origin'));
	}

	public function testFirstScalarReturnsNullForEmptyArrayValue(): void
	{
		$this->assertNull(McpHttpHeaders::firstScalar(['Origin' => []], 'origin'));
	}

	public function testFirstScalarMatchesNameCaseInsensitively(): void
	{
		$this->assertSame('1', McpHttpHeaders::firstScalar(['MCP-PROTOCOL-VERSION' => '1'], 'mcp-protocol-version'));
		$this->assertSame('2', McpHttpHeaders::firstScalar(['mcp-protocol-version' => '2'], 'MCP-Protocol-Version'));
	}

	public function testFirstScalarReturnsNullWhenAbsent(): void
	{
		$this->assertNull(McpHttpHeaders::firstScalar(['X-Other' => 'v'], 'authorization'));
	}

	public function testFirstScalarTrimsWhitespace(): void
	{
		$this->assertSame('value', McpHttpHeaders::firstScalar(['X' => "  value  "], 'x'));
	}

	public function testAcceptsJsonAndEventStreamRejectsNullAndEmpty(): void
	{
		$this->assertFalse(McpHttpHeaders::acceptsJsonAndEventStream(null));
		$this->assertFalse(McpHttpHeaders::acceptsJsonAndEventStream(''));
		$this->assertFalse(McpHttpHeaders::acceptsJsonAndEventStream('   '));
	}

	public function testAcceptsJsonAndEventStreamHappyPath(): void
	{
		$this->assertTrue(McpHttpHeaders::acceptsJsonAndEventStream('application/json, text/event-stream'));
	}

	public function testAcceptsJsonAndEventStreamWithQParameters(): void
	{
		$this->assertTrue(McpHttpHeaders::acceptsJsonAndEventStream('application/json;q=0.9, text/event-stream;q=0.8'));
	}

	public function testAcceptsJsonAndEventStreamAcceptsWildcard(): void
	{
		$this->assertTrue(McpHttpHeaders::acceptsJsonAndEventStream('*/*'));
		$this->assertTrue(McpHttpHeaders::acceptsJsonAndEventStream('application/json, */*'));
	}

	public function testAcceptsJsonAndEventStreamIsCaseInsensitive(): void
	{
		$this->assertTrue(McpHttpHeaders::acceptsJsonAndEventStream('APPLICATION/JSON, TEXT/EVENT-STREAM'));
	}

	public function testAcceptsJsonAndEventStreamRejectsJsonOnly(): void
	{
		$this->assertFalse(McpHttpHeaders::acceptsJsonAndEventStream('application/json'));
	}

	public function testAcceptsJsonAndEventStreamRejectsEventStreamOnly(): void
	{
		$this->assertFalse(McpHttpHeaders::acceptsJsonAndEventStream('text/event-stream'));
	}

	public function testAcceptsJsonAndEventStreamRejectsHtml(): void
	{
		$this->assertFalse(McpHttpHeaders::acceptsJsonAndEventStream('text/html'));
	}
}
