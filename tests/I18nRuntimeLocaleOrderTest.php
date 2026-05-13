<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/class.LocaleService.php';
require_once __DIR__ . '/../classes/class.I18nRuntime.php';

final class I18nRuntimeLocaleOrderTest extends TestCase
{
	public function testNormalizeLocaleRowsPreservesDatabaseOrderWhileDeduplicating(): void
	{
		$method = new ReflectionMethod(I18nRuntime::class, '_normalizeLocaleRows');
		$result = $method->invoke(null, [
			['locale' => 'hu-HU'],
			['locale' => 'en-US'],
			['locale' => 'hu_HU'],
			['locale' => 'de-DE'],
		]);

		$this->assertSame(['hu-HU', 'en-US', 'de-DE'], $result);
	}
}
