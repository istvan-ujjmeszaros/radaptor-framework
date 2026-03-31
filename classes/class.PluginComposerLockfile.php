<?php

class PluginComposerLockfile
{
	public static function getPath(): string
	{
		return DEPLOY_ROOT . 'plugins.composer.lock.json';
	}

	/**
	 * @param array{
	 *     lockfile_version?: int,
	 *     packages?: array<string, array<string, mixed>>
	 * } $lockfile
	 * @return array{
	 *     lockfile_version: int,
	 *     packages: array<string, array<string, mixed>>
	 * }
	 */
	public static function exportDocument(array $lockfile): array
	{
		$packages = [];

		foreach (($lockfile['packages'] ?? []) as $package => $entry) {
			$packages[$package] = self::exportPackage($package, $entry);
		}

		ksort($packages);

		return [
			'lockfile_version' => max(1, (int) ($lockfile['lockfile_version'] ?? 1)),
			'packages' => $packages,
		];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{
	 *     constraint: string,
	 *     managed: bool,
	 *     root_owned: bool,
	 *     owners: array<string, string>
	 * }
	 */
	public static function exportPackage(string $package, array $entry): array
	{
		$constraint = trim((string) ($entry['constraint'] ?? ''));

		if ($constraint === '') {
			throw new RuntimeException("Plugin composer lockfile package '{$package}' is missing constraint.");
		}

		return [
			'constraint' => $constraint,
			'managed' => (bool) ($entry['managed'] ?? false),
			'root_owned' => (bool) ($entry['root_owned'] ?? false),
			'owners' => PluginDependencyHelper::normalizeDependencies(
				$entry['owners'] ?? [],
				"Plugin composer lockfile package '{$package}' owners"
			),
		];
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
			throw new RuntimeException("Unable to write plugin composer lockfile: {$path}");
		}
	}

	/**
	 * @return array{
	 *     lockfile_version: int,
	 *     packages: array<string, array<string, mixed>>,
	 *     path: string
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
	 *     path: string
	 * }
	 */
	public static function loadFromPath(string $path): array
	{
		if (!file_exists($path)) {
			return [
				'lockfile_version' => 1,
				'packages' => [],
				'path' => $path,
			];
		}

		$json = file_get_contents($path);

		if ($json === false) {
			throw new RuntimeException("Unable to read plugin composer lockfile: {$path}");
		}

		try {
			$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException("Invalid JSON in {$path}: " . $e->getMessage(), 0, $e);
		}

		if (!is_array($data)) {
			throw new RuntimeException("Plugin composer lockfile JSON root must be an object: {$path}");
		}

		$packages = [];

		foreach (($data['packages'] ?? []) as $package => $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$packages[(string) $package] = self::exportPackage((string) $package, $entry);
		}

		ksort($packages);

		return [
			'lockfile_version' => max(1, (int) ($data['lockfile_version'] ?? 1)),
			'packages' => $packages,
			'path' => $path,
		];
	}
}
