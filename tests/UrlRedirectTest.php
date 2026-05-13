<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/class.Url.php';

final class UrlRedirectTest extends TestCase
{
	public function testRedirectDefaultsToTemporaryFoundStatus(): void
	{
		$method = new ReflectionMethod(Url::class, 'redirect');
		$parameter = $method->getParameters()[1] ?? null;

		$this->assertNotNull($parameter);
		$this->assertTrue($parameter->isDefaultValueAvailable());
		$this->assertSame(302, $parameter->getDefaultValue());
	}

	public function testRedirectStatusHeaderSupportsExpectedCodes(): void
	{
		$this->assertSame('HTTP/1.1 301 Moved Permanently', Url::redirectStatusHeader(301));
		$this->assertSame('HTTP/1.1 302 Found', Url::redirectStatusHeader(302));
		$this->assertSame('HTTP/1.1 303 See Other', Url::redirectStatusHeader(303));
		$this->assertSame('HTTP/1.1 307 Temporary Redirect', Url::redirectStatusHeader(307));
		$this->assertSame('HTTP/1.1 308 Permanent Redirect', Url::redirectStatusHeader(308));
	}

	public function testRedirectStatusHeaderRejectsUnsupportedCodes(): void
	{
		$this->expectException(InvalidArgumentException::class);

		Url::redirectStatusHeader(304);
	}
}
