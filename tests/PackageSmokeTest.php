<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PackageSmokeTest extends TestCase
{
	public function testRegistryPackageMetadataIsValid(): void
	{
		$root = dirname(__DIR__);
		$metadata_path = $root . '/.registry-package.json';
		$this->assertFileExists($metadata_path);

		$decoded = json_decode((string) file_get_contents($metadata_path), true);
		$this->assertIsArray($decoded);
		$this->assertSame('radaptor/core/framework', $decoded['package'] ?? null);
		$this->assertSame('core', $decoded['type'] ?? null);
		$this->assertSame('framework', $decoded['id'] ?? null);
		$this->assertNotSame('', trim((string) ($decoded['version'] ?? '')));
	}

	public function testFrameworkBootstrapEntrypointsExist(): void
	{
		$root = dirname(__DIR__);

		$this->assertFileExists($root . '/bootstrap.php');
		$this->assertFileExists($root . '/bootstrap.testing.php');
		$this->assertFileExists($root . '/bootstrap.autoloader.php');
		$this->assertFileExists($root . '/classes/class.Kernel.php');
	}
}
