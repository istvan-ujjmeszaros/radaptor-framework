<?php

class PackageMetadataHelper
{
	public static function updateVersionAtSourcePath(string $source_path, string $version): void
	{
		$metadata_path = rtrim($source_path, '/') . '/.registry-package.json';
		$metadata = self::loadRawDocumentFromPath($metadata_path);
		$metadata['version'] = PackageVersionHelper::normalizeVersion($version);
		$json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$result = file_put_contents($metadata_path, $json . "\n", LOCK_EX);

		if ($result === false) {
			throw new RuntimeException("Unable to write package metadata: {$metadata_path}");
		}
	}

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
	 *     dist_exclude: list<string>,
	 *     deprecated_layouts: array<string, string>,
	 *     tag_contexts: array<string, array{
	 *         context: string,
	 *         label: string|null
	 *     }>
	 * }
	 */
	public static function loadFromSourcePath(string $source_path): array
	{
		$metadata_path = rtrim($source_path, '/') . '/.registry-package.json';
		$metadata = self::loadRawDocumentFromPath($metadata_path);

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
		$result['version'] = PackageVersionHelper::normalizeVersion($result['version']);
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

		$result['deprecated_layouts'] = self::normalizeDeprecatedLayoutsMetadata(
			$metadata['deprecated_layouts'] ?? [],
			$metadata_path,
			$result['package']
		);
		$result['tag_contexts'] = self::normalizeTagContextsMetadata(
			$metadata['tag_contexts'] ?? [],
			$metadata_path,
			$result['package'],
			$result['id']
		);

		return $result;
	}

	/**
	 * @return array<string, array{
	 *     context: string,
	 *     label: string|null
	 * }>
	 */
	public static function normalizeTagContextsMetadata(mixed $tag_contexts, string $metadata_path, string $package, string $package_id): array
	{
		if ($tag_contexts === null || $tag_contexts === []) {
			return [];
		}

		if (!is_array($tag_contexts)) {
			throw new RuntimeException("Package metadata '{$package}' tag_contexts must be an object or array: {$metadata_path}");
		}

		$package_id = PackageTypeHelper::normalizeId($package_id, "Package metadata '{$package}' tag_contexts owner");
		$normalized = [];

		foreach ($tag_contexts as $key => $value) {
			if (is_int($key)) {
				if (!is_string($value) || trim($value) === '') {
					throw new RuntimeException("Package metadata '{$package}' tag_contexts[{$key}] must be a non-empty string: {$metadata_path}");
				}

				$local_context = trim($value);
				$metadata = [];
			} else {
				$local_context = trim((string) $key);
				$metadata = is_array($value) ? $value : [];
			}

			$local_context = PackageTypeHelper::normalizeId(
				$local_context,
				"Package metadata '{$package}' tag_context"
			);
			$context = $package_id . '_' . $local_context;

			if (strlen($context) > 64) {
				throw new RuntimeException("Package metadata '{$package}' tag context '{$context}' exceeds 64 characters: {$metadata_path}");
			}

			if (isset($normalized[$local_context])) {
				throw new RuntimeException("Package metadata '{$package}' tag_contexts has duplicate context '{$local_context}': {$metadata_path}");
			}

			$label = $metadata['label'] ?? null;
			$label = is_string($label) && trim($label) !== '' ? trim($label) : null;
			$normalized[$local_context] = [
				'context' => $context,
				'label' => $label,
			];
		}

		ksort($normalized);

		return $normalized;
	}

	/**
	 * @return array<string, string>
	 */
	private static function normalizeDeprecatedLayoutsMetadata(mixed $deprecated_layouts, string $metadata_path, string $package): array
	{
		if ($deprecated_layouts === null || $deprecated_layouts === []) {
			return [];
		}

		if (!is_array($deprecated_layouts)) {
			throw new RuntimeException("Package metadata '{$package}' deprecated_layouts must be an object: {$metadata_path}");
		}

		$normalized = [];

		foreach ($deprecated_layouts as $old_layout => $new_layout) {
			if (!is_string($old_layout) || trim($old_layout) === '') {
				throw new RuntimeException("Package metadata '{$package}' deprecated_layouts has a non-string or empty key: {$metadata_path}");
			}

			if (!is_string($new_layout) || trim($new_layout) === '') {
				throw new RuntimeException("Package metadata '{$package}' deprecated_layouts['{$old_layout}'] must be a non-empty string: {$metadata_path}");
			}

			$old_trimmed = trim($old_layout);
			$new_trimmed = trim($new_layout);

			if ($old_trimmed === $new_trimmed) {
				throw new RuntimeException("Package metadata '{$package}' deprecated_layouts['{$old_trimmed}'] cannot map to itself: {$metadata_path}");
			}

			if (isset($normalized[$old_trimmed])) {
				throw new RuntimeException("Package metadata '{$package}' deprecated_layouts has duplicate key '{$old_trimmed}': {$metadata_path}");
			}

			$normalized[$old_trimmed] = $new_trimmed;
		}

		ksort($normalized);

		return $normalized;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function loadRawDocumentFromPath(string $metadata_path): array
	{
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

		return $metadata;
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
