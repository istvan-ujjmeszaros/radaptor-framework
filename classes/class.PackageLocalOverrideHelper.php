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
	private const string WORKSPACE_DEV_MODE_ENV = 'RADAPTOR_WORKSPACE_DEV_MODE';
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
		return self::getCommittedManifestPathForAppRoot(DEPLOY_ROOT);
	}

	public static function getLocalManifestPath(): string
	{
		return self::getLocalManifestPathForAppRoot(DEPLOY_ROOT);
	}

	public static function getCommittedLockPath(): string
	{
		return self::getCommittedLockPathForAppRoot(DEPLOY_ROOT);
	}

	public static function getLocalLockPath(): string
	{
		return self::getLocalLockPathForAppRoot(DEPLOY_ROOT);
	}

	public static function getCommittedManifestPathForAppRoot(string $app_root): string
	{
		return self::normalizePath($app_root) . '/radaptor.json';
	}

	public static function getLocalManifestPathForAppRoot(string $app_root): string
	{
		return self::normalizePath($app_root) . '/' . self::LOCAL_MANIFEST_FILENAME;
	}

	public static function getCommittedLockPathForAppRoot(string $app_root): string
	{
		return self::normalizePath($app_root) . '/radaptor.lock.json';
	}

	public static function getLocalLockPathForAppRoot(string $app_root): string
	{
		return self::normalizePath($app_root) . '/' . self::LOCAL_LOCK_FILENAME;
	}

	public static function getDevRoot(?string $deploy_root = null): string
	{
		return self::getDevRootForAppRoot($deploy_root ?? DEPLOY_ROOT);
	}

	public static function getDevRootForAppRoot(string $app_root, ?string $dev_root = null): string
	{
		$app_root = self::normalizePath($app_root);
		self::assertWorkspaceDevModeEnabled($app_root);
		$configured = trim((string) ($dev_root ?? getenv(self::DEV_ROOT_ENV)));

		if ($configured !== '') {
			return self::normalizePath($configured);
		}

		throw new RuntimeException(
			"RADAPTOR_WORKSPACE_DEV_MODE=1 and RADAPTOR_DEV_ROOT must be set when local package overrides are active. "
			. "Current app root: {$app_root}. Enable the workspace package-dev compose override or pass --ignore-local-overrides."
		);
	}

	public static function isWorkspaceDevModeEnabled(): bool
	{
		return trim((string) getenv(self::WORKSPACE_DEV_MODE_ENV)) === '1';
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
		return self::hasLocalManifestForAppRoot(DEPLOY_ROOT);
	}

	public static function hasLocalManifestForAppRoot(string $app_root): bool
	{
		return is_file(self::getLocalManifestPathForAppRoot($app_root));
	}

	public static function isLocalOverrideActive(bool $ignore_local_overrides = false): bool
	{
		return self::isLocalOverrideActiveForAppRoot(DEPLOY_ROOT, $ignore_local_overrides);
	}

	public static function isLocalOverrideActiveForAppRoot(
		string $app_root,
		bool $ignore_local_overrides = false,
		?string $dev_root = null
	): bool {
		$app_root = self::normalizePath($app_root);
		$cache_key = self::buildCacheKey($app_root, $ignore_local_overrides, $dev_root);

		if (array_key_exists($cache_key, self::$_activeCache)) {
			return self::$_activeCache[$cache_key];
		}

		if (self::areLocalOverridesDisabled($ignore_local_overrides)) {
			self::$_activeCache[$cache_key] = false;

			return false;
		}

		if (!self::hasLocalManifestForAppRoot($app_root)) {
			self::$_activeCache[$cache_key] = false;

			return false;
		}

		self::loadLocalOverrideDocumentForAppRoot($app_root, $dev_root);
		self::$_activeCache[$cache_key] = true;

		return true;
	}

	public static function getEffectiveLockPath(bool $ignore_local_overrides = false): string
	{
		return self::getEffectiveLockPathForAppRoot(DEPLOY_ROOT, $ignore_local_overrides);
	}

	public static function getEffectiveLockPathForAppRoot(
		string $app_root,
		bool $ignore_local_overrides = false,
		?string $dev_root = null
	): string {
		return self::isLocalOverrideActiveForAppRoot($app_root, $ignore_local_overrides, $dev_root)
			? self::getLocalLockPathForAppRoot($app_root)
			: self::getCommittedLockPathForAppRoot($app_root);
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
		return self::loadEffectiveManifestForAppRoot(DEPLOY_ROOT, $ignore_local_overrides);
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
	public static function loadEffectiveManifestForAppRoot(
		string $app_root,
		bool $ignore_local_overrides = false,
		?string $dev_root = null
	): array {
		$app_root = self::normalizePath($app_root);
		$cache_key = self::buildCacheKey($app_root, $ignore_local_overrides, $dev_root);

		if (isset(self::$_effectiveManifestCache[$cache_key])) {
			return self::$_effectiveManifestCache[$cache_key];
		}

		$committed_path = self::getCommittedManifestPathForAppRoot($app_root);

		if (!self::isLocalOverrideActiveForAppRoot($app_root, $ignore_local_overrides, $dev_root)) {
			self::$_effectiveManifestCache[$cache_key] = PackageManifest::loadFromPath($committed_path);

			return self::$_effectiveManifestCache[$cache_key];
		}

		$committed_document = self::decodeJsonFile($committed_path, 'package manifest');
		$local_document = self::loadLocalOverrideDocumentForAppRoot($app_root, $dev_root);
		$merged_document = self::applyLocalOverrides($committed_document, $local_document, $app_root, $dev_root);

		self::$_effectiveManifestCache[$cache_key] = PackageManifest::loadFromDocument($merged_document, $committed_path);

		return self::$_effectiveManifestCache[$cache_key];
	}

	public static function getResolvedOverridePath(string $type, string $id, bool $ignore_local_overrides = false): ?string
	{
		return self::getResolvedOverridePathForAppRoot(DEPLOY_ROOT, $type, $id, $ignore_local_overrides);
	}

	public static function getResolvedOverridePathForAppRoot(
		string $app_root,
		string $type,
		string $id,
		bool $ignore_local_overrides = false,
		?string $dev_root = null
	): ?string {
		if (!self::isLocalOverrideActiveForAppRoot($app_root, $ignore_local_overrides, $dev_root)) {
			return null;
		}

		$type = PackageTypeHelper::normalizeType($type, 'Package');
		$id = PackageTypeHelper::normalizeId($id, 'Package');
		$section = PackageTypeHelper::getSectionForType($type);
		$local_document = self::loadLocalOverrideDocumentForAppRoot($app_root, $dev_root);
		$package = $local_document[$section][$id] ?? null;

		if (!is_array($package)) {
			return null;
		}

		$source = is_array($package['source'] ?? null) ? $package['source'] : [];
		$location = trim((string) ($source['location'] ?? ''));

		if ($location === '') {
			return null;
		}

		return self::resolveLocation($location, $app_root, $dev_root);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function loadLocalOverrideDocument(): array
	{
		return self::loadLocalOverrideDocumentForAppRoot(DEPLOY_ROOT);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function loadLocalOverrideDocumentForAppRoot(string $app_root, ?string $dev_root = null): array
	{
		$app_root = self::normalizePath($app_root);
		$cache_key = self::buildCacheKey($app_root, false, $dev_root);
		$path = self::getLocalManifestPathForAppRoot($app_root);

		if (isset(self::$_localDocumentCache[$cache_key])) {
			return self::$_localDocumentCache[$cache_key];
		}

		if (!is_file($path)) {
			throw new RuntimeException("Local package override file not found: {$path}");
		}

		$document = self::decodeJsonFile($path, 'local package override');
		self::validateLocalOverrideDocument($document, $app_root, $dev_root);
		self::$_localDocumentCache[$cache_key] = $document;

		return self::$_localDocumentCache[$cache_key];
	}

	public static function resolveLocation(string $location, ?string $app_root = null, ?string $dev_root = null): string
	{
		$app_root = self::normalizePath($app_root ?? DEPLOY_ROOT);
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

		$resolved_dev_root = self::getDevRootForAppRoot($app_root, $dev_root);
		$resolved = self::normalizePath(rtrim($resolved_dev_root, '/') . '/' . implode('/', $segments));

		if (!str_starts_with($resolved . '/', rtrim($resolved_dev_root, '/') . '/')) {
			throw new RuntimeException("Local package override location '{$location}' resolves outside RADAPTOR_DEV_ROOT.");
		}

		if ($resolved === $app_root || str_starts_with($resolved, $app_root . '/')) {
			throw new RuntimeException("Local package override location '{$location}' must resolve outside the current app root.");
		}

		return $resolved;
	}

	private static function assertWorkspaceDevModeEnabled(string $app_root): void
	{
		if (self::isWorkspaceDevModeEnabled()) {
			return;
		}

		throw new RuntimeException(
			"RADAPTOR_WORKSPACE_DEV_MODE=1 must be set when local package overrides are active. "
			. "Current app root: {$app_root}. Enable the workspace package-dev compose override or pass --ignore-local-overrides."
		);
	}

	/**
	 * @param array<string, mixed> $committed_document
	 * @param array<string, mixed> $local_document
	 * @return array<string, mixed>
	 */
	private static function applyLocalOverrides(
		array $committed_document,
		array $local_document,
		string $app_root,
		?string $dev_root = null
	): array {
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
				$resolved_path = self::resolveLocation($location, $app_root, $dev_root);
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
	private static function validateLocalOverrideDocument(
		array $document,
		string $app_root,
		?string $dev_root = null
	): void {
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

				self::resolveLocation($location, $app_root, $dev_root);
			}
		}
	}

	private static function buildCacheKey(string $app_root, bool $ignore_local_overrides = false, ?string $dev_root = null): string
	{
		$context_dev_root = trim((string) ($dev_root ?? getenv(self::DEV_ROOT_ENV)));
		$normalized_dev_root = $context_dev_root === '' ? '' : self::normalizePath($context_dev_root);

		return self::normalizePath($app_root)
			. '|'
			. ($ignore_local_overrides ? 'ignore' : 'use')
			. '|'
			. $normalized_dev_root;
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
