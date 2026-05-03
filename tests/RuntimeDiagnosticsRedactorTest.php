<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../modules/RuntimeDiagnostics/classes/class.RuntimeDiagnosticsRedactor.php';

final class RuntimeDiagnosticsRedactorTest extends TestCase
{
	public function testRedactsSecretLikeKeys(): void
	{
		$redacted = RuntimeDiagnosticsRedactor::redactArray([
			'password' => 'secret',
			'api_token' => 'token-value',
			'nested' => [
				'client_secret' => 'hidden',
				'visible' => 'kept',
			],
		]);

		$this->assertSame(RuntimeDiagnosticsRedactor::MASK, $redacted['password']);
		$this->assertSame(RuntimeDiagnosticsRedactor::MASK, $redacted['api_token']);
		$this->assertSame(RuntimeDiagnosticsRedactor::MASK, $redacted['nested']['client_secret']);
		$this->assertSame('kept', $redacted['nested']['visible']);
	}

	public function testDsnRedactionKeepsUsefulConnectionParts(): void
	{
		$dsn = 'mysql:host=mariadb;port=3306;user=root;password=radaptor_app;dbname=radaptor_app';
		$parsed = RuntimeDiagnosticsRedactor::parseDsn($dsn);

		$this->assertSame('mysql', $parsed['driver']);
		$this->assertSame('mariadb', $parsed['host']);
		$this->assertSame(3306, $parsed['port']);
		$this->assertSame('radaptor_app', $parsed['database']);
		$this->assertSame('root', $parsed['username']);
		$this->assertSame(RuntimeDiagnosticsRedactor::MASK, $parsed['password']);
		$this->assertSame(
			'mysql:host=mariadb;port=3306;user=root;password=[redacted];dbname=radaptor_app',
			$parsed['redacted_dsn']
		);
	}
}
