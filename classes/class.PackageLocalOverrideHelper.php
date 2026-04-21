<?php

if (!class_exists('PackageTypeHelper', false)) {
	require_once __DIR__ . '/class.PackageTypeHelper.php';
}

if (!class_exists('PackageManifest', false)) {
	require_once __DIR__ . '/class.PackageManifest.php';
}

class PackageLocalOverrideHelper
{
	private const string LOCAL_MANIFEST_FILENAME = 'radaptor.local.json';
	private const string LOCAL_LOCK_FILENAME = 'radaptor.local.lock.json';
	private const string DEV_ROOT_ENV = 'RADAPTOR_DEV_ROOT';
	private const string DISABLE_ENV = 'RADAPTOR_DISABLE_LOCAL_OVERRIDES';

	/** @var array<string, array<string, mixed>> */
	private static array $_effectiveManifestCache = [];

	/** @var array<string, bool> */
	private static array $_activeCache = [];

	/** @var array<string, array<string, mixed>> */
	private static array $_localDocumentCache = [];

	public static function reset(): void
	{
		self::$_effectiveManifestCache = [];
		self::$_activeCache = [];
		self::$_localDocumentCache = [];
	}

	public static function getCommittedManifestPath(): string
	{
		return DEPLOY_ROOT . 'radaptor.json';
	}

	public static function getLocalManifestPath(): string
	{
		return DEPLOY_ROOT . self::LOCAL_MANIFEST_FILENAME;
	}

	public static function getCommittedLockPath(): string
	{
		return DEPLOY_ROOT . 'radaptor.lock.json';
	}

	public static function getLocalLockPath(): string
	{
		return DEPLOY_ROOT . self::LOCAL_LOCK_FILENAME;
	}

	public static function getDevRoot(?string $deploy_root = null): string
	{
		$configured = trim((string) getenv(self::DEV_ROOT_ENV));

		if ($configured !== '') {
			return self::normalizePath($configured);
		}

		$deploy_root ??= DEPLOY_ROOT;
		$app_root = self::normalizePath($deploy_root);

		throw new RuntimeException(
			"RADAPTOR_DEV_ROOT must be set when local package overrides are active. "
			. "Current app root: {$app_root}. Enable the workspace package-dev compose override or pass --ignore-local-overrides."
		);
	}

	public static function areLocalOverridesDisabled(bool $ignore_local_overrides = false): bool
	{
		if ($ignore_local_overrides) {
			return true;
		}

		global $argv;

		foreach ($argv ?? [] as $arg) {
			if ($arg === '--ignore-local-overrides') {
				return true;
			}
		}

		$value = strtolower(trim((string) getenv(self::DISABLE_ENV)));

		return in_array($value, ['1', 'true', 'yes', 'on'], true);
	}

	public static function hasLocalManifest(): bool
	{
		return is_file(self::getLocalManifestPath());
	}

	public static function isLocalOverrideActive(bool $ignore_local_overrides = false): bool
	{
		$cache_key = $ignore_local_overrides ? 'ignore' : 'default';

		if (array_key_exists($cache_key, self::$_activeCache)) {
			return self::$_activeCache[$cache_key];
		}

		if (self::areLocalOverridesDisabled($ignore_local_overrides)) {
			self::$_activeCache[$cache_key] = false;

			return false;
		}

		if (!self::hasLocalManifest()) {
			self::$_activeCache[$cache_key] = false;

			return false;
		}

		self::loadLocalOverrideDocument();
		self::$_activeCache[$cache_key] = true;

		return true;
	}

