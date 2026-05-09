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

	public function testCanonicalizeRejectsInvalidInputFamily(): void
	{
		foreach ([
			'',
			'   ',
			'x',
			'english-US',
			'en-1234',
			'e1-US',
			'en-Latn-US-extra',
		] as $locale) {
			try {
				LocaleService::canonicalize($locale);
				$this->fail("Expected invalid locale to be rejected: {$locale}");
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testIsCanonicalBcp47AcceptsStorageBoundaryCases(): void
	{
		$this->assertTrue(LocaleService::isCanonicalBcp47('haw'));
		$this->assertTrue(LocaleService::isCanonicalBcp47('en'));
		$this->assertTrue(LocaleService::isCanonicalBcp47('zh-Hans'));
		$this->assertTrue(LocaleService::isCanonicalBcp47('es-419'));
		$this->assertFalse(LocaleService::isCanonicalBcp47('en_US'));
		$this->assertFalse(LocaleService::isCanonicalBcp47('ZH-hans'));
		$this->assertFalse(LocaleService::isCanonicalBcp47('en-Latn-US-extra'));
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

	public function testAcceptLanguageHandlesQualityBoundaries(): void
	{
		$this->assertSame('de-DE', LocaleService::matchEnabledLocaleFromAcceptLanguage(
			'hu-HU;q=0, de-DE;q=1',
			['hu-HU', 'de-DE', 'en-US'],
			'en-US'
		));
		$this->assertSame('de-DE', LocaleService::matchEnabledLocaleFromAcceptLanguage(
			'hu-HU;q=1.5, de-DE;q=0.8',
			['hu-HU', 'de-DE', 'en-US'],
			'en-US'
		));
		$this->assertSame('hu-HU', LocaleService::matchEnabledLocaleFromAcceptLanguage(
			'hu-HU, de-DE;q=0.8',
			['hu-HU', 'de-DE', 'en-US'],
			'en-US'
		));
	}

	public function testAcceptLanguageHandlesEmptyAndCommaHeavyHeaders(): void
	{
		$this->assertSame('en-US', LocaleService::matchEnabledLocaleFromAcceptLanguage(
			'',
			['hu-HU', 'en-US'],
			'en-US'
		));
		$this->assertSame('hu-HU', LocaleService::matchEnabledLocaleFromAcceptLanguage(
			',, hu-HU ,, en-US;q=0.5,',
			['hu-HU', 'en-US'],
			'en-US'
		));
	}
}
