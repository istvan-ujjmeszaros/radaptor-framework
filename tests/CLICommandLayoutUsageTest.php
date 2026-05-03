<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/class.Request.php';
require_once __DIR__ . '/../modules/CLI/classes/class.AbstractCLICommand.php';
require_once __DIR__ . '/../modules/CLI/events/CLICommand.LayoutUsage.php';

final class CLICommandLayoutUsageTest extends TestCase
{
	/** @var array<int, string> */
	private array $originalArgv = [];

	protected function setUp(): void
	{
		parent::setUp();

		global $argv;
		$this->originalArgv = $argv ?? [];
	}

	protected function tearDown(): void
	{
		global $argv;
		$argv = $this->originalArgv;

		parent::tearDown();
	}

	public function testJsonOutputReturnsControlledErrorWhenCmsInspectorIsMissing(): void
	{
		$this->assertFalse(class_exists('CmsUsageInspector', false));

		global $argv;
		$argv = ['radaptor', 'layout:usage', 'admin_login', '--json'];

		ob_start();

		try {
			(new CLICommandLayoutUsage())->run();
		} finally {
			$output = (string) ob_get_clean();
		}

		$decoded = json_decode($output, true);
		$this->assertIsArray($decoded);
		$this->assertSame('error', $decoded['status']);
		$this->assertSame(
			'CMS usage inspector is not available. Install or enable core:cms to use layout usage diagnostics.',
			$decoded['message']
		);
	}
}
