<?php

class PackageLockfile
{
	public static function getPath(): string
	{
		return DEPLOY_ROOT . 'radaptor.lock.json';
	}

	/**
	 * @param array{
	 *     lockfile_version?: int,
	 *     packages?: array<string, array<string, mixed>>
	 * } $lockfile
	 * @return array<string, mixed>
	 */
	public static function exportDocument(array $lockfile): array
	{
		$document = [
			'lockfile_version' => max(1, (int) ($lockfile['lockfile_version'] ?? 1)),
		];
		$sections = [
			'core' => [],
			'themes' => [],
			'plugins' => [],
		];

		foreach (($lockfile['packages'] ?? []) as $package_key => $package) {
			$package_key_parts = explode(':', (string) $package_key, 2);
			$type = PackageTypeHelper::normalizeType($package['type'] ?? $package_key_parts[0], 'Locked package');
			$id = PackageTypeHelper::normalizeId($package['id'] ?? ($package_key_parts[1] ?? ''), 'Locked package');
			$sections[PackageTypeHelper::getSectionForType($type)][$id] = self::exportPackage($package, $type, $id);
		}

		foreach ($sections as &$entries) {
			ksort($entries);
		}
		unset($entries);

		foreach ($sections as $section => $entries) {
			if ($entries !== []) {
				$document[$section] = $entries;
			}
		}

		return $document;
	}

	/**
	 * @param array<string, mixed> $package
	 * @return array<string, mixed>
	 */
	public static function exportPackage(array $package, string $type, string $id): array
	{
		$type = PackageTypeHelper::normalizeType($type, 'Locked package');
		$id = PackageTypeHelper::normalizeId($id, 'Locked package');
		$export = [
			'type' => $type,
			'id' => $id,
		];
		$known_keys = [
			'type',
			'id',
			'package',
			'source',
			'resolved',
			'composer',
			'assets',
			'dependencies',
			'auto_installed',
			'required_by',
		];

		if (array_key_exists('package', $package)) {
			$export['package'] = $package['package'];
		}

		if (isset($package['source']) && is_array($package['source'])) {
			$export['source'] = self::stripTransientSourceFields($package['source']);
		}

		if (isset($package['resolved']) && is_array($package['resolved'])) {
			$export['resolved'] = self::stripTransientSourceFields($package['resolved']);
		}

		if (array_key_exists('dependencies', $package)) {
			$export['dependencies'] = PackageDependencyHelper::normalizeDependencies(
				$package['dependencies'],
				"Locked package '{$type}:{$id}'"
			);
		}

		if (isset($package['composer']) && is_array($package['composer'])) {
			$composer = self::normalizeComposer($package['composer'], $type, $id);

			if ($composer['require'] !== []) {
				$export['composer'] = $composer;
			}
		}

		if (isset($package['assets']) && is_array($package['assets'])) {
			$assets = self::normalizeAssets($package['assets'], $type, $id);

			if ($assets['public'] !== []) {
				$export['assets'] = $assets;
			}
		}

		if (($package['auto_installed'] ?? false) === true) {
			$export['auto_installed'] = true;
		}

		if (isset($package['required_by']) && is_array($package['required_by']) && $package['required_by'] !== []) {
			$required_by = array_values(array_unique(array_map(
				static fn (mixed $value): string => trim((string) $value),
				$package['required_by']
			)));
			sort($required_by);
			$export['required_by'] = array_values(array_filter($required_by, static fn (string $value): bool => $value !== ''));
		}

		$extra = [];

		foreach ($package as $key => $value) {
			if ($key === 'resolved_path' || in_array($key, $known_keys, true)) {
				continue;
			}

			$extra[$key] = $value;
		}

		ksort($extra);

		foreach ($extra as $key => $value) {
			$export[$key] = $value;
		}

		return $export;
	}

	/**
	 * @param array{
	 *     lockfile_version?: int,
	 *     packages?: array<string, array<string, mixed>>
	 * } $lockfile
	 */
	public static function write(array $lockfile, ?string $path = null): void
	{
		$path ??= self::getPath();
		$document = self::exportDocument($lockfile);
		$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$result = file_put_contents($path, $json . "\n", LOCK_EX);

		if ($result === false) {
			throw new RuntimeException("Unable to write package lockfile: {$path}");
		}
	}

	/**
	 * @return array{
	 *     lockfile_version: int,
	 *     packages: array<string, array<string, mixed>>,
	 *     path: string,
	 *     base_dir: string
	 * }
	 */
	public static function load(): array
	{
		return self::loadFromPath(self::getPath());
	}

	/**
	 * @return array{
	 *     lockfile_version: int,
	 *     packages: array<string, array<string, mixed>>,
	 *     path: string,
	 *     base_dir: string
	 * }
	 */
	public static function loadFromPath(string $path): array
	{
		if (!file_exists($path)) {
			throw new RuntimeException("Package lockfile not found: {$path}");
		}

		$base_dir = dirname($path);
		$data = self::decodeJsonFile($path);
		$packages = [];

		foreach (['core', 'themes', 'plugins'] as $section) {
			foreach (($data[$section] ?? []) as $id => $package) {
				if (!is_array($package)) {
					continue;
				}

				$normalized = self::normalizeSectionPackage($section, $id, $package, $base_dir);
				$packages[PackageTypeHelper::getKey($normalized['type'], $normalized['id'])] = $normalized;
			}
		}

		ksort($packages);

		return [
			'lockfile_version' => max(1, (int) ($data['lockfile_version'] ?? 1)),
			'packages' => $packages,
			'path' => $path,
			'base_dir' => $base_dir,
		];
	}

