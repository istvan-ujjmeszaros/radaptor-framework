<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LocaleAdminServiceTest extends TestCase
{
	private const string FAKE_DEFAULT_LOCALE = 'zz-ZZ';

	private static bool $_runtime_bootstrapped = false;

	private bool $_transaction_started = false;

	private ?string $_original_app_default_locale_env = null;

	protected function setUp(): void
	{
		self::bootstrapConsumerRuntime();

		if (!class_exists('Db') || !class_exists('LocaleAdminService') || !class_exists('LocaleService')) {
			self::markTestSkipped('The Radaptor consumer app runtime is required for LocaleAdminService integration tests.');
		}

		$pdo = Db::instance();

		if (!$pdo->inTransaction()) {
			$pdo->beginTransaction();
			$this->_transaction_started = true;
		}
	}

	protected function tearDown(): void
	{
		$this->restoreAppDefaultLocaleEnv();

		if ($this->_transaction_started) {
			$pdo = Db::instance();

			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
		}

		$this->_transaction_started = false;
	}

	public function testSetEnabledTogglesIsEnabledColumn(): void
	{
		$locale = $this->getNonDefaultTestLocale();

		LocaleAdminService::ensureLocale($locale, true);

		LocaleAdminService::setEnabled($locale, false);

		$this->assertSame(0, $this->fetchIsEnabled($locale));

		LocaleAdminService::setEnabled($locale, true);

		$this->assertSame(1, $this->fetchIsEnabled($locale));
	}

	public function testEnsureLocaleIsIdempotent(): void
	{
		$locale = $this->getNonDefaultTestLocale(1);

		LocaleAdminService::ensureLocale($locale, true);
		$first = $this->fetchRow($locale);

		LocaleAdminService::ensureLocale($locale, true);
		$second = $this->fetchRow($locale);

		$this->assertNotNull($first);
		$this->assertNotNull($second);
		$this->assertSame($first['sort_order'], $second['sort_order']);
		$this->assertSame(1, (int) $second['is_enabled']);
	}

	public function testListLocalesMarksDefault(): void
	{
		$default = LocaleService::getDefaultLocale();
		$rows = LocaleAdminService::listLocales();

		$defaults = array_values(array_filter($rows, static fn (array $row): bool => ($row['is_default'] ?? false) === true));

		$this->assertCount(1, $defaults, 'Exactly one row should be marked as default.');
		$this->assertSame($default, $defaults[0]['locale']);
	}

	public function testSetEnabledFalseOnDefaultThrowsBeforeInsert(): void
	{
		$precondition_count = (int) Db::instance()
			->query("SELECT COUNT(*) FROM `locales` WHERE `locale` = '" . self::FAKE_DEFAULT_LOCALE . "'")
			->fetchColumn();

		if ($precondition_count !== 0) {
			self::markTestSkipped(sprintf(
				'Test locale "%s" must not exist in the locales table before this test runs (found %d row(s)). Pick another unused canonical locale.',
				self::FAKE_DEFAULT_LOCALE,
				$precondition_count
			));
		}

		$this->setAppDefaultLocaleEnv(self::FAKE_DEFAULT_LOCALE);

		$threw = false;

		try {
			LocaleAdminService::setEnabled(self::FAKE_DEFAULT_LOCALE, false);
		} catch (RuntimeException $exception) {
			$threw = true;
			$this->assertStringContainsString('APP_DEFAULT_LOCALE', $exception->getMessage());
		}

		$this->assertTrue($threw, 'setEnabled(default, false) must throw RuntimeException.');

		$post_count = (int) Db::instance()
			->query("SELECT COUNT(*) FROM `locales` WHERE `locale` = '" . self::FAKE_DEFAULT_LOCALE . "'")
			->fetchColumn();

		$this->assertSame(
			0,
			$post_count,
			'setEnabled(default, false) must throw BEFORE ensureLocale() inserts a row. Pre-bugfix code would have inserted a disabled row before throwing.'
		);
	}

	private function fetchIsEnabled(string $locale): int
	{
		$row = $this->fetchRow($locale);
		$this->assertNotNull($row, "Expected row for locale {$locale}.");

		return (int) $row['is_enabled'];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function fetchRow(string $locale): ?array
	{
		$stmt = Db::instance()->prepare('SELECT * FROM `locales` WHERE `locale` = ?');
		$stmt->execute([$locale]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}

	private function setAppDefaultLocaleEnv(string $locale): void
	{
		if ($this->_original_app_default_locale_env === null) {
			$current = getenv('APP_DEFAULT_LOCALE');
			$this->_original_app_default_locale_env = $current === false ? '' : $current;
		}

		putenv('APP_DEFAULT_LOCALE=' . $locale);
	}

	private function restoreAppDefaultLocaleEnv(): void
	{
		if ($this->_original_app_default_locale_env === null) {
			return;
		}

		if ($this->_original_app_default_locale_env === '') {
			putenv('APP_DEFAULT_LOCALE');
		} else {
			putenv('APP_DEFAULT_LOCALE=' . $this->_original_app_default_locale_env);
		}

		$this->_original_app_default_locale_env = null;
	}

	private function getNonDefaultTestLocale(int $offset = 0): string
	{
		$default = LocaleService::getDefaultLocale();
		$candidates = [
			'de-DE',
			'fr-FR',
			'es-ES',
			'it-IT',
			'pt-BR',
			'pl-PL',
			'ja-JP',
			'ko-KR',
			'ru-RU',
			'zh-Hans-CN',
		];
		$locales = array_values(array_filter(
			$candidates,
			static fn (string $locale): bool => $locale !== $default
		));

		if (!isset($locales[$offset])) {
			$this->fail('Unable to select a non-default test locale.');
		}

		return $locales[$offset];
	}

	private static function bootstrapConsumerRuntime(): void
	{
		if (self::$_runtime_bootstrapped || class_exists('Db', autoload: false)) {
			self::$_runtime_bootstrapped = true;

			return;
		}

		$bootstrap = getenv('RADAPTOR_APP_TEST_BOOTSTRAP') ?: '/app/bootstrap/bootstrap.testing.php';

		if (!is_file($bootstrap)) {
			self::markTestSkipped('Set RADAPTOR_APP_TEST_BOOTSTRAP or run from the Radaptor app container to execute LocaleAdminService integration tests.');
		}

		require_once $bootstrap;
		restore_error_handler();
		restore_exception_handler();

		self::$_runtime_bootstrapped = true;
	}
}
