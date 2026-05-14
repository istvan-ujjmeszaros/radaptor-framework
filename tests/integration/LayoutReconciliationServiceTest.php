<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LayoutReconciliationServiceTest extends TestCase
{
	private static bool $_runtime_bootstrapped = false;

	private bool $_transaction_started = false;

	private int $_test_resource_id = 0;

	private int $_audit_baseline_max_id = 0;

	protected function setUp(): void
	{
		self::bootstrapConsumerRuntime();

		if (
			!class_exists('Db', autoload: false)
			|| !class_exists('LayoutReconciliationService')
			|| !class_exists('CmsMutationAuditService')
		) {
			self::markTestSkipped('The Radaptor consumer app runtime is required for LayoutReconciliationService integration tests.');
		}

		$audit_pdo = Db::createIndependentPdoConnection();
		$this->_audit_baseline_max_id = (int) ($audit_pdo->query(
			"SELECT COALESCE(MAX(cms_mutation_audit_id), 0) FROM `cms_mutation_audit`"
		)->fetchColumn() ?: 0);

		$pdo = Db::instance();

		if (!$pdo->inTransaction()) {
			$pdo->beginTransaction();
			$this->_transaction_started = true;
		}

		$this->_test_resource_id = $this->insertScratchWebpage();
	}

	protected function tearDown(): void
	{
		if ($this->_transaction_started) {
			$pdo = Db::instance();

			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
		}

		$this->_transaction_started = false;

		// The audit service writes through an independent PDO connection that bypasses our
		// rollback. Clean up any layout:rename* rows this test wrote.
		$audit_pdo = Db::createIndependentPdoConnection();
		$audit_pdo->prepare(
			"DELETE FROM `cms_mutation_audit`
			WHERE cms_mutation_audit_id > ? AND operation LIKE 'layout:rename%'"
		)->execute([$this->_audit_baseline_max_id]);
	}

	public function testCollectPendingFindsWebpageAndThemeSettings(): void
	{
		$this->insertLayoutAttribute($this->_test_resource_id, 'admin_nomenu');
		$this->insertThemeSetting('admin_nomenu', 'SoAdmin');

		$pending = LayoutReconciliationService::collectPending(['admin_nomenu' => 'admin_login']);

		$this->assertTrue($pending['has_changes']);
		$this->assertCount(1, $pending['webpages']);
		$this->assertSame($this->_test_resource_id, $pending['webpages'][0]['resource_id']);
		$this->assertSame('admin_nomenu', $pending['webpages'][0]['old_layout']);
		$this->assertSame('admin_login', $pending['webpages'][0]['new_layout']);
		$this->assertCount(1, $pending['theme_settings']);
		$this->assertFalse($pending['theme_settings'][0]['conflict']);
		$this->assertSame('SoAdmin', $pending['theme_settings'][0]['theme']);
	}

	public function testCollectPendingDetectsThemeSettingsConflict(): void
	{
		$this->insertThemeSetting('admin_nomenu', 'SoAdmin');
		$this->insertThemeSetting('admin_login', 'PortalAdmin');

		$pending = LayoutReconciliationService::collectPending(['admin_nomenu' => 'admin_login']);

		$this->assertTrue($pending['has_changes']);
		$this->assertCount(1, $pending['theme_settings']);
		$this->assertTrue($pending['theme_settings'][0]['conflict']);
		$this->assertSame('PortalAdmin', $pending['theme_settings'][0]['conflict_theme']);
	}

	public function testApplyRewritesWebpageLayoutAndWritesAudit(): void
	{
		$this->insertLayoutAttribute($this->_test_resource_id, 'admin_nomenu');

		$pending = LayoutReconciliationService::collectPending(['admin_nomenu' => 'admin_login']);
		$applied = LayoutReconciliationService::apply($pending, [
			'admin_nomenu' => ['new_layout' => 'admin_login', 'package' => 'radaptor/themes/so-admin', 'version' => '1.0.0'],
		]);

		$this->assertSame(1, $applied['webpages_updated']);
		$this->assertSame('admin_login', $this->readLayoutAttribute($this->_test_resource_id));

		$operations = $this->readNewAuditOperations();
		$this->assertContains('layout:rename', $operations, 'context_started/context_finished rows for layout:rename should be present.');
		$this->assertContains('layout:rename:webpage', $operations, 'A leaf row for the webpage rename should be present.');
	}

	public function testApplyResolvesThemeSettingsConflictByDroppingOld(): void
	{
		$this->insertThemeSetting('admin_nomenu', 'SoAdmin');
		$this->insertThemeSetting('admin_login', 'PortalAdmin');

		$pending = LayoutReconciliationService::collectPending(['admin_nomenu' => 'admin_login']);
		$applied = LayoutReconciliationService::apply($pending);

		$this->assertSame(0, $applied['theme_settings_renamed']);
		$this->assertSame(1, $applied['theme_settings_dropped']);

		// The new mapping should remain untouched
		$this->assertSame('PortalAdmin', $this->readThemeSetting('admin_login'));
		// The old key should be gone
		$this->assertNull($this->readThemeSetting('admin_nomenu'));

		$this->assertContains('layout:rename:theme_settings_conflict', $this->readNewAuditOperations());
	}

	public function testApplyRenamesThemeSettingsKeyWithoutConflict(): void
	{
		$this->insertThemeSetting('admin_nomenu', 'SoAdmin');

		$pending = LayoutReconciliationService::collectPending(['admin_nomenu' => 'admin_login']);
		$applied = LayoutReconciliationService::apply($pending);

		$this->assertSame(1, $applied['theme_settings_renamed']);
		$this->assertSame(0, $applied['theme_settings_dropped']);
		$this->assertSame('SoAdmin', $this->readThemeSetting('admin_login'));
		$this->assertNull($this->readThemeSetting('admin_nomenu'));

		$this->assertContains('layout:rename:theme_settings', $this->readNewAuditOperations());
	}

	/**
	 * @return list<string>
	 */
	private function readNewAuditOperations(): array
	{
		$audit_pdo = Db::createIndependentPdoConnection();
		$stmt = $audit_pdo->prepare(
			"SELECT operation FROM `cms_mutation_audit`
			WHERE cms_mutation_audit_id > ?
			  AND operation LIKE 'layout:rename%'
			ORDER BY cms_mutation_audit_id"
		);
		$stmt->execute([$this->_audit_baseline_max_id]);

		return array_map(static fn (array $row): string => (string) $row['operation'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
	}

	private function insertScratchWebpage(): int
	{
		$pdo = Db::instance();
		// Sentinel resource for tests: a webpage node under root. Rolls back with the transaction.
		$stmt = $pdo->prepare(
			"INSERT INTO `resource_tree` (`lft`, `rgt`, `parent_id`, `node_type`, `path`, `resource_name`)
			VALUES (?, ?, 0, 'webpage', '/__layout_rename_test/', ?)"
		);
		$resource_name = 'scratch-' . bin2hex(random_bytes(4)) . '.html';
		$stmt->execute([900000 + random_int(0, 100), 900001 + random_int(0, 100), $resource_name]);

		return (int) $pdo->lastInsertId();
	}

	private function insertLayoutAttribute(int $resource_id, string $layout): void
	{
		Db::instance()
			->prepare("INSERT INTO `attributes` (`resource_name`, `resource_id`, `param_name`, `param_value`) VALUES ('resource_data', ?, 'layout', ?)")
			->execute([$resource_id, $layout]);
	}

	private function readLayoutAttribute(int $resource_id): ?string
	{
		$stmt = Db::instance()->prepare(
			"SELECT `param_value` FROM `attributes`
			WHERE `resource_name` = 'resource_data' AND `resource_id` = ? AND `param_name` = 'layout'
			LIMIT 1"
		);
		$stmt->execute([$resource_id]);
		$value = $stmt->fetchColumn();

		return $value === false ? null : (string) $value;
	}

	private function insertThemeSetting(string $layout, string $theme): void
	{
		Db::instance()
			->prepare("INSERT INTO `attributes` (`resource_name`, `resource_id`, `param_name`, `param_value`) VALUES ('_theme_settings', 0, ?, ?)")
			->execute([$layout, $theme]);
	}

	private function readThemeSetting(string $layout): ?string
	{
		$stmt = Db::instance()->prepare(
			"SELECT `param_value` FROM `attributes`
			WHERE `resource_name` = '_theme_settings' AND `resource_id` = 0 AND `param_name` = ?
			LIMIT 1"
		);
		$stmt->execute([$layout]);
		$value = $stmt->fetchColumn();

		return $value === false ? null : (string) $value;
	}

	private static function bootstrapConsumerRuntime(): void
	{
		if (self::$_runtime_bootstrapped || class_exists('Db', autoload: false)) {
			self::$_runtime_bootstrapped = true;

			return;
		}

		$bootstrap = getenv('RADAPTOR_APP_TEST_BOOTSTRAP') ?: '/app/bootstrap/bootstrap.testing.php';

		if (!is_file($bootstrap)) {
			self::markTestSkipped('Set RADAPTOR_APP_TEST_BOOTSTRAP or run from the Radaptor app container to execute LayoutReconciliationService integration tests.');
		}

		require_once $bootstrap;
		restore_error_handler();
		restore_exception_handler();

		self::$_runtime_bootstrapped = true;
	}
}
