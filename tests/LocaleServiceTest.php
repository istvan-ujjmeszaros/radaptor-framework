<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/class.LocaleService.php';

final class LocaleServiceTest extends TestCase
{
	public function testCanonicalizeAcceptsLegacyUnderscoreInput(): void
	{
		$this->assertSame('en-US', LocaleService::canonicalize('en_US'));
		$this->assertSame('zh-Hans-CN', LocaleService::canonicalize('zh_Hans_CN'));
	}

	public function testAcceptLanguageUsesQualityAndExactMatch(): void
	{
		$this->assertSame('hu-HU', LocaleService::matchEnabledLocaleFromAcceptLanguage(
			'de-DE;q=0.5, hu-HU;q=0.9, en-US;q=0.8',
			['en-US', 'hu-HU', 'de-DE'],
			'en-US'
		));
	}

	public function testAcceptLanguageDoesNotCrossScriptVariants(): void
	{
		$this->assertSame('en-US', LocaleService::matchEnabledLocaleFromAcceptLanguage(
			'zh-Hant-TW, en-US;q=0.8',
			['zh-Hans-CN', 'en-US'],
			'en-US'
		));
	}

	public function testAcceptLanguageAllowsUnambiguousLanguageOnlyFallback(): void
	{
		$this->assertSame('hu-HU', LocaleService::matchEnabledLocaleFromAcceptLanguage(
			'hu-RO, en-US;q=0.8',
			['hu-HU', 'en-US'],
			'en-US'
		));
	}
}
