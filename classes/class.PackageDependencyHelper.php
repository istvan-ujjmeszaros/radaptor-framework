<?php

class PackageDependencyHelper
{
	/**
	 * @return array<string, string>
	 */
	public static function normalizeDependencies(mixed $dependencies, string $context = 'package'): array
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
	 * @param array<string, array<string, mixed>> $packages
	 * @return array<string, string>
	 */
	public static function buildPackageNameMap(array $packages): array
	{
		$names = [];

		foreach ($packages as $package_key => $package) {
			$package_name = trim((string) ($package['package'] ?? ''));

			if ($package_name === '') {
				continue;
			}

			if (isset($names[$package_name]) && $names[$package_name] !== $package_key) {
				throw new RuntimeException(
					"Duplicate package name '{$package_name}' is declared by '{$names[$package_name]}' and '{$package_key}'."
				);
			}

			$names[$package_name] = $package_key;
		}

		ksort($names);

		return $names;
	}

	/**
	 * @param array<string, array<string, mixed>> $packages
	 * @return array<string, array<string, string>>
	 */
	public static function findMissingDependencies(array $packages): array
	{
		$package_map = self::buildPackageNameMap($packages);
		$missing = [];

		foreach ($packages as $package_key => $package) {
			$dependencies = self::normalizeDependencies(
				$package['dependencies'] ?? [],
				"Package '{$package_key}'"
			);

			foreach ($dependencies as $dependency_package => $constraint) {
				if (!isset($package_map[$dependency_package])) {
					$missing[$package_key][$dependency_package] = $constraint;
				}
			}
		}

		foreach ($missing as &$package_missing) {
			ksort($package_missing);
		}
		unset($package_missing);
		ksort($missing);

		return $missing;
	}

	/**
	 * @param array<string, array<string, mixed>> $packages
	 * @return array<string, array<string, array{constraint: string, resolved_version: string|null, dependency_key: string|null}>>
	 */
	public static function findDependencyVersionMismatches(array $packages): array
	{
		$package_map = self::buildPackageNameMap($packages);
		$mismatches = [];

		foreach ($packages as $package_key => $package) {
			$dependencies = self::normalizeDependencies(
				$package['dependencies'] ?? [],
				"Package '{$package_key}'"
			);

			foreach ($dependencies as $dependency_package => $constraint) {
				$dependency_key = $package_map[$dependency_package] ?? null;

				if ($dependency_key === null) {
					continue;
				}

				$dependency_version = self::extractResolvedVersion($packages[$dependency_key]);

				if ($dependency_version === null || !PluginVersionHelper::matches($dependency_version, $constraint)) {
					$mismatches[$package_key][$dependency_package] = [
						'constraint' => $constraint,
						'resolved_version' => $dependency_version,
						'dependency_key' => $dependency_key,
					];
				}
			}
		}

		foreach ($mismatches as &$package_mismatches) {
			ksort($package_mismatches);
		}
		unset($package_mismatches);
		ksort($mismatches);

		return $mismatches;
	}

	/**
	 * @param array<string, array<string, mixed>> $packages
	 * @return list<string>
	 */
	public static function sortPackageKeysByDependencies(array $packages): array
	{
		$package_map = self::buildPackageNameMap($packages);
		$incoming = [];
		$adjacency = [];

		foreach ($packages as $package_key => $package) {
			$incoming[$package_key] = 0;
			$adjacency[$package_key] = [];
		}

		foreach ($packages as $package_key => $package) {
			foreach (array_keys(self::normalizeDependencies($package['dependencies'] ?? [], "Package '{$package_key}'")) as $dependency_package) {
				$dependency_key = $package_map[$dependency_package] ?? null;

				if ($dependency_key === null) {
					continue;
				}

				if ($dependency_key === $package_key) {
					throw new RuntimeException("Package dependency cycle detected: '{$package_key}' depends on itself.");
				}

				$adjacency[$dependency_key][$package_key] = true;
				$incoming[$package_key]++;
			}
		}

		$queue = array_keys(array_filter($incoming, static fn (int $count): bool => $count === 0));
		sort($queue);
		$ordered = [];

		while ($queue !== []) {
			$package_key = array_shift($queue);
			$ordered[] = $package_key;
			$dependants = array_keys($adjacency[$package_key]);
			sort($dependants);

			foreach ($dependants as $dependant_key) {
				$incoming[$dependant_key]--;

				if ($incoming[$dependant_key] === 0) {
					$queue[] = $dependant_key;
				}
			}

			sort($queue);
		}

		if (count($ordered) !== count($packages)) {
			$cycle_packages = [];

			foreach ($incoming as $package_key => $count) {
				if ($count > 0) {
					$cycle_packages[] = $package_key;
				}
			}

			sort($cycle_packages);

			throw new RuntimeException('Package dependency cycle detected: ' . implode(', ', $cycle_packages));
		}

		return $ordered;
	}

	/**
	 * @param array<string, mixed> $package
	 */
	public static function extractResolvedVersion(array $package): ?string
	{
		$resolved = is_array($package['resolved'] ?? null) ? $package['resolved'] : [];
		$version = $resolved['version'] ?? $package['version'] ?? null;

		if (!is_string($version) || trim($version) === '') {
			return null;
		}

		return trim($version);
	}
}
