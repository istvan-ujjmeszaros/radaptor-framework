<?php

class PackageReleaseVersionHelper
{
	/** @var array<int, string> */
	private const array SUPPORTED_CHANNELS = [
		'alpha',
		'beta',
		'rc',
	];

	/**
	 * @return array{
	 *     previous_version: string,
	 *     new_version: string,
	 *     channel: string|null
	 * }
	 */
	public static function planStableRelease(string $current_version): array
	{
		$parsed = self::parseVersion($current_version);

		if ($parsed['is_prerelease']) {
			return [
				'previous_version' => $parsed['normalized'],
				'new_version' => $parsed['base_version'],
				'channel' => null,
			];
		}

		return [
			'previous_version' => $parsed['normalized'],
			'new_version' => self::incrementPatch($parsed['base_version']),
			'channel' => null,
		];
	}

	/**
	 * @return array{
	 *     previous_version: string,
	 *     new_version: string,
	 *     channel: string
	 * }
	 */
	public static function planPrerelease(string $current_version, ?string $requested_channel = null): array
	{
		$parsed = self::parseVersion($current_version);
		$requested_channel = $requested_channel !== null ? strtolower(trim($requested_channel)) : null;

		if ($requested_channel !== null && $requested_channel !== '' && !in_array($requested_channel, self::SUPPORTED_CHANNELS, true)) {
			throw new RuntimeException("Unsupported prerelease channel '{$requested_channel}'. Use alpha, beta, or rc.");
		}

		if (!$parsed['is_prerelease']) {
			if ($requested_channel === null || $requested_channel === '') {
				throw new RuntimeException('Stable versions require an explicit prerelease channel.');
			}

			$base_version = self::incrementPatch($parsed['base_version']);

			return [
				'previous_version' => $parsed['normalized'],
				'new_version' => self::buildPrereleaseVersion($base_version, $requested_channel, 1),
				'channel' => $requested_channel,
			];
		}

		$current_channel = $parsed['channel'];
		$current_sequence = $parsed['sequence'];

		if ($current_channel === null || $current_sequence === null) {
			throw new RuntimeException("Unsupported prerelease version '{$parsed['normalized']}' for automated package prerelease.");
		}

		if ($requested_channel !== null && $requested_channel !== '' && $requested_channel !== $current_channel) {
			throw new RuntimeException(
				"Current version '{$parsed['normalized']}' is on '{$current_channel}'. Channel switches require an explicit stable release first."
			);
		}

		return [
			'previous_version' => $parsed['normalized'],
			'new_version' => self::buildPrereleaseVersion($parsed['base_version'], $current_channel, $current_sequence + 1),
			'channel' => $current_channel,
		];
	}

	private static function incrementPatch(string $base_version): string
	{
		[$major, $minor, $patch] = array_map('intval', explode('.', PluginVersionHelper::normalizeVersion($base_version)));

		return "{$major}.{$minor}." . ($patch + 1);
	}

	private static function buildPrereleaseVersion(string $base_version, string $channel, int $sequence): string
	{
		return PluginVersionHelper::normalizeVersion($base_version . '-' . $channel . '.' . $sequence);
	}

	/**
	 * @return array{
	 *     normalized: string,
	 *     base_version: string,
	 *     is_prerelease: bool,
	 *     channel: string|null,
	 *     sequence: int|null
	 * }
	 */
	private static function parseVersion(string $version): array
	{
		$normalized = PluginVersionHelper::normalizeVersion($version);

		if (preg_match('/^(?<base>\d+\.\d+\.\d+)-(?<channel>alpha|beta|rc)\.(?<sequence>\d+)$/', $normalized, $matches) === 1) {
			return [
				'normalized' => $normalized,
				'base_version' => $matches['base'],
				'is_prerelease' => true,
				'channel' => strtolower($matches['channel']),
				'sequence' => (int) $matches['sequence'],
			];
		}

		if (str_contains($normalized, '-')) {
			throw new RuntimeException("Unsupported prerelease version '{$normalized}'. Use <major>.<minor>.<patch>-alpha|beta|rc.<n>.");
		}

		return [
			'normalized' => $normalized,
			'base_version' => $normalized,
			'is_prerelease' => false,
			'channel' => null,
			'sequence' => null,
		];
	}
}
