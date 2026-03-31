<?php

class PackageRegistryClient
{
	private const int REGISTRY_FETCH_TIMEOUT_SECONDS = 15;

	/**
	 * @param array<string, mixed> $registry
	 * @return array<string, mixed>
	 */
	public static function fetchCatalog(array $registry): array
	{
		$url = $registry['resolved_url'] ?? null;

		if (!is_string($url) || !self::isSupportedRegistryUrl($url)) {
			throw new RuntimeException("Package registry URL is invalid for '{$registry['name']}'.");
		}

		$catalog = self::decodeJsonFromUrl($url);
		$catalog['registry_name'] = $registry['name'];
		$catalog['registry_url'] = $url;

		return $catalog;
	}

	/**
	 * @param array<string, mixed> $registry
	 * @return array{
	 *     registry_name: string,
	 *     registry_url: string,
	 *     package: string,
	 *     type: string,
	 *     id: string,
	 *     version: string,
	 *     dependencies: array<string, string>,
	 *     composer_require: array<string, string>,
	 *     assets: array{
	 *         public: list<array{source: string, target: string}>
	 *     },
	 *     dist: array{
	 *         type: string,
	 *         url: string,
	 *         sha256: string
	 *     }
	 * }
	 */
	public static function resolvePackage(array $registry, string $package, ?string $requested_version = null): array
	{
		$catalog = self::fetchCatalog($registry);
		$packages = $catalog['packages'] ?? null;

		if (!is_array($packages) || !isset($packages[$package]) || !is_array($packages[$package])) {
			throw new RuntimeException("Package '{$package}' was not found in registry '{$registry['name']}'.");
		}

		$package_entry = $packages[$package];
		$versions = $package_entry['versions'] ?? null;

		if (!is_array($versions) || $versions === []) {
			throw new RuntimeException("Registry package '{$package}' does not define any versions.");
		}

		$version = self::selectPackageVersion($package_entry, $requested_version);

		if (!isset($versions[$version]) || !is_array($versions[$version])) {
			throw new RuntimeException("Registry package '{$package}' does not provide version '{$version}'.");
		}

		$version_entry = $versions[$version];
		$type = PackageTypeHelper::normalizeType(
			$version_entry['type'] ?? null,
			"Registry package '{$package}' version '{$version}'"
		);
		$id = PackageTypeHelper::normalizeId(
			$version_entry['id'] ?? null,
			"Registry package '{$package}' version '{$version}'"
		);
		$dist = $version_entry['dist'] ?? null;
		$dependencies = PackageDependencyHelper::normalizeDependencies(
			$version_entry['dependencies'] ?? [],
			"Registry package '{$package}' version '{$version}'"
		);
		$composer_require = PackageDependencyHelper::normalizeDependencies(
			(is_array($version_entry['composer'] ?? null) ? ($version_entry['composer']['require'] ?? []) : []),
			"Registry package '{$package}' version '{$version}' composer.require"
		);
		$assets = self::normalizeAssets(
			$version_entry['assets'] ?? [],
			$package,
			$version
		);

		if (!is_array($dist)) {
			throw new RuntimeException("Registry package '{$package}' version '{$version}' is missing dist metadata.");
		}

		$dist_type = $dist['type'] ?? null;
		$dist_url = $dist['url'] ?? null;
		$dist_sha256 = $dist['sha256'] ?? null;

		if (!is_string($dist_type) || $dist_type === '') {
			throw new RuntimeException("Registry package '{$package}' version '{$version}' is missing dist.type.");
		}

		if (!is_string($dist_url) || $dist_url === '') {
			throw new RuntimeException("Registry package '{$package}' version '{$version}' is missing dist.url.");
		}

		if (!is_string($dist_sha256) || trim($dist_sha256) === '') {
			throw new RuntimeException("Registry package '{$package}' version '{$version}' is missing dist.sha256.");
		}

		return [
			'registry_name' => (string) $catalog['registry_name'],
			'registry_url' => (string) $catalog['registry_url'],
			'package' => $package,
			'type' => $type,
			'id' => $id,
			'version' => $version,
			'dependencies' => $dependencies,
			'composer_require' => $composer_require,
			'assets' => $assets,
			'dist' => [
				'type' => $dist_type,
				'url' => self::resolveUrl($catalog['registry_url'], $dist_url),
				'sha256' => strtolower(trim($dist_sha256)),
			],
		];
	}

	/**
	 * @param array<string, mixed> $package_entry
	 */
	private static function selectPackageVersion(array $package_entry, ?string $requested_version): string
	{
		$versions = $package_entry['versions'] ?? null;

		if (!is_array($versions) || $versions === []) {
			throw new RuntimeException('Registry package does not define any versions.');
		}

		if ($requested_version === null || trim($requested_version) === '') {
			$latest = $package_entry['latest'] ?? null;

			if (is_string($latest) && $latest !== '' && isset($versions[$latest]) && is_array($versions[$latest])) {
				return $latest;
			}

			$selected = PluginVersionHelper::selectBestMatchingVersion(array_keys($versions));

			if ($selected !== null) {
				return $selected;
			}

			throw new RuntimeException('Registry package does not define a usable version.');
		}

		$selected = PluginVersionHelper::selectBestMatchingVersion(array_keys($versions), trim($requested_version));

		if ($selected === null) {
			throw new RuntimeException(
				"Registry package does not provide a version matching constraint '{$requested_version}'."
			);
		}

		return $selected;
	}

