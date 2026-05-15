<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/class.PackageTypeHelper.php';
require_once __DIR__ . '/../classes/class.PluginVersionHelper.php';
require_once __DIR__ . '/../classes/class.PackageDependencyHelper.php';
require_once __DIR__ . '/../classes/class.PackageMetadataHelper.php';
require_once __DIR__ . '/../classes/class.LayoutRenameRegistry.php';

final class LayoutRenameRegistryTest extends TestCase
{
	private string $_fixture_root = '';

	protected function setUp(): void
	{
		$this->_fixture_root = sys_get_temp_dir() . '/radaptor-layout-rename-' . bin2hex(random_bytes(6));

		if (!mkdir($this->_fixture_root, 0o777, true) && !is_dir($this->_fixture_root)) {
			$this->fail("Unable to create fixture root: {$this->_fixture_root}");
		}
	}

	protected function tearDown(): void
	{
		if ($this->_fixture_root !== '' && is_dir($this->_fixture_root)) {
			$this->removeDirectory($this->_fixture_root);
		}
	}

	public function testBuildFromSourcePathsReturnsEmptyForNoDeclarations(): void
	{
		$theme = $this->writeTheme('so-admin', '1.0.0', []);

		$result = LayoutRenameRegistry::buildFromSourcePaths(['themes:so-admin' => $theme]);

		$this->assertSame([], $result);
	}

	public function testBuildFromSourcePathsAggregatesAndSorts(): void
	{
		$so = $this->writeTheme('so-admin', '1.0.0', ['admin_nomenu' => 'admin_login']);
		$portal = $this->writeTheme('portal-admin', '2.0.0', ['old_widget' => 'new_widget']);

		$result = LayoutRenameRegistry::buildFromSourcePaths([
			'themes:so-admin' => $so,
			'themes:portal-admin' => $portal,
		]);

		$this->assertSame(['admin_nomenu', 'old_widget'], array_keys($result));
		$this->assertSame('admin_login', $result['admin_nomenu']['new_layout']);
		$this->assertSame('radaptor/themes/so-admin', $result['admin_nomenu']['package']);
		$this->assertSame('1.0.0', $result['admin_nomenu']['version']);
		$this->assertSame('new_widget', $result['old_widget']['new_layout']);
		$this->assertSame('radaptor/themes/portal-admin', $result['old_widget']['package']);
	}

