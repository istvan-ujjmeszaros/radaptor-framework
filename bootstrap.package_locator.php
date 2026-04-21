<?php

if (!function_exists('radaptorBootstrapNormalizePath')) {
	function radaptorBootstrapNormalizePath(string $path): string
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

if (!function_exists('radaptorBootstrapResolveAppRoot')) {
	function radaptorBootstrapResolveAppRoot(string $current_framework_root): string
	{
		$app_root = getenv('RADAPTOR_APP_ROOT');

		if (is_string($app_root) && trim($app_root) !== '') {
			return rtrim($app_root, '/') . '/';
		}

		$search_root = radaptorBootstrapNormalizePath($current_framework_root);

		while ($search_root !== '' && $search_root !== '.' && $search_root !== '/') {
			if (is_file($search_root . '/radaptor.json') || is_file($search_root . '/radaptor.lock.json')) {
				return rtrim($search_root, '/') . '/';
			}

			$parent = dirname($search_root);

			if ($parent === $search_root) {
				break;
			}

			$search_root = radaptorBootstrapNormalizePath($parent);
		}

		return rtrim(dirname($current_framework_root, 2), '/') . '/';
	}
}

if (!function_exists('radaptorBootstrapResolveStoredPath')) {
	function radaptorBootstrapResolveStoredPath(string $app_root, string $path): string
	{
		if (str_starts_with($path, '/')) {
			return radaptorBootstrapNormalizePath($path);
		}

		return radaptorBootstrapNormalizePath($app_root . ltrim($path, '/'));
	}
}

if (!function_exists('radaptorBootstrapLocalOverridesDisabled')) {
	function radaptorBootstrapLocalOverridesDisabled(): bool
	{
		global $argv;

		foreach ($argv ?? [] as $arg) {
			if ($arg === '--ignore-local-overrides') {
				return true;
			}
		}

		$value = strtolower(trim((string) getenv('RADAPTOR_DISABLE_LOCAL_OVERRIDES')));

		return in_array($value, ['1', 'true', 'yes', 'on'], true);
	}
}

if (!function_exists('radaptorBootstrapResolveDevRoot')) {
	function radaptorBootstrapResolveDevRoot(string $app_root): string
	{
		$configured = trim((string) getenv('RADAPTOR_DEV_ROOT'));

		if ($configured !== '') {
			return radaptorBootstrapNormalizePath($configured);
		}

		throw new RuntimeException(
			'RADAPTOR_DEV_ROOT must be set when local package overrides are active. '
			. 'Enable the workspace package-dev compose override or pass --ignore-local-overrides.'
		);
	}
}

if (!function_exists('radaptorBootstrapDecodeJsonDocument')) {
	/**
	 * @return array<string, mixed>
	 */
	function radaptorBootstrapDecodeJsonDocument(string $path, string $label): array
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
}

if (!function_exists('radaptorBootstrapResolveLocalOverrideLocation')) {
	function radaptorBootstrapResolveLocalOverrideLocation(string $location, string $app_root): string
	{
		$location = trim(str_replace('\\', '/', $location));

		if ($location === '') {
			throw new RuntimeException('Local package override source.location must not be empty.');
		}

		if (str_starts_with($location, '/')) {
			throw new RuntimeException("Local package override location '{$location}' must be relative.");
		}

		foreach (explode('/', $location) as $segment) {
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

		$resolved = radaptorBootstrapNormalizePath(
			rtrim(radaptorBootstrapResolveDevRoot($app_root), '/') . '/' . $location
		);
		$dev_root = radaptorBootstrapNormalizePath(radaptorBootstrapResolveDevRoot($app_root));
		$app_root = rtrim(radaptorBootstrapNormalizePath($app_root), '/');

		if (!str_starts_with($resolved . '/', $dev_root . '/')) {
			throw new RuntimeException("Local package override location '{$location}' resolves outside RADAPTOR_DEV_ROOT.");
		}

		if ($resolved === $app_root || str_starts_with($resolved, $app_root . '/')) {
			throw new RuntimeException(
				"Local package override location '{$location}' must resolve outside the current app root."
			);
		}

		return $resolved;
	}
}

if (!function_exists('radaptorBootstrapLoadLocalOverrideDocument')) {
	/**
	 * @return array<string, mixed>|null
	 */
	function radaptorBootstrapLoadLocalOverrideDocument(string $app_root): ?array
	{
		if (radaptorBootstrapLocalOverridesDisabled()) {
			return null;
		}

		$path = rtrim($app_root, '/') . '/radaptor.local.json';

		if (!is_file($path)) {
			return null;
		}

		$data = radaptorBootstrapDecodeJsonDocument($path, 'local package override');
		$allowed_root_keys = ['manifest_version', 'core', 'themes'];

		foreach (array_keys($data) as $key) {
			if (!in_array($key, $allowed_root_keys, true)) {
				throw new RuntimeException(
					"Local package override file may only define 'manifest_version', 'core', and 'themes'. Invalid key: '{$key}'."
				);
			}
		}

		foreach (['core', 'themes'] as $section) {
			$packages = $data[$section] ?? null;

			if ($packages === null) {
				continue;
			}

			if (!is_array($packages)) {
				throw new RuntimeException("Local package override section '{$section}' must be an object.");
			}

			foreach ($packages as $id => $package) {
				if (!is_array($package) || array_keys($package) !== ['source']) {
					throw new RuntimeException("Local package override entry '{$section}.{$id}' may only define 'source'.");
				}

				$source = $package['source'] ?? null;

				if (!is_array($source) || array_keys($source) !== ['type', 'location']) {
					throw new RuntimeException(
						"Local package override '{$section}.{$id}.source' may only define 'type' and 'location'."
					);
				}

				if (($source['type'] ?? null) !== 'dev') {
					throw new RuntimeException("Local package override '{$section}.{$id}' must use source.type='dev'.");
				}

				radaptorBootstrapResolveLocalOverrideLocation(trim((string) ($source['location'] ?? '')), $app_root);
			}
		}

		return $data;
	}
}

if (!function_exists('radaptorBootstrapResolveFrameworkRootFromDocument')) {
	function radaptorBootstrapResolveFrameworkRootFromDocument(array $data, string $app_root): ?string
	{
		$framework = $data['core']['framework'] ?? null;

		if (!is_array($framework)) {
			return null;
		}

		foreach (['resolved', 'source'] as $section) {
			$source = $framework[$section] ?? null;

			if (!is_array($source) || !is_string($source['path'] ?? null) || trim((string) $source['path']) === '') {
				continue;
			}

			$resolved_root = radaptorBootstrapResolveStoredPath($app_root, trim((string) $source['path']));

			if (is_dir($resolved_root)) {
				return $resolved_root;
			}
		}

		return null;
	}
}

if (!function_exists('radaptorBootstrapResolveFrameworkRootFromLocalOverride')) {
	function radaptorBootstrapResolveFrameworkRootFromLocalOverride(array $data, string $app_root): ?string
	{
		$framework = $data['core']['framework'] ?? null;

		if (!is_array($framework)) {
			return null;
		}

		$source = $framework['source'] ?? null;

		if (!is_array($source) || ($source['type'] ?? null) !== 'dev') {
			return null;
		}

		$location = trim((string) ($source['location'] ?? ''));

		if ($location === '') {
			return null;
		}

		$resolved_root = radaptorBootstrapResolveLocalOverrideLocation($location, $app_root);

		if (!is_dir($resolved_root)) {
			throw new RuntimeException("Local framework override directory does not exist: {$resolved_root}");
		}

		return $resolved_root;
	}
}

if (!function_exists('radaptorBootstrapResolveFrameworkRoot')) {
	function radaptorBootstrapResolveFrameworkRoot(string $current_framework_root): string
	{
		$current_framework_root = radaptorBootstrapNormalizePath($current_framework_root);
		$app_root = radaptorBootstrapResolveAppRoot($current_framework_root);
		$local_override = radaptorBootstrapLoadLocalOverrideDocument($app_root);
		$local_lock_path = rtrim($app_root, '/') . '/radaptor.local.lock.json';
		$lock_path = rtrim($app_root, '/') . '/radaptor.lock.json';
		$manifest_path = rtrim($app_root, '/') . '/radaptor.json';

		if (is_array($local_override)) {
			if (is_file($local_lock_path)) {
				$data = radaptorBootstrapDecodeJsonDocument($local_lock_path, 'local package lockfile');
				$resolved_root = radaptorBootstrapResolveFrameworkRootFromDocument($data, $app_root);

				if ($resolved_root !== null) {
					return $resolved_root;
				}

				throw new RuntimeException(
					"Framework package is missing from local lockfile '{$local_lock_path}'. Run `php radaptor.php install --json` or `php radaptor.php local-lock:refresh --json`."
				);
			}

			$resolved_root = radaptorBootstrapResolveFrameworkRootFromLocalOverride($local_override, $app_root);

			if ($resolved_root !== null) {
				return $resolved_root;
			}
		}

		if (is_file($lock_path)) {
			$data = radaptorBootstrapDecodeJsonDocument($lock_path, 'package lockfile');
			$resolved_root = radaptorBootstrapResolveFrameworkRootFromDocument($data, $app_root);

			if ($resolved_root !== null) {
				return $resolved_root;
			}
		}

		if (is_file($manifest_path)) {
			$data = radaptorBootstrapDecodeJsonDocument($manifest_path, 'package manifest');
			$resolved_root = radaptorBootstrapResolveFrameworkRootFromDocument($data, $app_root);

			if ($resolved_root !== null) {
				return $resolved_root;
			}
		}

		return $current_framework_root;
	}
}