	/**
	 * @param array<string, mixed> $package
	 * @return array<string, mixed>
	 */
	private static function normalizeSectionPackage(string $section, string $id, array $package, string $base_dir): array
	{
		$type = PackageTypeHelper::getTypeForSection($section);
		$id = PackageTypeHelper::normalizeId($package['id'] ?? $id, 'Locked package');
		$normalized = [
			'type' => $type,
			'id' => $id,
			'package' => trim((string) ($package['package'] ?? '')),
		];

		if ($normalized['package'] === '') {
			throw new RuntimeException("Locked package '{$type}:{$id}' is missing package.");
		}

		foreach ($package as $key => $value) {
			if (in_array($key, ['type', 'id', 'package', 'source', 'resolved', 'composer', 'assets', 'dependencies'], true)) {
				continue;
			}

			$normalized[$key] = $value;
		}

		if (isset($package['source']) && is_array($package['source'])) {
			$normalized['source'] = self::normalizeSource($package['source'], $base_dir, $type, $id);
		}

		if (isset($package['resolved']) && is_array($package['resolved'])) {
			$normalized['resolved'] = self::normalizeSource($package['resolved'], $base_dir, $type, $id);
		}

		if (array_key_exists('dependencies', $package)) {
			$normalized['dependencies'] = PackageDependencyHelper::normalizeDependencies(
				$package['dependencies'],
				"Locked package '{$type}:{$id}'"
			);
		}

		if (isset($package['composer']) && is_array($package['composer'])) {
			$normalized['composer'] = self::normalizeComposer($package['composer'], $type, $id);
		}

		if (isset($package['assets']) && is_array($package['assets'])) {
			$normalized['assets'] = self::normalizeAssets($package['assets'], $type, $id);
		}

		return $normalized;
	}

	/**
	 * @return array{
	 *     require: array<string, string>
	 * }
	 */
	private static function normalizeComposer(array $composer, string $type, string $id): array
	{
		return [
			'require' => PackageDependencyHelper::normalizeDependencies(
				$composer['require'] ?? [],
				"Locked package '{$type}:{$id}' composer.require"
			),
		];
	}

	/**
	 * @return array{
	 *     public: list<array{source: string, target: string}>
	 * }
	 */
	private static function normalizeAssets(array $assets, string $type, string $id): array
	{
		$public_assets = $assets['public'] ?? [];

		if ($public_assets === []) {
			return [
				'public' => [],
			];
		}

		if (!is_array($public_assets)) {
			throw new RuntimeException("Locked package '{$type}:{$id}' assets.public must be an array.");
		}

		$normalized = [];

		foreach ($public_assets as $index => $asset) {
			if (!is_array($asset)) {
				throw new RuntimeException("Locked package '{$type}:{$id}' assets.public[{$index}] must be an object.");
			}

			$source = trim((string) ($asset['source'] ?? ''));
			$target = trim((string) ($asset['target'] ?? ''));

			if ($source === '' || $target === '') {
				throw new RuntimeException("Locked package '{$type}:{$id}' assets.public[{$index}] must define source and target.");
			}

			$normalized[] = [
				'source' => ltrim(str_replace('\\', '/', $source), '/'),
				'target' => ltrim(str_replace('\\', '/', $target), '/'),
			];
		}

		usort($normalized, static fn (array $left, array $right): int => [$left['target'], $left['source']] <=> [$right['target'], $right['source']]);

		return [
			'public' => $normalized,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalizeSource(array $source, string $base_dir, string $type, string $id): array
	{
		$normalized = $source;
		$normalized['type'] = self::requireSourceType($source['type'] ?? null, $type, $id);
		$normalized_type = $normalized['type'];

		if (
			$normalized_type === 'dev'
			&& (!isset($normalized['path']) || !is_string($normalized['path']) || trim($normalized['path']) === '')
		) {
			$normalized['path'] = PackageTypeHelper::getDefaultPath($type, 'dev', $id);
		}

		if (isset($source['path']) && is_string($source['path'])) {
			$normalized['resolved_path'] = self::resolvePath($base_dir, $source['path']);
		} elseif (isset($normalized['path']) && is_string($normalized['path'])) {
			$normalized['resolved_path'] = self::resolvePath($base_dir, $normalized['path']);
		}

		return $normalized;
	}

	private static function requireSourceType(mixed $source_type, string $type, string $id): string
	{
		$source_type = trim((string) $source_type);

		if (in_array($source_type, ['dev', 'registry'], true)) {
			return $source_type;
		}

		throw new RuntimeException("Locked package '{$type}:{$id}' uses unsupported source type '{$source_type}'.");
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function stripTransientSourceFields(array $source): array
	{
		$export = $source;
		unset($export['resolved_path']);

		ksort($export);

		return $export;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decodeJsonFile(string $path): array
	{
		$json = file_get_contents($path);

		if ($json === false) {
			throw new RuntimeException("Unable to read package lockfile: {$path}");
		}

		try {
			$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException("Invalid JSON in package lockfile {$path}: " . $e->getMessage(), 0, $e);
		}

		if (!is_array($data)) {
			throw new RuntimeException("Package lockfile must decode to an object: {$path}");
		}

		return $data;
	}

	private static function resolvePath(string $base_dir, string $path): string
	{
		if (str_starts_with($path, '/')) {
			return self::normalizePath($path);
		}

		return self::normalizePath(rtrim($base_dir, '/') . '/' . ltrim($path, '/'));
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
