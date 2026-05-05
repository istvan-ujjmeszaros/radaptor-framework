<?php

declare(strict_types=1);

class I18nSeedTargetDiscovery
{
	/** @var list<string> */
	private const EXCLUDED_DIRECTORIES = [
		'.git',
		'cache',
		'dist',
		'generated',
		'node_modules',
		'tmp',
		'vendor',
	];

	/**
	 * @param array{include_static?: bool, include_discovered?: bool, all_packages?: bool} $options
	 * @return list<array<string, mixed>>
	 */
	public static function discoverTargets(array $options = []): array
	{
		$include_static = (bool) ($options['include_static'] ?? true);
		$include_discovered = (bool) ($options['include_discovered'] ?? true);
		$all_packages = (bool) ($options['all_packages'] ?? false);
		$targets = [];
		$seen = [];

		if ($include_static) {
			foreach (I18nShippedSeedRegistry::getStaticTargets() as $target) {
				self::addTarget($targets, $seen, [
					'group_type' => $target['group_type'],
					'group_id' => $target['group_id'],
					'input_dir' => $target['seed_dir'],
					'domains' => $target['domains'] ?? [],
					'key_prefixes' => $target['key_prefixes'] ?? [],
					'source' => 'static',
					'relative_path' => self::relativePath($target['seed_dir']),
				]);
			}
		}

		if (!$include_discovered) {
			return $targets;
		}

		foreach (self::discoverRoots($all_packages) as $root) {
			foreach (self::listSeedDirectories($root['path']) as $seed_dir) {
				$relative_to_root = self::relativePath($seed_dir, $root['path']);
				$component = self::componentIdFromSeedPath($relative_to_root);
				$group_id = $component === ''
					? $root['id']
					: $root['id'] . ':' . str_replace('/', '.', $component);

				self::addTarget($targets, $seen, [
					'group_type' => $root['type'],
					'group_id' => $group_id,
					'input_dir' => $seed_dir,
					'source' => $root['source'],
					'root' => $root['path'],
					'relative_path' => $relative_to_root,
				]);
			}
		}

		usort($targets, static function (array $left, array $right): int {
			return [
				(string) ($left['group_type'] ?? ''),
				(string) ($left['group_id'] ?? ''),
				(string) ($left['input_dir'] ?? ''),
			] <=> [
				(string) ($right['group_type'] ?? ''),
				(string) ($right['group_id'] ?? ''),
				(string) ($right['input_dir'] ?? ''),
			];
		});

		return $targets;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function describeTargets(array $options = []): array
	{
		$targets = [];

		foreach (self::discoverTargets($options) as $target) {
			$input_dir = (string) $target['input_dir'];
			$files = is_dir($input_dir) ? self::listLocaleFiles($input_dir) : [];
			$rows = 0;

			foreach ($files as $file) {
				$rows += self::countCsvRows($file);
			}

			$targets[] = [
				...$target,
				'status' => is_dir($input_dir) ? ($files === [] ? 'empty' : 'ok') : 'missing',
				'locales' => array_values(array_map(
					static fn (string $file): string => basename($file, '.csv'),
					$files
				)),
				'files' => count($files),
				'rows' => $rows,
			];
		}

		return $targets;
	}

	/**
	 * @return list<string>
	 */
	public static function listSeedDirectories(string $root): array
	{
		$root = self::normalizePath($root);

		if (!is_dir($root)) {
			return [];
		}

		$directory_iterator = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
		$filter = new RecursiveCallbackFilterIterator(
			$directory_iterator,
			static function (SplFileInfo $current): bool {
				if (!$current->isDir()) {
					return false;
				}

				return !in_array($current->getBasename(), self::EXCLUDED_DIRECTORIES, true);
			}
		);
		$iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
		$seed_dirs = [];

		foreach ($iterator as $file_info) {
			if (
				$file_info->isDir()
				&& $file_info->getBasename() === 'seeds'
				&& basename(str_replace('\\', '/', $file_info->getPath())) === 'i18n'
			) {
				$seed_dirs[] = self::normalizePath($file_info->getPathname());
			}
		}

		sort($seed_dirs);

		return array_values(array_unique($seed_dirs));
	}

	/**
	 * @return list<array{path: string, type: string, id: string, source: string}>
	 */
	public static function discoverRoots(bool $all_packages = false): array
	{
		$roots = [];
		$seen = [];

		self::addRoot($roots, $seen, DEPLOY_ROOT . 'app', 'app', 'app', 'app');

		foreach (PackagePathHelper::getActivePackageRoots(['core', 'theme', 'plugin']) as $root) {
			$description = self::describePackageRoot($root);
			self::addRoot($roots, $seen, $root, $description['type'], $description['id'], $description['source']);
		}

		foreach (self::discoverLockedPluginRoots(PluginLockfile::getPath()) as $root) {
			$description = self::describePackageRoot($root);
			self::addRoot($roots, $seen, $root, 'plugin', $description['id'], 'plugin-lock');
		}

		if ($all_packages) {
			foreach (self::discoverAllPackageRoots() as $root) {
				$description = self::describePackageRoot($root);
				self::addRoot($roots, $seen, $root, $description['type'], $description['id'], $description['source']);
			}
		}

		usort($roots, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);

		return $roots;
	}

	/**
	 * @return list<string>
	 */
	public static function discoverLockedPluginRoots(string $lock_path): array
	{
		if (!is_file($lock_path)) {
			return [];
		}

		$lock = PluginLockfile::loadFromPath($lock_path);
		$base_dir = dirname($lock_path);
		$roots = [];

		foreach ($lock['plugins'] as $plugin) {
			$plugin_path = self::resolveLockedPluginPath($plugin, $base_dir);

			if ($plugin_path !== null && is_dir($plugin_path)) {
				$roots[] = self::normalizePath($plugin_path);
			}
		}

		sort($roots);

		return array_values(array_unique($roots));
	}

	/**
	 * @param list<array<string, mixed>> $targets
	 * @param array<string, true> $seen
	 * @param array<string, mixed> $target
	 */
	private static function addTarget(array &$targets, array &$seen, array $target): void
	{
		$input_dir = self::normalizePath((string) $target['input_dir']);

		if (isset($seen[$input_dir])) {
			return;
		}

		$seen[$input_dir] = true;
		$target['input_dir'] = $input_dir;
		$targets[] = $target;
	}

	/**
	 * @param list<array{path: string, type: string, id: string, source: string}> $roots
	 * @param array<string, true> $seen
	 */
	private static function addRoot(array &$roots, array &$seen, string $path, string $type, string $id, string $source): void
	{
		$path = self::normalizePath($path);

		if (!is_dir($path) || isset($seen[$path])) {
			return;
		}

		$seen[$path] = true;
		$roots[] = [
			'path' => $path,
			'type' => $type,
			'id' => $id,
			'source' => $source,
		];
	}

	/**
	 * @return list<string>
	 */
	private static function listImmediateDirectories(string $collection_dir): array
	{
		$collection_dir = self::normalizePath($collection_dir);

		if (!is_dir($collection_dir)) {
			return [];
		}

		$dirs = glob($collection_dir . '/*', GLOB_ONLYDIR) ?: [];
		$dirs = array_map([self::class, 'normalizePath'], $dirs);
		sort($dirs);

		return array_values($dirs);
	}

	/**
	 * @return list<string>
	 */
	private static function discoverAllPackageRoots(): array
	{
		$roots = [];
		$workspace_root = WorkspaceConsumerDiscovery::resolveWorkspaceRoot();

		foreach ([
			DEPLOY_ROOT . 'packages/registry/core',
			DEPLOY_ROOT . 'packages/registry/themes',
			DEPLOY_ROOT . 'packages/registry/plugins',
			DEPLOY_ROOT . 'plugins/dev',
			DEPLOY_ROOT . 'plugins/registry',
		] as $collection_dir) {
			$roots = [...$roots, ...self::listImmediateDirectories($collection_dir)];
		}

		if (is_string($workspace_root) && $workspace_root !== '') {
			foreach ([
				$workspace_root . '/packages-dev/core',
				$workspace_root . '/packages-dev/themes',
				$workspace_root . '/packages-dev/plugins',
			] as $collection_dir) {
				$roots = [...$roots, ...self::listImmediateDirectories($collection_dir)];
			}
		}

		$roots = array_values(array_unique(array_map([self::class, 'normalizePath'], $roots)));
		sort($roots);

		return $roots;
	}

	/**
	 * @return array{type: string, id: string, source: string}
	 */
	private static function describePackageRoot(string $root): array
	{
		$root = self::normalizePath($root);
		$manifest_path = $root . '/.registry-package.json';
		$id = basename($root);
		$type = 'package';
		$source = 'discovered';

		if (is_file($manifest_path)) {
			$manifest = json_decode((string) file_get_contents($manifest_path), true);

			if (is_array($manifest)) {
				$type = trim((string) ($manifest['type'] ?? $type));
				$id = trim((string) ($manifest['id'] ?? $id));
				$source = 'manifest';
			}
		}

		$storage_path = PackagePathHelper::toStoragePath($root);

		if (str_starts_with($storage_path, 'plugins/dev/') || str_starts_with($storage_path, 'plugins/registry/')) {
			$type = 'plugin';
			$id = basename($root);
			$source = str_contains($storage_path, '/dev/') ? 'plugin-dev' : 'plugin-registry';
		} elseif (str_contains($storage_path, 'packages/dev/core/')) {
			$type = 'core';
			$source = 'package-dev';
		} elseif (str_contains($storage_path, 'packages/dev/themes/')) {
			$type = 'theme';
			$source = 'package-dev';
		} elseif (str_contains($storage_path, 'packages/registry/core/')) {
			$type = 'core';
			$source = 'package-registry';
		} elseif (str_contains($storage_path, 'packages/registry/themes/')) {
			$type = 'theme';
			$source = 'package-registry';
		}

		if ($type === '') {
			$type = 'package';
		}

		if ($id === '') {
			$id = basename($root);
		}

		return [
			'type' => $type,
			'id' => $id,
			'source' => $source,
		];
	}

	/**
	 * @param array<string, mixed> $plugin
	 */
	private static function resolveLockedPluginPath(array $plugin, string $base_dir): ?string
	{
		$resolved = $plugin['resolved'] ?? null;

		if (!is_array($resolved)) {
			return null;
		}

		$path = $resolved['path'] ?? null;

		if (!is_string($path) || trim($path) === '') {
			return null;
		}

		if (str_starts_with($path, '/')) {
			return rtrim($path, '/');
		}

		return rtrim($base_dir . '/' . ltrim($path, '/'), '/');
	}

	private static function componentIdFromSeedPath(string $relative_to_root): string
	{
		$relative_to_root = trim($relative_to_root, '/');

		if ($relative_to_root === 'i18n/seeds') {
			return '';
		}

		return preg_replace('#/i18n/seeds$#', '', $relative_to_root) ?? $relative_to_root;
	}

	/**
	 * @return list<string>
	 */
	private static function listLocaleFiles(string $seed_dir): array
	{
		$files = glob(rtrim($seed_dir, '/') . '/*.csv') ?: [];
		sort($files);

		return array_values(array_map([self::class, 'normalizePath'], $files));
	}

	private static function countCsvRows(string $path): int
	{
		$handle = fopen($path, 'r');

		if ($handle === false) {
			return 0;
		}

		fgetcsv($handle, 0, ',', '"', '');
		$count = 0;

		while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
			if ($row === [null] || $row === false) {
				continue;
			}

			$count++;
		}

		fclose($handle);

		return $count;
	}

	private static function relativePath(string $path, ?string $root = null): string
	{
		$path = self::normalizePath($path);
		$root = $root !== null ? self::normalizePath($root) : self::normalizePath(DEPLOY_ROOT);

		if ($path === $root) {
			return '';
		}

		if (str_starts_with($path, $root . '/')) {
			return ltrim(substr($path, strlen($root)), '/');
		}

		return PackagePathHelper::toStoragePath($path);
	}

	private static function normalizePath(string $path): string
	{
		$path = str_replace('\\', '/', $path);
		$real = realpath($path);

		if ($real !== false) {
			return rtrim(str_replace('\\', '/', $real), '/');
		}

		return rtrim($path, '/');
	}
}
