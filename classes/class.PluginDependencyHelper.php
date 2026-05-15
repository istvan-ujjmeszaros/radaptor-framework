<?php

class PluginDependencyHelper
{
	/**
	 * @return array<string, string>
	 */
	public static function normalizeDependencies(mixed $dependencies, string $context = 'plugin'): array
	{
		if ($dependencies === null || $dependencies === []) {
			return [];
		}

		if (!is_array($dependencies)) {
			throw new RuntimeException("{$context} dependencies must be an object mapping package names to version constraints.");
		}

		$normalized = [];

		foreach ($dependencies as $package => $constraint) {
			if (is_int($package)) {
				throw new RuntimeException("{$context} dependencies must be an object mapping package names to version constraints.");
			}

			$package = trim((string) $package);
			$constraint = trim((string) $constraint);

			if ($package === '' || $constraint === '') {
				throw new RuntimeException("{$context} dependencies must not contain empty package names or constraints.");
			}

			$normalized[$package] = $constraint;
		}

		ksort($normalized);

		return $normalized;
	}

	/**
	 * @param array<string, array<string, mixed>> $plugins
	 * @return array<string, string>
	 */
	public static function buildPackageToPluginMap(array $plugins): array
	{
		$packages = [];

		foreach ($plugins as $plugin_id => $plugin) {
			$package = trim((string) ($plugin['package'] ?? ''));

			if ($package === '') {
				continue;
			}

			if (isset($packages[$package]) && $packages[$package] !== $plugin_id) {
				throw new RuntimeException(
					"Duplicate plugin package '{$package}' is declared by '{$packages[$package]}' and '{$plugin_id}'."
				);
			}

			$packages[$package] = $plugin_id;
		}

		ksort($packages);

		return $packages;
	}

	/**
	 * @param array<string, array<string, mixed>> $plugins
	 * @return array<string, array<string, string>>
	 */
	public static function findMissingDependencies(array $plugins): array
	{
		$package_map = self::buildPackageToPluginMap($plugins);
		$missing = [];

		foreach ($plugins as $plugin_id => $plugin) {
			$dependencies = self::normalizeDependencies(
				$plugin['dependencies'] ?? [],
				"Plugin '{$plugin_id}'"
			);

			foreach ($dependencies as $package => $constraint) {
				if (!isset($package_map[$package])) {
					$missing[$plugin_id][$package] = $constraint;
				}
			}
		}

		foreach ($missing as &$plugin_missing) {
			ksort($plugin_missing);
		}
		unset($plugin_missing);
		ksort($missing);

		return $missing;
	}

	/**
	 * @param array<string, array<string, mixed>> $plugins
	 * @return array<string, array<string, array{constraint: string, resolved_version: string|null, dependency_plugin_id: string|null}>>
	 */
	public static function findDependencyVersionMismatches(array $plugins): array
	{
		$package_map = self::buildPackageToPluginMap($plugins);
		$mismatches = [];

		foreach ($plugins as $plugin_id => $plugin) {
			$dependencies = self::normalizeDependencies(
				$plugin['dependencies'] ?? [],
				"Plugin '{$plugin_id}'"
			);

			foreach ($dependencies as $package => $constraint) {
				$dependency_plugin_id = $package_map[$package] ?? null;

				if ($dependency_plugin_id === null) {
					continue;
				}

				$dependency_version = self::extractResolvedVersion($plugins[$dependency_plugin_id]);

				if ($dependency_version === null || !PluginVersionHelper::matches($dependency_version, $constraint)) {
					$mismatches[$plugin_id][$package] = [
						'constraint' => $constraint,
						'resolved_version' => $dependency_version,
						'dependency_plugin_id' => $dependency_plugin_id,
					];
				}
			}
		}

		foreach ($mismatches as &$plugin_mismatches) {
			ksort($plugin_mismatches);
		}
		unset($plugin_mismatches);
		ksort($mismatches);

		return $mismatches;
	}

	/**
	 * @param array<string, array<string, mixed>> $plugins
	 * @return list<string>
	 */
	public static function sortPluginIdsByDependencies(array $plugins): array
	{
		$package_map = self::buildPackageToPluginMap($plugins);
		$incoming = [];
		$adjacency = [];

		foreach ($plugins as $plugin_id => $plugin) {
			$incoming[$plugin_id] = 0;
			$adjacency[$plugin_id] = [];
		}

		foreach ($plugins as $plugin_id => $plugin) {
			foreach (array_keys(self::normalizeDependencies($plugin['dependencies'] ?? [], "Plugin '{$plugin_id}'")) as $package) {
				$dependency_plugin_id = $package_map[$package] ?? null;

				if ($dependency_plugin_id === null) {
					continue;
				}

				if ($dependency_plugin_id === $plugin_id) {
					throw new RuntimeException("Plugin dependency cycle detected: '{$plugin_id}' depends on itself.");
				}

				$adjacency[$dependency_plugin_id][$plugin_id] = true;
				$incoming[$plugin_id]++;
			}
		}

		$queue = array_keys(array_filter($incoming, static fn (int $count): bool => $count === 0));
		sort($queue);
		$ordered = [];

		while ($queue !== []) {
			$plugin_id = array_shift($queue);
			$ordered[] = $plugin_id;
			$dependants = array_keys($adjacency[$plugin_id]);
			sort($dependants);

			foreach ($dependants as $dependant_plugin_id) {
				$incoming[$dependant_plugin_id]--;

				if ($incoming[$dependant_plugin_id] === 0) {
					$queue[] = $dependant_plugin_id;
				}
			}

			sort($queue);
		}

		if (count($ordered) !== count($plugins)) {
			$cycle_plugins = [];

			foreach ($incoming as $plugin_id => $count) {
				if ($count > 0) {
					$cycle_plugins[] = $plugin_id;
				}
			}

			sort($cycle_plugins);

			throw new RuntimeException('Plugin dependency cycle detected: ' . implode(', ', $cycle_plugins));
		}

		return $ordered;
	}

	/**
	 * @param array<string, mixed> $plugin
	 */
	public static function extractResolvedVersion(array $plugin): ?string
	{
		$resolved = is_array($plugin['resolved'] ?? null) ? $plugin['resolved'] : [];
		$version = $resolved['version'] ?? $plugin['version'] ?? null;

		if (!is_string($version) || trim($version) === '') {
			return null;
		}

		return trim($version);
	}
}
