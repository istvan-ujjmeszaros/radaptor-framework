<?php

class PackageAssetsBuilder
{
	public static function getStatePath(): string
	{
		return DEPLOY_ROOT . 'generated/__package_assets__.json';
	}

	/**
	 * @return array{
	 *     dry_run: bool,
	 *     links_created: int,
	 *     links_removed: int,
	 *     links_unchanged: int,
	 *     links: list<array{target: string, source: string, action: string}>
	 * }
	 */
	public static function build(bool $dry_run = false): array
	{
		return self::buildPaths(PackageLockfile::getPath(), self::getStatePath(), $dry_run, DEPLOY_ROOT);
	}

	/**
	 * @return array{
	 *     dry_run: bool,
	 *     links_created: int,
	 *     links_removed: int,
	 *     links_unchanged: int,
	 *     links: list<array{target: string, source: string, action: string}>
	 * }
	 */
	public static function buildPaths(
		string $lock_path,
		string $state_path,
		bool $dry_run = false,
		?string $app_base_dir = null
	): array {
		$lock_base_dir = dirname($lock_path);
		$app_base_dir = $app_base_dir !== null ? self::normalizePathPreservingLinks($app_base_dir) : $lock_base_dir;
		$lock = PackageLockfile::loadFromPath($lock_path);
		$desired_links = self::collectDesiredLinks($lock['packages'], $app_base_dir);
		$current_state = self::loadState($state_path);
		$links = [];
		$created = 0;
		$removed = 0;
		$unchanged = 0;

		foreach ($current_state as $target => $source) {
			if (isset($desired_links[$target]) && $desired_links[$target] === $source) {
				continue;
			}

			$links[] = [
				'target' => $target,
				'source' => $source,
				'action' => 'removed',
			];
			$removed++;

			if (!$dry_run) {
				self::removeManagedLink($target);
			}
		}

		foreach ($desired_links as $target => $source) {
			$normalized_target = self::normalizePathPreservingLinks($target);
			$normalized_source = self::normalizePath($source);
			$current_source = self::readLinkTarget($normalized_target);

			if ($current_source === $normalized_source) {
				$links[] = [
					'target' => $normalized_target,
					'source' => $normalized_source,
					'action' => 'unchanged',
				];
				$unchanged++;

				continue;
			}

			if ($current_source !== null && $current_source !== $normalized_source) {
				throw new RuntimeException("Asset target '{$normalized_target}' is already managed by a different source.");
			}

			if (file_exists($normalized_target) && !is_link($normalized_target)) {
				if (!self::canReplaceExistingTarget($normalized_target, $normalized_source)) {
					throw new RuntimeException("Asset target '{$normalized_target}' already exists and is not a symlink.");
				}
			}

			$links[] = [
				'target' => $normalized_target,
				'source' => $normalized_source,
				'action' => 'linked',
			];
			$created++;

			if (!$dry_run) {
				self::ensureDirectory(dirname($normalized_target));
				self::removeExistingTarget($normalized_target);
				self::replaceLink($normalized_source, $normalized_target);
			}
		}

		if (!$dry_run) {
			self::writeState($state_path, $desired_links);
		}

		return [
			'dry_run' => $dry_run,
			'links_created' => $created,
			'links_removed' => $removed,
			'links_unchanged' => $unchanged,
			'links' => $links,
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $packages
	 * @return array<string, string>
	 */
	private static function collectDesiredLinks(array $packages, string $app_base_dir): array
	{
		$links = [];

		foreach ($packages as $package_key => $package) {
			$type = $package['type'] ?? null;

			// Phase 1 only manages public asset mounts for core/theme packages.
			// Plugin assets still flow through the legacy plugin runtime for now.
			if (!in_array($type, ['core', 'theme'], true)) {
				continue;
			}

			$assets = is_array($package['assets'] ?? null) ? $package['assets'] : ['public' => []];
			$public_assets = $assets['public'] ?? [];

			if (!is_array($public_assets) || $public_assets === []) {
				continue;
			}

			$resolved = is_array($package['resolved'] ?? null) ? $package['resolved'] : [];
			$source = is_array($package['source'] ?? null) ? $package['source'] : [];
			$package_root = $resolved['resolved_path'] ?? $source['resolved_path'] ?? null;

			if ((!is_string($package_root) || !is_dir($package_root)) && is_string($resolved['path'] ?? null)) {
				$package_root = PackagePathHelper::resolveStoragePath((string) $resolved['path']);
			}

			if ((!is_string($package_root) || !is_dir($package_root)) && is_string($source['path'] ?? null)) {
				$package_root = PackagePathHelper::resolveStoragePath((string) $source['path']);
			}

			if (!is_string($package_root) || !is_dir($package_root)) {
				throw new RuntimeException("Asset package '{$package_key}' is missing an installed filesystem path.");
			}

			foreach ($public_assets as $asset) {
				$source_relative = ltrim((string) ($asset['source'] ?? ''), '/');
				$target_relative = ltrim((string) ($asset['target'] ?? ''), '/');

				if ($source_relative === '' || $target_relative === '') {
					continue;
				}

				$source_path = self::normalizePath(rtrim($package_root, '/') . '/' . $source_relative);

				if (!file_exists($source_path)) {
					throw new RuntimeException("Asset source '{$source_relative}' does not exist for package '{$package_key}'.");
				}

				$target_path = self::normalizePathPreservingLinks(rtrim($app_base_dir, '/') . '/public/www/' . $target_relative);
				$links[$target_path] = $source_path;
			}
		}

		ksort($links);

		return $links;
	}

	/**
	 * @return array<string, string>
	 */
	private static function loadState(string $state_path): array
	{
		if (!is_file($state_path)) {
			return [];
		}

		$json = file_get_contents($state_path);

		if ($json === false || trim($json) === '') {
			return [];
		}

		try {
			$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return [];
		}

		if (!is_array($data) || !is_array($data['links'] ?? null)) {
			return [];
		}

		$links = [];

		foreach ($data['links'] as $target => $source) {
			if (!is_string($target) || !is_string($source) || trim($target) === '' || trim($source) === '') {
				continue;
			}

			$links[self::normalizePathPreservingLinks($target)] = self::normalizePath($source);
		}

		ksort($links);

		return $links;
	}

	/**
	 * @param array<string, string> $links
	 */
	private static function writeState(string $state_path, array $links): void
	{
		self::ensureDirectory(dirname($state_path));
		$json = json_encode([
			'links' => $links,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		if (file_put_contents($state_path, $json . "\n", LOCK_EX) === false) {
			throw new RuntimeException("Unable to write package assets state: {$state_path}");
		}
	}

	private static function readLinkTarget(string $path): ?string
	{
		if (!is_link($path)) {
			return null;
		}

		$target = readlink($path);

		if ($target === false) {
			throw new RuntimeException("Unable to read asset symlink target: {$path}");
		}

		if (str_starts_with($target, '/')) {
			return self::normalizePath($target);
		}

		return self::normalizePath(dirname($path) . '/' . $target);
	}

	private static function replaceLink(string $source, string $target): void
	{
		if (is_link($target) || file_exists($target)) {
			if (!unlink($target)) {
				throw new RuntimeException("Unable to replace asset symlink target: {$target}");
			}
		}

		if (!symlink($source, $target)) {
			throw new RuntimeException("Unable to create asset symlink '{$target}' -> '{$source}'.");
		}
	}

	private static function canReplaceExistingTarget(string $target, string $source): bool
	{
		if (!file_exists($target) || is_link($target)) {
			return false;
		}

		if (is_dir($target)) {
			return is_dir($source) && self::directoriesMatch($target, $source);
		}

		return is_file($target)
			&& is_file($source)
			&& sha1_file($target) === sha1_file($source);
	}

	private static function removeExistingTarget(string $target): void
	{
		if (is_link($target) || !file_exists($target)) {
			return;
		}

		if (is_file($target)) {
			if (!unlink($target)) {
				throw new RuntimeException("Unable to remove existing asset file: {$target}");
			}

			return;
		}

		if (!is_dir($target)) {
			return;
		}

		$items = scandir($target);

		if ($items === false) {
			throw new RuntimeException("Unable to scan existing asset directory: {$target}");
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			self::removeExistingTarget($target . '/' . $item);
		}

		if (!rmdir($target)) {
			throw new RuntimeException("Unable to remove existing asset directory: {$target}");
		}
	}

	private static function directoriesMatch(string $left, string $right): bool
	{
		$left_entries = self::collectDirectoryEntries($left);
		$right_entries = self::collectDirectoryEntries($right);

		if (array_keys($left_entries) !== array_keys($right_entries)) {
			return false;
		}

		foreach ($left_entries as $relative_path => $left_entry) {
			$right_entry = $right_entries[$relative_path];

			if ($left_entry['type'] !== $right_entry['type']) {
				return false;
			}

			if ($left_entry['type'] === 'file' && $left_entry['hash'] !== $right_entry['hash']) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array<string, array{type: string, hash: string|null}>
	 */
	private static function collectDirectoryEntries(string $root): array
	{
		$entries = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);
		$root = rtrim(self::normalizePath($root), '/');

		foreach ($iterator as $item) {
			$path = $item->getPathname();
			$normalized_path = self::normalizePath($path);
			$relative_path = ltrim(substr($normalized_path, strlen($root)), '/');

			if ($relative_path === '') {
				continue;
			}

			if ($item->isDir()) {
				$entries[$relative_path] = [
					'type' => 'dir',
					'hash' => null,
				];

				continue;
			}

			$hash = sha1_file($path);

			if ($hash === false) {
				throw new RuntimeException("Unable to hash asset file: {$path}");
			}

			$entries[$relative_path] = [
				'type' => 'file',
				'hash' => $hash,
			];
		}

		ksort($entries);

		return $entries;
	}

	private static function removeManagedLink(string $path): void
	{
		if (is_link($path) || is_file($path)) {
			if (!unlink($path)) {
				throw new RuntimeException("Unable to remove asset target: {$path}");
			}

			return;
		}

		if (is_dir($path)) {
			throw new RuntimeException("Managed asset target '{$path}' is a directory; refusing to remove.");
		}
	}

	private static function ensureDirectory(string $directory): void
	{
		if (is_dir($directory)) {
			return;
		}

		if (!mkdir($directory, 0o755, true) && !is_dir($directory)) {
			throw new RuntimeException("Unable to create directory: {$directory}");
		}
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

	private static function normalizePathPreservingLinks(string $path): string
	{
		return rtrim(str_replace('\\', '/', $path), '/');
	}
}
