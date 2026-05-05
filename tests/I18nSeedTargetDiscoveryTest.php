<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('DEPLOY_ROOT')) {
	$fixture_root = sys_get_temp_dir() . '/radaptor-i18n-discovery-' . bin2hex(random_bytes(6));
	define('I18N_SEED_DISCOVERY_FIXTURE_ROOT', $fixture_root);
	define('DEPLOY_ROOT', $fixture_root . '/');
} else {
	define('I18N_SEED_DISCOVERY_FIXTURE_ROOT', '');
}

require_once __DIR__ . '/../classes/class.PackageTypeHelper.php';
require_once __DIR__ . '/../classes/class.PackageManifest.php';
require_once __DIR__ . '/../classes/class.PackageLocalOverrideHelper.php';
require_once __DIR__ . '/../classes/class.PackageLockfile.php';
require_once __DIR__ . '/../classes/class.PackagePathHelper.php';
require_once __DIR__ . '/../classes/class.PluginIdHelper.php';
require_once __DIR__ . '/../classes/class.PluginLockfile.php';
require_once __DIR__ . '/../classes/class.WorkspaceConsumerDiscovery.php';
require_once __DIR__ . '/../classes/class.I18nSeedTargetDiscovery.php';

final class I18nSeedTargetDiscoveryTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		$root = self::fixtureRoot();

		if ($root === '') {
			return;
		}

		self::mkdir($root . '/app/i18n/seeds');
		self::createPackage($root . '/packages/registry/core/active-core', 'core', 'active-core');
		self::createPackage($root . '/packages/registry/core/inactive-core', 'core', 'inactive-core');
		self::createPackage($root . '/packages/registry/themes/inactive-theme', 'theme', 'inactive-theme');
		self::createPackage($root . '/packages/dev/core/dev-core', 'core', 'dev-core');
		self::createPackage($root . '/packages/dev/themes/dev-theme', 'theme', 'dev-theme');
		self::mkdir($root . '/plugins/dev/locked-plugin/i18n/seeds');
		self::mkdir($root . '/plugins/dev/inactive-plugin/i18n/seeds');
		self::mkdir($root . '/plugins/registry/registry-only-plugin/i18n/seeds');
		self::mkdir($root . '/packages/registry/core/inactive-core/vendor/ignored/i18n/seeds');

		file_put_contents($root . '/radaptor.json', json_encode([
			'manifest_version' => 1,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

		file_put_contents($root . '/radaptor.lock.json', json_encode([
			'lockfile_version' => 1,
			'core' => [
				'active-core' => [
					'type' => 'core',
					'id' => 'active-core',
					'package' => 'radaptor/core/active-core',
					'source' => ['type' => 'registry'],
					'resolved' => [
						'type' => 'registry',
						'path' => 'packages/registry/core/active-core',
					],
				],
			],
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

		file_put_contents($root . '/plugins.lock.json', json_encode([
			'lockfile_version' => 1,
			'plugins' => [
				'locked-plugin' => [
					'plugin_id' => 'locked-plugin',
					'resolved' => [
						'type' => 'dev',
						'path' => 'plugins/dev/locked-plugin',
					],
				],
			],
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	}

	public static function tearDownAfterClass(): void
	{
		$root = self::fixtureRoot();

		if ($root !== '') {
			self::deleteTree($root);
		}
	}

	public function testActiveDiscoveryExcludesUnreferencedPackagesAndPlugins(): void
	{
		$this->skipWhenDeployRootIsAlreadyDefined();

		$targets = I18nSeedTargetDiscovery::discoverTargets([
			'include_static' => false,
		]);
		$groupIds = self::groupIds($targets);

		$this->assertContains('app', $groupIds);
		$this->assertContains('active-core', $groupIds);
		$this->assertContains('locked-plugin', $groupIds);
		$this->assertNotContains('inactive-core', $groupIds);
		$this->assertNotContains('inactive-theme', $groupIds);
		$this->assertNotContains('dev-core', $groupIds);
		$this->assertNotContains('dev-theme', $groupIds);
		$this->assertNotContains('inactive-plugin', $groupIds);
		$this->assertNotContains('registry-only-plugin', $groupIds);
	}

	public function testAllPackagesDiscoveryIsAuditOnlyScope(): void
	{
		$this->skipWhenDeployRootIsAlreadyDefined();

		$targets = I18nSeedTargetDiscovery::discoverTargets([
			'include_static' => false,
			'all_packages' => true,
		]);
		$groupIds = self::groupIds($targets);

		$this->assertContains('app', $groupIds);
		$this->assertContains('active-core', $groupIds);
		$this->assertContains('locked-plugin', $groupIds);
		$this->assertContains('inactive-core', $groupIds);
		$this->assertContains('inactive-theme', $groupIds);
		$this->assertContains('dev-core', $groupIds);
		$this->assertContains('dev-theme', $groupIds);
		$this->assertContains('inactive-plugin', $groupIds);
		$this->assertContains('registry-only-plugin', $groupIds);
		$this->assertNotContains('ignored', $groupIds);
	}

	/**
	 * @param list<array<string, mixed>> $targets
	 * @return list<string>
	 */
	private static function groupIds(array $targets): array
	{
		$groupIds = array_map(static fn (array $target): string => (string) $target['group_id'], $targets);
		sort($groupIds);

		return $groupIds;
	}

	private static function createPackage(string $root, string $type, string $id): void
	{
		self::mkdir($root . '/i18n/seeds');
		file_put_contents($root . '/.registry-package.json', json_encode([
			'package' => 'radaptor/' . $type . '/' . $id,
			'type' => $type,
			'id' => $id,
			'version' => '0.0.0-test',
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	}

	private function skipWhenDeployRootIsAlreadyDefined(): void
	{
		if (self::fixtureRoot() === '') {
			$this->markTestSkipped('DEPLOY_ROOT is already defined by the runtime bootstrap.');
		}
	}

	private static function mkdir(string $path): void
	{
		if (!is_dir($path) && !mkdir($path, 0o777, true) && !is_dir($path)) {
			throw new RuntimeException("Unable to create fixture directory: {$path}");
		}
	}

	private static function fixtureRoot(): string
	{
		return (string) I18N_SEED_DISCOVERY_FIXTURE_ROOT;
	}

	private static function deleteTree(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}

		rmdir($path);
	}
}
