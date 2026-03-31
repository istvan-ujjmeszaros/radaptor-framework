<?php

class PackageMetadataHelper
{
	/**
	 * @return array<string, string>
	 */
	public static function loadDependenciesFromSourcePath(string $source_path): array
	{
		$metadata_path = rtrim($source_path, '/') . '/.registry-package.json';

		if (!is_file($metadata_path)) {
			return [];
		}

		return self::loadFromSourcePath($source_path)['dependencies'];
	}

	/**
	 * @return array<string, string>
	 */
	public static function loadComposerRequireFromSourcePath(string $source_path): array
	{
		$metadata_path = rtrim($source_path, '/') . '/.registry-package.json';

		if (!is_file($metadata_path)) {
			return [];
		}

		return self::loadFromSourcePath($source_path)['composer']['require'];
	}

	/**
	 * @return array{
	 *     package: string,
	 *     type: string,
	 *     id: string,
	 *     version: string,
	 *     dependencies: array<string, string>,
	 *     composer: array{
	 *         require: array<string, string>
	 *     },
	 *     assets: array{
	 *         public: list<array{source: string, target: string}>
	 *     },
	 *     dist_exclude: list<string>
	 * }
	 */
	public static function loadFromSourcePath(string $source_path): array
	{
		$metadata_path = rtrim($source_path, '/') . '/.registry-package.json';

		if (!is_file($metadata_path)) {
			throw new RuntimeException("Package source is missing .registry-package.json: {$metadata_path}");
		}

		$json = file_get_contents($metadata_path);

		if ($json === false) {
			throw new RuntimeException("Unable to read package metadata: {$metadata_path}");
		}

		try {
			$metadata = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException("Invalid JSON in {$metadata_path}: " . $e->getMessage(), 0, $e);
		}

		if (!is_array($metadata)) {
			throw new RuntimeException("Package metadata must be an object: {$metadata_path}");
		}

		$result = [];

		foreach (['package', 'type', 'id', 'version'] as $required_key) {
			$value = $metadata[$required_key] ?? null;

			if (!is_string($value) || trim($value) === '') {
				throw new RuntimeException("Package metadata is missing '{$required_key}': {$metadata_path}");
			}

			$result[$required_key] = trim($value);
		}

		$result['type'] = PackageTypeHelper::normalizeType($result['type'], 'Package metadata');
		$result['id'] = PackageTypeHelper::normalizeId($result['id'], 'Package metadata');
		$result['version'] = PluginVersionHelper::normalizeVersion($result['version']);
		$result['dependencies'] = PackageDependencyHelper::normalizeDependencies(
			$metadata['dependencies'] ?? [],
			"Package metadata '{$result['package']}'"
		);
		$result['composer'] = self::normalizeComposerMetadata(
			$metadata['composer'] ?? [],
			$metadata_path,
			$result['package']
		);
		$result['assets'] = self::normalizeAssetsMetadata(
			$metadata['assets'] ?? [],
			$metadata_path,
			$result['package']
		);
		$dist_exclude = $metadata['dist_exclude'] ?? [];

		if (!is_array($dist_exclude)) {
			throw new RuntimeException("Package metadata dist_exclude must be an array: {$metadata_path}");
		}

		$result['dist_exclude'] = array_values(array_filter(
			array_map(
				static fn (mixed $value): string => trim((string) $value),
				$dist_exclude
			),
			static fn (string $value): bool => $value !== ''
		));

		return $result;
	}

	/**
	 * @return array{
	 *     require: array<string, string>
	 * }
	 */
	private static function normalizeComposerMetadata(mixed $composer, string $metadata_path, string $package): array
	{
		if ($composer === null || $composer === []) {
			return [
				'require' => [],
			];
		}

		if (!is_array($composer)) {
			throw new RuntimeException("Package metadata composer block must be an object: {$metadata_path}");
		}

		return [
			'require' => PackageDependencyHelper::normalizeDependencies(
				$composer['require'] ?? [],
				"Package metadata '{$package}' composer.require"
			),
		];
	}

	/**
	 * @return array{
	 *     public: list<array{source: string, target: string}>
	 * }
	 */
	private static function normalizeAssetsMetadata(mixed $assets, string $metadata_path, string $package): array
	{
		if ($assets === null || $assets === []) {
			return [
				'public' => [],
			];
		}

		if (!is_array($assets)) {
			throw new RuntimeException("Package metadata assets block must be an object: {$metadata_path}");
		}

		$public_assets = $assets['public'] ?? [];

		if ($public_assets === []) {
			return [
				'public' => [],
			];
		}

		if (!is_array($public_assets)) {
			throw new RuntimeException("Package metadata '{$package}' assets.public must be an array: {$metadata_path}");
		}

		$normalized = [];

		foreach ($public_assets as $index => $asset) {
			if (!is_array($asset)) {
				throw new RuntimeException("Package metadata '{$package}' assets.public[{$index}] must be an object: {$metadata_path}");
			}

			$source = trim((string) ($asset['source'] ?? ''));
			$target = trim((string) ($asset['target'] ?? ''));

			if ($source === '' || $target === '') {
				throw new RuntimeException("Package metadata '{$package}' assets.public[{$index}] must define source and target: {$metadata_path}");
			}

			if (str_starts_with($target, '/') || str_contains($target, '..')) {
				throw new RuntimeException("Package metadata '{$package}' assets.public[{$index}] uses an invalid target path: {$target}");
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
}