	public static function isSupportedRegistryUrl(string $url): bool
	{
		if (preg_match('#^(https?|file)://#i', $url) !== 1) {
			return false;
		}

		$parts = parse_url($url);

		if ($parts === false || !isset($parts['scheme'])) {
			return false;
		}

		if (strtolower((string) $parts['scheme']) === 'file') {
			return self::isAllowedLocalFileUrl($url);
		}

		return true;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decodeJsonFromUrl(string $url): array
	{
		if (!self::isSupportedRegistryUrl($url)) {
			throw new RuntimeException("Package registry URL is not allowed: {$url}");
		}

		$context = stream_context_create([
			'http' => [
				'timeout' => self::REGISTRY_FETCH_TIMEOUT_SECONDS,
				'follow_location' => 1,
			],
			'https' => [
				'timeout' => self::REGISTRY_FETCH_TIMEOUT_SECONDS,
				'follow_location' => 1,
			],
		]);
		$fetch_error = null;
		set_error_handler(static function (int $_severity, string $message) use (&$fetch_error): bool {
			$fetch_error = $message;

			return true;
		});

		try {
			$json = file_get_contents($url, false, $context);
		} finally {
			restore_error_handler();
		}

		if ($json === false) {
			throw new RuntimeException(
				"Unable to fetch package registry URL: {$url}"
				. ($fetch_error !== null ? ': ' . $fetch_error : '')
			);
		}

		try {
			$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException("Invalid package registry JSON at {$url}: " . $e->getMessage(), 0, $e);
		}

		if (!is_array($data)) {
			throw new RuntimeException("Package registry JSON root must be an object: {$url}");
		}

		return $data;
	}

	private static function resolveUrl(string $base_url, string $candidate): string
	{
		if (filter_var($candidate, FILTER_VALIDATE_URL) !== false) {
			if (str_starts_with(strtolower($candidate), 'file://') && !self::isAllowedLocalFileUrl($candidate)) {
				throw new RuntimeException("Package registry file URL is not allowed: {$candidate}");
			}

			return $candidate;
		}

		$base = parse_url($base_url);

		if (!is_array($base) || !isset($base['scheme'])) {
			throw new RuntimeException("Unable to resolve package registry URL base: {$base_url}");
		}

		if ($base['scheme'] === 'file') {
			$base_path = $base['path'] ?? '';
			$base_dir = rtrim(str_replace('\\', '/', dirname($base_path)), '/');
			$path = str_starts_with($candidate, '/')
				? $candidate
				: ($base_dir . '/' . ltrim($candidate, '/'));
			$resolved = 'file://' . self::normalizePath($path);

			if (!self::isAllowedLocalFileUrl($resolved)) {
				throw new RuntimeException("Package registry file URL is not allowed: {$resolved}");
			}

			return $resolved;
		}

		$authority = $base['scheme'] . '://' . ($base['host'] ?? '');

		if (isset($base['port'])) {
			$authority .= ':' . $base['port'];
		}

		if (str_starts_with($candidate, '/')) {
			return $authority . $candidate;
		}

		$base_path = $base['path'] ?? '/';
		$base_dir = rtrim(str_replace('\\', '/', dirname($base_path)), '/');
		$joined_path = self::normalizeRelativeUrlPath($base_dir . '/' . $candidate);

		return $authority . $joined_path;
	}

	private static function normalizePath(string $path): string
	{
		$path = str_replace('\\', '/', $path);
		$real = realpath($path);

		if ($real !== false) {
			return rtrim(str_replace('\\', '/', $real), '/');
		}

		return self::normalizeRelativeUrlPath($path);
	}

	private static function normalizeRelativeUrlPath(string $path): string
	{
		$path = str_replace('\\', '/', $path);
		$prefix = str_starts_with($path, '/') ? '/' : '';
		$segments = [];

		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			if ($segment === '..') {
				array_pop($segments);

				continue;
			}

			$segments[] = $segment;
		}

		return $prefix . implode('/', $segments);
	}

	private static function isAllowedLocalFileUrl(string $url): bool
	{
		$parts = parse_url($url);

		if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'file') {
			return false;
		}

		$path = $parts['path'] ?? null;

		if (!is_string($path) || trim($path) === '') {
			return false;
		}

		$normalized_path = self::normalizePath($path);

		foreach (self::getAllowedLocalFileRoots() as $root) {
			if ($normalized_path === $root || str_starts_with($normalized_path, $root . '/')) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<string>
	 */
	private static function getAllowedLocalFileRoots(): array
	{
		$workspace_root = dirname(rtrim(DEPLOY_ROOT, '/'));
		$roots = [
			self::normalizePath(sys_get_temp_dir()),
			self::normalizePath(DEPLOY_ROOT . 'tmp'),
			self::normalizePath($workspace_root . '/radaptor_plugin_registry'),
		];

		sort($roots);

		return array_values(array_unique($roots));
	}

	/**
	 * @return array{
	 *     public: list<array{source: string, target: string}>
	 * }
	 */
	private static function normalizeAssets(mixed $assets, string $package, string $version): array
	{
		if ($assets === null || $assets === []) {
			return [
				'public' => [],
			];
		}

		if (!is_array($assets)) {
			throw new RuntimeException("Registry package '{$package}' version '{$version}' assets must be an object.");
		}

		$public_assets = $assets['public'] ?? [];

		if ($public_assets === []) {
			return [
				'public' => [],
			];
		}

		if (!is_array($public_assets)) {
			throw new RuntimeException("Registry package '{$package}' version '{$version}' assets.public must be an array.");
		}

		$normalized = [];

		foreach ($public_assets as $index => $asset) {
			if (!is_array($asset)) {
				throw new RuntimeException("Registry package '{$package}' version '{$version}' assets.public[{$index}] must be an object.");
			}

			$source = trim((string) ($asset['source'] ?? ''));
			$target = trim((string) ($asset['target'] ?? ''));

			if ($source === '' || $target === '') {
				throw new RuntimeException("Registry package '{$package}' version '{$version}' assets.public[{$index}] must define source and target.");
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
