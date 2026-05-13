<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../modules/CLI/classes/class.AbstractCLICommand.php';
require_once __DIR__ . '/../modules/Runtime/classes/class.RuntimeSiteCutoverGuard.php';

final class RuntimeSiteCutoverGuardTest extends TestCase
{
	public function testDefaultSafeRiskIsNotTreatedAsExplicitCutoverSafe(): void
	{
		$method = new ReflectionMethod(RuntimeSiteCutoverGuard::class, 'commandDeclaresRiskLevel');

		$this->assertFalse($method->invoke(null, new RuntimeSiteCutoverGuardImplicitSafeCommand()));
		$this->assertTrue($method->invoke(null, new RuntimeSiteCutoverGuardExplicitSafeCommand()));
	}
}

final class RuntimeSiteCutoverGuardImplicitSafeCommand extends AbstractCLICommand
{
	public function run(): void
	{
	}

	public function getName(): string
	{
		return 'Implicit safe test command';
	}

	public function getDocs(): string
	{
		return '';
	}
}

final class RuntimeSiteCutoverGuardExplicitSafeCommand extends AbstractCLICommand
{
	public function run(): void
	{
	}

	public function getName(): string
	{
		return 'Explicit safe test command';
	}

	public function getDocs(): string
	{
		return '';
	}

	public function getRiskLevel(): string
	{
		return 'safe';
	}
}
