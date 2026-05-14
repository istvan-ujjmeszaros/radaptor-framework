<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/class.LocaleService.php';
require_once __DIR__ . '/../classes/enum.CsvImportMode.php';
require_once __DIR__ . '/../classes/class.I18nCsvSchema.php';
require_once __DIR__ . '/../classes/class.I18nShippedDatabaseAuditService.php';

final class I18nShippedDatabaseAuditServiceTest extends TestCase
{
	public function testSummarizeSyncResultReportsOnlyActionableLocalesInSuggestedCommand(): void
	{
		$result = I18nShippedDatabaseAuditService::summarizeSyncResult([
			'groups_processed' => 1,
			'files_processed' => 3,
			'inserted' => 2,
			'updated' => 1,
			'conflicts' => 0,
			'has_errors' => false,
			'groups' => [
				[
					'group_type' => 'core',
					'group_id' => 'cms_root',
					'files' => [
						[
							'locale' => 'en-US',
							'file' => '/tmp/en-US.csv',
							'processed' => 10,
							'inserted' => 2,
							'updated' => 0,
							'conflicts' => 0,
							'errors' => [],
						],
						[
							'locale' => 'hu-HU',
							'file' => '/tmp/hu-HU.csv',
							'processed' => 10,
							'inserted' => 0,
							'updated' => 1,
							'conflicts' => 0,
							'errors' => [],
						],
						[
							'locale' => 'de-DE',
							'file' => '/tmp/de-DE.csv',
							'processed' => 10,
							'inserted' => 0,
							'updated' => 0,
							'conflicts' => 0,
							'errors' => [],
						],
					],
				],
			],
		], ['de-DE', 'en-US', 'hu-HU']);

		$this->assertSame('needs_sync', $result['status']);
		$this->assertSame(2, $result['missing_rows']);
		$this->assertSame(1, $result['changed_rows']);
		$this->assertSame(['en-US', 'hu-HU'], $result['sync_locales']);
		$this->assertSame('radaptor i18n:sync-shipped --locale en-US,hu-HU', $result['suggested_command']);
	}

	public function testCustomizedHumanReviewedRowsAreReportedWithoutFailingDoctor(): void
	{
		$result = I18nShippedDatabaseAuditService::summarizeSyncResult([
			'groups_processed' => 1,
			'files_processed' => 1,
			'inserted' => 0,
			'updated' => 0,
			'conflicts' => 1,
			'has_errors' => false,
			'groups' => [
				[
					'group_type' => 'core',
					'group_id' => 'cms_root',
					'files' => [
						[
							'locale' => 'en-US',
							'file' => '/tmp/en-US.csv',
							'processed' => 10,
							'inserted' => 0,
							'updated' => 0,
							'conflicts' => 1,
							'errors' => [],
						],
					],
				],
			],
		], ['en-US']);

		$this->assertSame('customized', $result['status']);
		$this->assertSame(1, $result['customized_rows']);
		$this->assertSame([], $result['sync_locales']);
		$this->assertSame('', $result['suggested_command']);
		$this->assertSame('shipped_i18n_database_customized', $result['issues'][0]['code'] ?? null);
	}

	public function testActionableDriftTakesPrecedenceOverCustomizedRows(): void
	{
		$result = I18nShippedDatabaseAuditService::summarizeSyncResult([
			'groups_processed' => 1,
			'files_processed' => 1,
			'inserted' => 5,
			'updated' => 0,
			'conflicts' => 3,
			'has_errors' => false,
			'groups' => [
				[
					'group_type' => 'core',
					'group_id' => 'cms_root',
					'files' => [
						[
							'locale' => 'hu-HU',
							'file' => '/tmp/hu-HU.csv',
							'processed' => 10,
							'inserted' => 5,
							'updated' => 0,
							'conflicts' => 3,
							'errors' => [],
						],
					],
				],
			],
		], ['hu-HU']);

		$this->assertSame('needs_sync', $result['status']);
		$this->assertSame(5, $result['missing_rows']);
		$this->assertSame(3, $result['customized_rows']);
		$this->assertSame(['hu-HU'], $result['sync_locales']);
		$this->assertSame('radaptor i18n:sync-shipped --locale hu-HU', $result['suggested_command']);
	}

	public function testImportErrorsAreErrorsAndSuggestAffectedLocale(): void
	{
		$result = I18nShippedDatabaseAuditService::summarizeSyncResult([
			'groups_processed' => 1,
			'files_processed' => 1,
			'inserted' => 0,
			'updated' => 0,
			'conflicts' => 0,
			'has_errors' => true,
			'groups' => [
				[
					'group_type' => 'core',
					'group_id' => 'cms_root',
					'files' => [
						[
							'locale' => 'de-DE',
							'file' => '/tmp/de-DE.csv',
							'processed' => 10,
							'inserted' => 0,
							'updated' => 0,
							'conflicts' => 0,
							'errors' => ['Line 2: locale is not registered'],
						],
					],
				],
			],
		], ['de-DE', 'en-US']);

		$this->assertSame('error', $result['status']);
		$this->assertSame(['de-DE'], $result['sync_locales']);
		$this->assertSame('radaptor i18n:sync-shipped --locale de-DE', $result['suggested_command']);
		$this->assertSame('shipped_i18n_seed_import_error', $result['issues'][0]['code'] ?? null);
		$this->assertSame('shipped_i18n_legacy_error', $result['issues'][0]['errors'][0]['code'] ?? null);
	}

	public function testMissingSeedFilesAreSkippedForEnabledLocalesNotShippedByPackage(): void
	{
		$temp_dir = sys_get_temp_dir() . '/radaptor-i18n-audit-' . bin2hex(random_bytes(6));
		mkdir($temp_dir);

		try {
			$result = I18nShippedDatabaseAuditService::auditTargets([[
				'group_type' => 'core',
				'group_id' => 'test',
				'input_dir' => $temp_dir,
			]], ['en-US']);
		} finally {
			rmdir($temp_dir);
		}

		$this->assertSame('ok', $result['status']);
		$this->assertSame(1, $result['groups_processed']);
		$this->assertSame(0, $result['files_processed']);
		$this->assertSame([], $result['sync_locales']);
		$this->assertSame('', $result['suggested_command']);
		$this->assertSame([], $result['issues']);
	}

	public function testAuditUsesShippedSourceTextHashForExistingMessages(): void
	{
		$method = new ReflectionMethod(I18nShippedDatabaseAuditService::class, 'resolveSourceHashForSeedRow');
		$existing_message = ['source_hash' => md5('old source')];

		$this->assertSame(
			md5('new source'),
			$method->invoke(null, ['source_text' => 'new source'], $existing_message)
		);
		$this->assertSame(
			md5('old source'),
			$method->invoke(null, ['source_text' => ''], $existing_message)
		);
		$this->assertNull($method->invoke(null, ['source_text' => ''], null));
	}

	public function testImportedAllowSourceMatchNormalizesAgainstIncomingText(): void
	{
		$method = new ReflectionMethod(I18nShippedDatabaseAuditService::class, 'resolveImportedAllowSourceMatch');

		$this->assertTrue($method->invoke(null, null, '1', 'hu-HU', 'Locales', 'Locales'));
		$this->assertFalse($method->invoke(null, null, '1', 'hu-HU', 'Locales', 'Locale-ok'));
		$this->assertFalse($method->invoke(null, ['allow_source_match' => 1], '', 'hu-HU', 'Locales', 'Locale-ok'));
		$this->assertTrue($method->invoke(null, ['allow_source_match' => 1], '', 'hu-HU', 'Locales', 'Locales'));
	}
}
