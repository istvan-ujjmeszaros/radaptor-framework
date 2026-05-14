<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/class.LocaleService.php';
require_once __DIR__ . '/../classes/class.LocaleRegistry.php';
require_once __DIR__ . '/../classes/class.I18nCsvSchema.php';
require_once __DIR__ . '/../classes/class.I18nSeedLintService.php';

final class I18nSeedLintServiceTest extends TestCase
{
	private string $tempDir;

	protected function setUp(): void
	{
		$this->tempDir = sys_get_temp_dir() . '/radaptor-i18n-lint-' . bin2hex(random_bytes(6));
		mkdir($this->tempDir, 0o755, true);
	}

	protected function tearDown(): void
	{
		$this->deleteTree($this->tempDir);
	}

	public function testSourceMatchWarningsRequireExplicitAllowFlagEvenForOneWordText(): void
	{
		$this->writeSeed('en-US', [
			['admin', 'menu.locales', '', 'en-US', 'Locales', 'Locales', '0', '0', 'Locales'],
		]);
		$this->writeSeed('hu-HU', [
			['admin', 'menu.locales', '', 'hu-HU', 'Locales', 'Locales', '0', '0', 'Locales'],
		]);

		$result = $this->lint();

		$this->assertSame('warning', $result['status']);
		$this->assertContains('source_match_not_allowed', $this->issueCodes($result));
	}

	public function testAllowSourceMatchSuppressesSourceMatchWarning(): void
	{
		$this->writeSeed('en-US', [
			['admin', 'menu.locales', '', 'en-US', 'Locales', 'Locales', '0', '0', 'Locales'],
		]);
		$this->writeSeed('hu-HU', [
			['admin', 'menu.locales', '', 'hu-HU', 'Locales', 'Locales', '0', '1', 'Locales'],
		]);

		$result = $this->lint();

		$this->assertSame('ok', $result['status']);
		$this->assertNotContains('source_match_not_allowed', $this->issueCodes($result));
	}

	public function testStaleAllowSourceMatchIsAnError(): void
	{
		$this->writeSeed('en-US', [
			['admin', 'menu.locales', '', 'en-US', 'Locales', 'Locales', '0', '0', 'Locales'],
		]);
		$this->writeSeed('hu-HU', [
			['admin', 'menu.locales', '', 'hu-HU', 'Locales', 'Locale-ok', '0', '1', 'Locale-ok'],
		]);

		$result = $this->lint();

		$this->assertSame('error', $result['status']);
		$this->assertContains('allow_source_match_stale', $this->issueCodes($result));
	}

	public function testSourceLocaleCannotSetAllowSourceMatch(): void
	{
		$this->writeSeed('en-US', [
			['admin', 'menu.locales', '', 'en-US', 'Locales', 'Locales', '0', '1', 'Locales'],
		]);

		$result = $this->lint();

		$this->assertSame('error', $result['status']);
		$this->assertContains('source_locale_allow_source_match', $this->issueCodes($result));
	}

	/**
	 * @param list<list<string>> $rows
	 */
	private function writeSeed(string $locale, array $rows): void
	{
		$path = $this->tempDir . '/' . $locale . '.csv';
		$handle = fopen($path, 'w');

		$this->assertIsResource($handle);
		fputcsv($handle, I18nCsvSchema::NORMALIZED_HEADER, ',', '"', '');

		foreach ($rows as $row) {
			fputcsv($handle, $row, ',', '"', '');
		}

		fclose($handle);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function lint(): array
	{
		return I18nSeedLintService::lint([[
			'group_type' => 'test',
			'group_id' => 'fixture',
			'input_dir' => $this->tempDir,
			'domains' => ['admin'],
		]], [
			'expected_locales' => ['en-US'],
			'check_global_duplicates' => false,
		]);
	}

	/**
	 * @param array<string, mixed> $result
	 * @return list<string>
	 */
	private function issueCodes(array $result): array
	{
		return array_values(array_map(
			static fn (array $issue): string => (string) ($issue['code'] ?? ''),
			$result['issues'] ?? []
		));
	}

	private function deleteTree(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		$items = scandir($path);

		if ($items === false) {
			return;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$child = $path . '/' . $item;

			if (is_dir($child)) {
				$this->deleteTree($child);
			} else {
				unlink($child);
			}
		}

		rmdir($path);
	}
}
