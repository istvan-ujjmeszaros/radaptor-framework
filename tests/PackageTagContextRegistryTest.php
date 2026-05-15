<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('DEPLOY_ROOT')) {
	$fixture_root = sys_get_temp_dir() . '/radaptor-package-tag-contexts-' . bin2hex(random_bytes(6));
	define('PACKAGE_TAG_CONTEXT_FIXTURE_ROOT', $fixture_root);
	define('DEPLOY_ROOT', $fixture_root . '/');
} else {
	define('PACKAGE_TAG_CONTEXT_FIXTURE_ROOT', '');
}

require_once __DIR__ . '/../classes/class.PackageTypeHelper.php';
require_once __DIR__ . '/../classes/class.PackageManifest.php';
require_once __DIR__ . '/../classes/class.PackageLocalOverrideHelper.php';
require_once __DIR__ . '/../classes/class.PackageMetadataHelper.php';
require_once __DIR__ . '/../classes/class.PackageLockfile.php';
require_once __DIR__ . '/../classes/class.PackagePathHelper.php';
require_once __DIR__ . '/../classes/class.PackageTagContextRegistry.php';

final class PackageTagContextRegistryTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		$root = self::fixtureRoot();

		if ($root === '') {
			return;
		}

		self::mkdir($root);
	}

	public static function tearDownAfterClass(): void
	{
		$root = self::fixtureRoot();

		if ($root !== '') {
			self::deleteTree($root);
		}
	}

	protected function tearDown(): void
	{
		if (self::fixtureRoot() !== '' && is_file(PackageLockfile::getPath())) {
			unlink(PackageLockfile::getPath());
		}

		parent::tearDown();
	}

	public function testMetadataPrefixesLocalContextWithPackageId(): void
	{
		$contexts = PackageMetadataHelper::normalizeTagContextsMetadata(
			[
				'history' => ['label' => 'History'],
			],
			'fixture',
			'radaptor/modules/blog',
			'blog'
		);

		$this->assertSame('blog_history', $contexts['history']['context']);
		$this->assertSame('History', $contexts['history']['label']);
	}

	public function testMetadataRejectsContextLongerThanStorageColumn(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('exceeds 64 characters');

		PackageMetadataHelper::normalizeTagContextsMetadata(
			['really-long-context-name-that-will-not-fit-in-the-current-tags-column' => []],
			'fixture',
			'radaptor/modules/very-long-package-name',
			'very-long-package-name'
		);
	}

	public function testRegistryRejectsDuplicateResolvedContexts(): void
	{
		$this->skipWhenDeployRootIsAlreadyDefined();

		PackageLockfile::write([
			'lockfile_version' => 1,
			'packages' => [
				'core:blog' => self::lockedPackage('core', 'blog'),
				'theme:blog' => self::lockedPackage('theme', 'blog'),
			],
		]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Duplicate tag context 'blog_history'");

		PackageTagContextRegistry::getAll();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function lockedPackage(string $type, string $id): array
	{
		return [
			'type' => $type,
			'id' => $id,
			'package' => 'radaptor/' . $type . '/' . $id,
			'source' => ['type' => 'dev', 'path' => 'packages/dev/' . $type . '/' . $id],
			'resolved' => ['type' => 'dev', 'path' => 'packages/dev/' . $type . '/' . $id, 'version' => '0.0.0'],
			'tag_contexts' => [
				'history' => [],
			],
		];
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
		return (string) PACKAGE_TAG_CONTEXT_FIXTURE_ROOT;
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
