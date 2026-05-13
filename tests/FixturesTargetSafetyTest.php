<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/testing/class.AbstractFixture.php';
require_once __DIR__ . '/../classes/testing/class.Fixtures.php';

final class FixturesTargetSafetyTest extends TestCase
{
	/**
	 * @return iterable<string, array{string}>
	 */
	public static function unsafeDsnProvider(): iterable
	{
		yield 'normal app database' => ['mysql:host=mariadb;port=3306;user=root;password=secret;dbname=radaptor_app'];

		yield 'audit database' => ['mysql:host=mariadb;port=3306;user=root;password=secret;dbname=radaptor_app_audit'];

		yield 'test audit database' => ['mysql:host=mariadb;port=3306;user=root;password=secret;dbname=radaptor_app_test_audit'];
	}

	#[DataProvider('unsafeDsnProvider')]
	public function testFixtureLoadingRejectsNonTestTargetsBeforeConnecting(string $dsn): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Fixture loading target must be an explicit _test database');

		Fixtures::loadAll($dsn);
	}

	public function testFixtureLoadingRequiresNamedDatabase(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not contain a database name');

		Fixtures::loadAll('mysql:host=mariadb;port=3306;user=root;password=secret');
	}
}