	public function testBuildFromSourcePathsThrowsOnConflictingDeclarations(): void
	{
		$so = $this->writeTheme('so-admin', '1.0.0', ['admin_nomenu' => 'admin_login']);
		$portal = $this->writeTheme('portal-admin', '2.0.0', ['admin_nomenu' => 'admin_compact']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/Conflicting deprecated_layouts/');

		LayoutRenameRegistry::buildFromSourcePaths([
			'themes:so-admin' => $so,
			'themes:portal-admin' => $portal,
		]);
	}

	public function testBuildFromSourcePathsIgnoresAgreeingDuplicates(): void
	{
		$so = $this->writeTheme('so-admin', '1.0.0', ['admin_nomenu' => 'admin_login']);
		$cms = $this->writeTheme('cms', '1.0.0', ['admin_nomenu' => 'admin_login']);

		$result = LayoutRenameRegistry::buildFromSourcePaths([
			'themes:so-admin' => $so,
			'core:cms' => $cms,
		]);

		$this->assertSame(['admin_nomenu' => [
			'new_layout' => 'admin_login',
			'package' => 'radaptor/themes/so-admin',
			'version' => '1.0.0',
		]], $result);
	}

	public function testExtractRenameMapReturnsFlatOldToNewArray(): void
	{
		$renames = [
			'admin_nomenu' => ['new_layout' => 'admin_login', 'package' => 'p', 'version' => '1.0.0'],
			'old_widget' => ['new_layout' => 'new_widget', 'package' => 'p', 'version' => '1.0.0'],
		];

		$this->assertSame(
			['admin_nomenu' => 'admin_login', 'old_widget' => 'new_widget'],
			LayoutRenameRegistry::extractRenameMap($renames)
		);
	}

	public function testCollectAvailableLayoutsFindsThemeAndCmsTemplates(): void
	{
		$theme_dir = $this->_fixture_root . '/so-admin';
		mkdir($theme_dir . '/theme/_layout', 0o777, true);
		file_put_contents($theme_dir . '/theme/_layout/template.layout_admin_login.php', '<?php');
		file_put_contents($theme_dir . '/theme/_layout/template.layout_admin_default.php', '<?php');

		$cms_dir = $this->_fixture_root . '/cms';
		mkdir($cms_dir . '/templates-common/default-SoAdmin/_layout', 0o777, true);
		file_put_contents($cms_dir . '/templates-common/default-SoAdmin/_layout/template.layout_public_default.php', '<?php');

		$portal_dir = $this->_fixture_root . '/portal-admin';
		mkdir($portal_dir . '/theme/_layouts', 0o777, true);
		file_put_contents($portal_dir . '/theme/_layouts/template.layout_admin_login.php', '<?php');

		$available = LayoutRenameRegistry::collectAvailableLayouts([
			'themes:so-admin' => $theme_dir,
			'core:cms' => $cms_dir,
			'themes:portal-admin' => $portal_dir,
		]);

		$this->assertSame(
			['admin_default', 'admin_login', 'public_default'],
			$available
		);
	}

	public function testValidateTargetsReturnsErrorsForUnknownLayouts(): void
	{
		$renames = [
			'admin_nomenu' => ['new_layout' => 'admin_login', 'package' => 'radaptor/themes/so-admin', 'version' => '1.0.0'],
			'old_widget' => ['new_layout' => 'nonexistent_layout', 'package' => 'radaptor/themes/so-admin', 'version' => '1.0.0'],
		];

		$errors = LayoutRenameRegistry::validateTargets($renames, ['admin_login', 'admin_default']);

		$this->assertCount(1, $errors);
		$this->assertStringContainsString("'nonexistent_layout'", $errors[0]);
	}

	public function testValidateTargetsReturnsEmptyOnFullCoverage(): void
	{
		$renames = [
			'admin_nomenu' => ['new_layout' => 'admin_login', 'package' => 'p', 'version' => '1.0.0'],
		];

		$this->assertSame([], LayoutRenameRegistry::validateTargets($renames, ['admin_login']));
	}

	public function testMetadataNormalizerRejectsSelfReferentialRename(): void
	{
		$theme = $this->writeTheme('so-admin', '1.0.0', ['admin_login' => 'admin_login']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/cannot map to itself/');

		LayoutRenameRegistry::buildFromSourcePaths(['themes:so-admin' => $theme]);
	}

	public function testMetadataNormalizerRejectsEmptyValue(): void
	{
		$theme = $this->writeTheme('so-admin', '1.0.0', ['admin_nomenu' => '   ']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/must be a non-empty string/');

		LayoutRenameRegistry::buildFromSourcePaths(['themes:so-admin' => $theme]);
	}

	/**
	 * @param array<string, string> $deprecated_layouts
	 */
	private function writeTheme(string $id, string $version, array $deprecated_layouts): string
	{
		$dir = $this->_fixture_root . '/' . $id;

		if (!is_dir($dir)) {
			mkdir($dir, 0o777, true);
		}

		$metadata = [
			'package' => 'radaptor/themes/' . $id,
			'type' => 'theme',
			'id' => $id,
			'version' => $version,
		];

		if ($deprecated_layouts !== []) {
			$metadata['deprecated_layouts'] = $deprecated_layouts;
		}

		file_put_contents(
			$dir . '/.registry-package.json',
			json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

		return $dir;
	}

	private function removeDirectory(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $entry) {
			if ($entry->isDir()) {
				rmdir($entry->getPathname());
			} else {
				unlink($entry->getPathname());
			}
		}

		rmdir($dir);
	}
}
