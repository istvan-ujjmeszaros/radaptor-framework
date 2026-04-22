<?php

class WorkspacePackageRegistryCatalog
{
	/**
	 * @return array{
	 *     available: bool,
	 *     root: string|null,
	 *     path: string|null,
	 *     error: string|null,
	 *     packages: array<string, array<string, mixed>>
	 * }
	 */
	public static function inspect(): array
	{
		try {
			$root = LocalRegistryRootResolver::resolve();
		} catch (Throwable $e) {
			return [
				'available' => false,
				'root' => null,
				'path' => null,
				'error' => $e->getMessage(),
				'packages' => [],
			];
		}

		$path = rtrim($root, '/') . '/registry.json';

		if (!is_file($path)) {
			return [
				'available' => false,
				'root' => $root,
				'path' => $path,
				'error' => 'Workspace package registry catalog not found.',
				'packages' => [],
			];
		}

		$json = file_get_contents($path);

		if ($json === false) {
			return [
				'available' => false,
				'root' => $root,
				'path' => $path,
				'error' => "Unable to read workspace package registry catalog: {$path}",
				'packages' => [],
			];
		}

		try {
			$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			return [
				'available' => false,
				'root' => $root,
				'path' => $path,
				'error' => "Invalid workspace package registry JSON: {$e->getMessage()}",
				'packages' => [],
			];
		}

		return [
			'available' => is_array($data),
			'root' => $root,
			'path' => $path,
			'error' => is_array($data) ? null : 'Workspace package registry catalog must decode to an object.',
			'packages' => is_array($data['packages'] ?? null) ? $data['packages'] : [],
		];
	}

	public static function getLatestVersion(array $catalog, string $package_name): ?string
	{
		$package = $catalog['packages'][$package_name] ?? null;

		if (!is_array($package)) {
			return null;
		}

		$latest = trim((string) ($package['latest'] ?? ''));

		if ($latest === '') {
			return null;
		}

		return $latest;
	}
}
