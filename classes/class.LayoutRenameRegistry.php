<?php

declare(strict_types=1);

/**
 * Aggregates `deprecated_layouts` declarations from package `.registry-package.json` metadata.
 *
 * Theme packages may declare layout renames in their `.registry-package.json`:
 *
 *     "deprecated_layouts": {
 *         "admin_nomenu": "admin_login"
 *     }
 *
 * The install/update preflight gate calls this registry to discover all renames
 * declared by incoming packages, validate that their targets exist, and detect
 * conflicting declarations across packages.
 */
final class LayoutRenameRegistry
{
	/**
	 * @param array<string, string> $source_paths package_key => absolute source path
	 * @return array<string, array{new_layout: string, package: string, version: string}>
	 */
	public static function buildFromSourcePaths(array $source_paths): array
	{
		$renames = [];

		foreach ($source_paths as $source_path) {
			$metadata_path = rtrim($source_path, '/') . '/.registry-package.json';

			if (!is_file($metadata_path)) {
				continue;
			}

			$metadata = PackageMetadataHelper::loadFromSourcePath($source_path);
			$package_name = $metadata['package'];
			$package_version = $metadata['version'];

			foreach ($metadata['deprecated_layouts'] as $old_layout => $new_layout) {
				if (isset($renames[$old_layout])) {
					if ($renames[$old_layout]['new_layout'] === $new_layout) {
						continue;
					}

					$prior = $renames[$old_layout];

					throw new RuntimeException(sprintf(
						"Conflicting deprecated_layouts: '%s' maps to '%s' in '%s' and to '%s' in '%s'.",
						$old_layout,
						$prior['new_layout'],
						$prior['package'],
						$new_layout,
						$package_name
					));
				}

				$renames[$old_layout] = [
					'new_layout' => $new_layout,
					'package' => $package_name,
					'version' => $package_version,
				];
			}
		}

		ksort($renames);

		return $renames;
	}

	/**
	 * Reduces the registry entries to a simple old => new map for downstream consumers
	 * that do not need declarer metadata.
	 *
	 * @param array<string, array{new_layout: string, package: string, version: string}> $renames
	 * @return array<string, string>
	 */
	public static function extractRenameMap(array $renames): array
	{
		return array_map(static fn (array $entry): string => $entry['new_layout'], $renames);
	}

	/**
	 * Scans theme and core/cms source paths for `template.layout_<name>.php` files so we can
	 * validate that rename targets resolve to real layouts post-install. The Layout build
	 * artifact may not exist yet at preflight time, so we glob source files directly.
	 *
	 * @param array<string, string> $source_paths package_key => absolute source path
	 * @return list<string>
	 */
	public static function collectAvailableLayouts(array $source_paths): array
	{
		$names = [];

		foreach ($source_paths as $source_path) {
			$base = rtrim($source_path, '/');
			$patterns = [
				$base . '/theme/_layout/template.layout_*.php',
				$base . '/theme/_layouts/template.layout_*.php',
				$base . '/templates-common/default-SoAdmin/_layout/template.layout_*.php',
			];

			foreach ($patterns as $pattern) {
				$matches = glob($pattern);

				if ($matches === false) {
					continue;
				}

				foreach ($matches as $file) {
					if (preg_match('/template\.layout_(.+)\.php$/', basename($file), $captured) === 1) {
						$names[] = $captured[1];
					}
				}
			}
		}

		$names = array_values(array_unique($names));
		sort($names);

		return $names;
	}

	/**
	 * Returns error messages for every rename whose target is not in the available layout set.
	 *
	 * @param array<string, array{new_layout: string, package: string, version: string}> $renames
	 * @param list<string> $available_layouts
	 * @return list<string>
	 */
	public static function validateTargets(array $renames, array $available_layouts): array
	{
		$available_set = array_fill_keys($available_layouts, true);
		$errors = [];

		foreach ($renames as $old_layout => $entry) {
			if (!isset($available_set[$entry['new_layout']])) {
				$errors[] = sprintf(
					"Package '%s' declares rename '%s' -> '%s', but target layout '%s' is not registered by any installed theme or core package.",
					$entry['package'],
					$old_layout,
					$entry['new_layout'],
					$entry['new_layout']
				);
			}
		}

		return $errors;
	}
}
