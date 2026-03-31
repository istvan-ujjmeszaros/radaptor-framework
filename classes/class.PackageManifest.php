<?php

class PackageManifest
{
	public static function getPath(): string
	{
		return DEPLOY_ROOT . 'radaptor.json';
	}

	/**
	 * @param array{
	 *     manifest_version?: int,
	 *     registries?: array<string, array<string, mixed>>,
	 *     packages?: array<string, array<string, mixed>>
	 * } $manifest
	 * @return array<string, mixed>
	 */
	public static function exportDocument(array $manifest): array
	{
		$document = [
			'manifest_version' => max(1, (int) ($manifest['manifest_version'] ?? 1)),
		];
		$registries = self::exportRegistries($manifest['registries'] ?? []);

		if ($registries !== []) {
			$document['registries'] = $registries;
		}

		$sections = [
			'core' => [],
			'themes' => [],
			'plugins' => [],
		];

		foreach (($manifest['packages'] ?? []) as $package_key => $package) {
			$package_key_parts = explode(':', (string) $package_key, 2);
			$type = PackageTypeHelper::normalizeType($package['type'] ?? $package_key_parts[0], 'Manifest package');
			$id = PackageTypeHelper::normalizeId($package['id'] ?? ($package_key_parts[1] ?? ''), 'Manifest package');
			$sections[PackageTypeHelper::getSectionForType($type)][$id] = self::exportPackage($package, $type, $id);
		}

		foreach ($sections as &$section_entries) {
			ksort($section_entries);
		}
		unset($section_entries);

		foreach ($sections as $section => $entries) {
			if ($entries !== []) {
				$document[$section] = $entries;
			}
		}

		return $document;
	}

	/**
	 * @param array<string, array<string, mixed>> $registries
	 * @return array<string, array{url: string}>
	 */
	private static function exportRegistries(array $registries): array
	{
		$export = [];

		foreach ($registries as $registry_name => $registry) {
			$registry_name = PackageTypeHelper::normalizeId($registry_name, 'Manifest registry');
			$url = trim((string) ($registry['resolved_url'] ?? $registry['url'] ?? ''));

			if ($url === '') {
				throw new RuntimeException("Manifest registry '{$registry_name}' is missing a URL.");
			}

			$export[$registry_name] = [
				'url' => $url,
			];
		}

		ksort($export);

		return $export;
	}

