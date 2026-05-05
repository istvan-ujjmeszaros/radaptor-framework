<?php

declare(strict_types=1);

class Migration_20260429_020000_create_mcp_tokens_webpage
{
	public function getDescription(): string
	{
		return 'Compatibility stub for MCP token management webpage creation.';
	}

	public function run(): void
	{
		// Intentionally empty. CMS pages are content and are managed through
		// app seeds, widget default-path generation, or explicit resource specs.
	}
}
