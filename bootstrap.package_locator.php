<?php

if (!function_exists('radaptorBootstrapNormalizePath')) {
	function radaptorBootstrapNormalizePath(string $path): string
	{
		$path = str_replace('\\', '/', $path);
		$real = realpath($path);

		if ($real !== false) {
			return rtrim(str_replace('\\', '/', $real), '/');
		}

		return rtrim($path, '/');
	}
}

if (!function_exists('radaptorBootstrapResolveAppRoot')) {
	function radaptorBootstrapResolveAppRoot(string $current_framework_root): string
	{
		$app_root = getenv('RADAPTOR_APP_ROOT');

		if (is_string($app_root) && trim($app_root) !== '') {
			return rtrim($app_root, '/') . '/';
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

if (!function_exists('radaptorBootstrapResolveFrameworkRoot')) {
	function radaptorBootstrapResolveFrameworkRoot(string $current_framework_root): string
	{
		$current_framework_root = radaptorBootstrapNormalizePath($current_framework_root);
		$app_root = radaptorBootstrapResolveAppRoot($current_framework_root);
		$lock_path = $app_root . 'radaptor.lock.json';

		if (!is_file($lock_path)) {
			return $current_framework_root;
		}

		$json = file_get_contents($lock_path);

		if ($json === false || trim($json) === '') {
			return $current_framework_root;
		}

		try {
			$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return $current_framework_root;
		}

		if (!is_array($data)) {
			return $current_framework_root;
		}

		$framework = $data['core']['framework'] ?? null;

		if (!is_array($framework)) {
			return $current_framework_root;
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

		return $current_framework_root;
	}
}