	/**
	 * @param array<string, mixed> $package
	 * @return array<string, mixed>
	 */
	public static function exportPackage(array $package, string $type, string $id): array
	{
		$type = PackageTypeHelper::normalizeType($type, 'Manifest package');
		$id = PackageTypeHelper::normalizeId($id, 'Manifest package');
		$source = is_array($package['source'] ?? null) ? $package['source'] : [];
		$source_type = self::requireSourceType($source['type'] ?? null, $type, $id);
		$export = [];

		if (!is_string($package['package'] ?? null) || trim((string) $package['package']) === '') {
			throw new RuntimeException("Manifest package '{$type}:{$id}' is missing package.");
		}

		$export['package'] = trim((string) $package['package']);
		$export['source'] = [
			'type' => $source_type,
		];

		if ($source_type === 'dev') {
			$path = $source['path'] ?? null;

			if ($path !== null && (!is_string($path) || trim($path) === '')) {
				throw new RuntimeException("Manifest dev package '{$type}:{$id}' uses an invalid source.path.");
			}

			$path = is_string($path) && trim($path) !== ''
				? trim($path)
				: PackageTypeHelper::getDefaultPath($type, 'dev', $id);

			$export['source']['path'] = $path;
		} else {
			$registry_name = trim((string) ($source['registry'] ?? ''));

			if ($registry_name === '') {
				throw new RuntimeException("Manifest registry package '{$type}:{$id}' is missing source.registry.");
			}

			$export['source']['registry'] = $registry_name;

			if (isset($source['version']) && trim((string) $source['version']) !== '') {
				$export['source']['version'] = trim((string) $source['version']);
			}
		}

		$extra = [];

		foreach ($package as $key => $value) {
			if (in_array($key, ['type', 'id', 'package', 'source'], true)) {
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
	 *     manifest_version?: int,
	 *     registries?: array<string, array<string, mixed>>,
	 *     packages?: array<string, array<string, mixed>>
	 * } $manifest
	 */
	public static function write(array $manifest, ?string $path = null): void
	{
		$path ??= self::getPath();
		$document = self::exportDocument($manifest);
		$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$result = file_put_contents($path, $json . "\n", LOCK_EX);

		if ($result === false) {
			throw new RuntimeException("Unable to write package manifest: {$path}");
		}
	}

	/**
	 * @return array{
	 *     manifest_version: int,
	 *     registries: array<string, array{name: string, url: string, resolved_url: string}>,
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
	 *     manifest_version: int,
	 *     registries: array<string, array{name: string, url: string, resolved_url: string}>,
	 *     packages: array<string, array<string, mixed>>,
	 *     path: string,
	 *     base_dir: string
	 * }
	 */
	public static function loadFromPath(string $path): array
	{
		if (!file_exists($path)) {
			throw new RuntimeException("Package manifest not found: {$path}");
		}

		$base_dir = dirname($path);
		$data = self::decodeJsonFile($path);
		$registries = self::normalizeRegistries($data['registries'] ?? []);
		$packages = [];

		foreach (['core', 'themes', 'plugins'] as $section) {
			foreach (($data[$section] ?? []) as $id => $package) {
				if (!is_array($package)) {
					continue;
				}

				$normalized_package = self::normalizeSectionPackage(
					$section,
					$id,
					$package,
					$registries,
					$base_dir
				);
				$packages[PackageTypeHelper::getKey($normalized_package['type'], $normalized_package['id'])] = $normalized_package;
			}
		}

		ksort($packages);

		return [
			'manifest_version' => max(1, (int) ($data['manifest_version'] ?? 1)),
			'registries' => $registries,
			'packages' => $packages,
			'path' => $path,
			'base_dir' => $base_dir,
		];
	}

	/**
	 * @return array<string, array{name: string, url: string, resolved_url: string}>
	 */
	private static function normalizeRegistries(mixed $registries): array
	{
		if ($registries === null || $registries === []) {
			return [];
		}

		if (!is_array($registries)) {
			throw new RuntimeException('Package manifest registries must be an object.');
		}

		$normalized = [];

		foreach ($registries as $registry_name => $registry) {
			if (!is_array($registry)) {
				throw new RuntimeException("Package manifest registry '{$registry_name}' must be an object.");
			}

			$registry_name = PackageTypeHelper::normalizeId($registry_name, 'Manifest registry');
			$url = trim((string) ($registry['url'] ?? ''));

			if ($url === '') {
				throw new RuntimeException("Package manifest registry '{$registry_name}' is missing url.");
			}

			$normalized[$registry_name] = [
				'name' => $registry_name,
				'url' => $url,
				'resolved_url' => $url,
			];
		}

		ksort($normalized);

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $package
	 * @param array<string, array{name: string, url: string, resolved_url: string}> $registries
	 * @return array<string, mixed>
	 */
	private static function normalizeSectionPackage(
		string $section,
		string $id,
		array $package,
		array $registries,
		string $base_dir
	): array {
		$type = PackageTypeHelper::getTypeForSection($section);
		$id = PackageTypeHelper::normalizeId($id, 'Manifest package');
		$normalized = [
			'type' => $type,
			'id' => $id,
			'package' => trim((string) ($package['package'] ?? '')),
		];

		if ($normalized['package'] === '') {
			throw new RuntimeException("Manifest package '{$type}:{$id}' is missing package.");
		}

		foreach ($package as $key => $value) {
			if (in_array($key, ['package', 'source'], true)) {
				continue;
			}

			$normalized[$key] = $value;
		}

		$source = is_array($package['source'] ?? null) ? $package['source'] : [];
		$source_type = self::requireSourceType($source['type'] ?? null, $type, $id);
		$normalized_source = [
			'type' => $source_type,
		];

		if ($source_type === 'dev') {
			$path = trim((string) ($source['path'] ?? PackageTypeHelper::getDefaultPath($type, 'dev', $id)));

			$normalized_source['path'] = $path;
			$normalized_source['resolved_path'] = self::resolvePath($base_dir, $path);
		} else {
			$registry_name = trim((string) ($source['registry'] ?? ''));

			if ($registry_name === '') {
				throw new RuntimeException("Manifest registry package '{$type}:{$id}' is missing source.registry.");
			}

			if (!isset($registries[$registry_name])) {
				throw new RuntimeException("Manifest registry alias '{$registry_name}' was not declared.");
			}

			$normalized_source['registry'] = $registry_name;
			$normalized_source['resolved_registry_url'] = $registries[$registry_name]['resolved_url'];

			if (isset($source['version']) && trim((string) $source['version']) !== '') {
				$normalized_source['version'] = trim((string) $source['version']);
			}
		}

		$normalized['source'] = $normalized_source;

		return $normalized;
	}

	private static function requireSourceType(mixed $source_type, string $type, string $id): string
	{
		$source_type = trim((string) $source_type);

		if (in_array($source_type, ['dev', 'registry'], true)) {
			return $source_type;
		}

		throw new RuntimeException("Manifest package '{$type}:{$id}' uses unsupported source type '{$source_type}'.");
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decodeJsonFile(string $path): array
	{
		$json = file_get_contents($path);

		if ($json === false) {
			throw new RuntimeException("Unable to read package manifest: {$path}");
		}

		try {
			$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException("Invalid JSON in package manifest {$path}: " . $e->getMessage(), 0, $e);
		}

		if (!is_array($data)) {
			throw new RuntimeException("Package manifest must decode to an object: {$path}");
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