	public static function getEffectiveLockPath(bool $ignore_local_overrides = false): string
	{
		return self::isLocalOverrideActive($ignore_local_overrides)
			? self::getLocalLockPath()
			: self::getCommittedLockPath();
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
	public static function loadEffectiveManifest(bool $ignore_local_overrides = false): array
	{
		$cache_key = $ignore_local_overrides ? 'ignore' : 'default';

		if (isset(self::$_effectiveManifestCache[$cache_key])) {
			return self::$_effectiveManifestCache[$cache_key];
		}

		$committed_path = self::getCommittedManifestPath();

		if (!self::isLocalOverrideActive($ignore_local_overrides)) {
			self::$_effectiveManifestCache[$cache_key] = PackageManifest::loadFromPath($committed_path);

			return self::$_effectiveManifestCache[$cache_key];
		}

		$committed_document = self::decodeJsonFile($committed_path, 'package manifest');
		$local_document = self::loadLocalOverrideDocument();
		$merged_document = self::applyLocalOverrides($committed_document, $local_document);

		self::$_effectiveManifestCache[$cache_key] = PackageManifest::loadFromDocument($merged_document, $committed_path);

		return self::$_effectiveManifestCache[$cache_key];
	}

	public static function getResolvedOverridePath(string $type, string $id, bool $ignore_local_overrides = false): ?string
	{
		if (!self::isLocalOverrideActive($ignore_local_overrides)) {
			return null;
		}

		$type = PackageTypeHelper::normalizeType($type, 'Package');
		$id = PackageTypeHelper::normalizeId($id, 'Package');
		$section = PackageTypeHelper::getSectionForType($type);
		$local_document = self::loadLocalOverrideDocument();
		$package = $local_document[$section][$id] ?? null;

		if (!is_array($package)) {
			return null;
		}

		$source = is_array($package['source'] ?? null) ? $package['source'] : [];
		$location = trim((string) ($source['location'] ?? ''));

		if ($location === '') {
			return null;
		}

		return self::resolveLocation($location);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function loadLocalOverrideDocument(): array
	{
		$path = self::getLocalManifestPath();

		if (isset(self::$_localDocumentCache[$path])) {
			return self::$_localDocumentCache[$path];
		}

		if (!is_file($path)) {
			throw new RuntimeException("Local package override file not found: {$path}");
		}

		$document = self::decodeJsonFile($path, 'local package override');
		self::validateLocalOverrideDocument($document);
		self::$_localDocumentCache[$path] = $document;

		return self::$_localDocumentCache[$path];
	}

	/**
	 * @param array<string, mixed> $committed_document
	 * @param array<string, mixed> $local_document
	 * @return array<string, mixed>
	 */
	private static function applyLocalOverrides(array $committed_document, array $local_document): array
	{
		$merged = $committed_document;

		foreach (['core', 'themes'] as $section) {
			$overrides = $local_document[$section] ?? null;

			if (!is_array($overrides)) {
				continue;
			}

			foreach ($overrides as $id => $package) {
				if (!is_array($package)) {
					continue;
				}

				if (!isset($merged[$section][$id]) || !is_array($merged[$section][$id])) {
					throw new RuntimeException("Local package override references unknown package '{$section}.{$id}'.");
				}

				$source = is_array($package['source'] ?? null) ? $package['source'] : [];
				$location = trim((string) ($source['location'] ?? ''));
				$resolved_path = self::resolveLocation($location);
				$merged[$section][$id]['source'] = [
					'type' => 'dev',
					'path' => $resolved_path,
				];
			}
		}

		return $merged;
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private static function validateLocalOverrideDocument(array $document): void
	{
		$allowed_sections = ['core', 'themes'];
		$allowed_root_keys = array_merge(['manifest_version'], $allowed_sections);

		foreach (array_keys($document) as $key) {
			if (!in_array($key, $allowed_root_keys, true)) {
				throw new RuntimeException(
					"Local package override file may only define 'manifest_version', 'core', and 'themes'. Invalid key: '{$key}'."
				);
			}
		}

		foreach ($allowed_sections as $section) {
			$packages = $document[$section] ?? null;

			if ($packages === null) {
				continue;
			}

			if (!is_array($packages)) {
				throw new RuntimeException("Local package override section '{$section}' must be an object.");
			}

			foreach ($packages as $id => $package) {
				PackageTypeHelper::normalizeId($id, "Local package override '{$section}'");

				if (!is_array($package)) {
					throw new RuntimeException("Local package override entry '{$section}.{$id}' must be an object.");
				}

				if (array_keys($package) !== ['source']) {
					throw new RuntimeException("Local package override entry '{$section}.{$id}' may only define 'source'.");
				}

				$source = $package['source'] ?? null;

				if (!is_array($source)) {
					throw new RuntimeException("Local package override entry '{$section}.{$id}.source' must be an object.");
				}

				$allowed_source_keys = ['type', 'location'];

				foreach (array_keys($source) as $key) {
					if (!in_array($key, $allowed_source_keys, true)) {
						throw new RuntimeException(
							"Local package override '{$section}.{$id}.source' uses unsupported key '{$key}'."
						);
					}
				}

				if (($source['type'] ?? null) !== 'dev') {
					throw new RuntimeException("Local package override '{$section}.{$id}' must use source.type='dev'.");
				}

				$location = trim((string) ($source['location'] ?? ''));

				if ($location === '') {
					throw new RuntimeException("Local package override '{$section}.{$id}' is missing source.location.");
				}

				self::resolveLocation($location);
			}
		}
	}

	public static function resolveLocation(string $location): string
	{
		$location = trim(str_replace('\\', '/', $location));

		if ($location === '') {
			throw new RuntimeException('Local package override source.location must not be empty.');
		}

		if (str_starts_with($location, '/')) {
			throw new RuntimeException("Local package override location '{$location}' must be relative.");
		}

		$segments = explode('/', $location);

		foreach ($segments as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				throw new RuntimeException(
					"Local package override location '{$location}' contains an invalid path segment."
				);
			}

			if (preg_match('/^[A-Za-z0-9._-]+$/', $segment) !== 1) {
				throw new RuntimeException(
					"Local package override location '{$location}' contains unsupported characters."
				);
			}
		}

		$resolved = self::normalizePath(rtrim(self::getDevRoot(), '/') . '/' . implode('/', $segments));
		$dev_root = self::normalizePath(self::getDevRoot());
		$deploy_root = self::normalizePath(DEPLOY_ROOT);

		if (!str_starts_with($resolved . '/', $dev_root . '/')) {
			throw new RuntimeException("Local package override location '{$location}' resolves outside RADAPTOR_DEV_ROOT.");
		}

		if ($resolved === $deploy_root || str_starts_with($resolved, $deploy_root . '/')) {
			throw new RuntimeException("Local package override location '{$location}' must resolve outside the current app root.");
		}

		return $resolved;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decodeJsonFile(string $path, string $label): array
	{
		$json = file_get_contents($path);

		if ($json === false) {
			throw new RuntimeException("Unable to read {$label}: {$path}");
		}

		try {
			$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new RuntimeException("Invalid JSON in {$label} {$path}: {$exception->getMessage()}", 0, $exception);
		}

		if (!is_array($data)) {
			throw new RuntimeException(ucfirst($label) . " must decode to an object: {$path}");
		}

		return $data;
	}

	private static function normalizePath(string $path): string
	{
		$path = str_replace('\\', '/', $path);
		$prefix = '';

		if ($path !== '' && $path[0] === '/') {
			$prefix = '/';
			$path = substr($path, 1);
		}

		$segments = [];

		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			if ($segment === '..' && $segments !== [] && end($segments) !== '..') {
				array_pop($segments);
			} else {
				$segments[] = $segment;
			}
		}

		return rtrim($prefix . implode('/', $segments), '/');
	}
}
