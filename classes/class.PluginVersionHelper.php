<?php

class PluginVersionHelper
{
	public static function normalizeVersion(string $version): string
	{
		$version = trim($version);

		if (!preg_match('/^(?<core>\d+(?:\.\d+){0,2})(?<prerelease>-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version, $matches)) {
			throw new RuntimeException("Unsupported plugin version format '{$version}'. Use semver-style versions like 1.2.3 or 1.2.3-alpha.1.");
		}

		$parts = array_map('intval', explode('.', $matches['core']));

		while (count($parts) < 3) {
			$parts[] = 0;
		}

		$normalized = implode('.', array_slice($parts, 0, 3));
		$prerelease = trim((string) ($matches['prerelease'] ?? ''));

		if ($prerelease !== '') {
			$normalized .= $prerelease;
		}

		return $normalized;
	}

	public static function compare(string $left, string $right): int
	{
		return version_compare(self::normalizeVersion($left), self::normalizeVersion($right));
	}

	public static function matches(string $version, string $constraint): bool
	{
		$normalized_version = self::normalizeVersion($version);
		$constraint = trim($constraint);

		if ($constraint === '' || $constraint === '*') {
			return true;
		}

		$parts = preg_split('/\s*,\s*|\s+/', $constraint) ?: [];
		$parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

		if ($parts === []) {
			return true;
		}

		foreach ($parts as $part) {
			if (!self::matchesSingleConstraint($normalized_version, $part)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param list<string> $versions
	 */
	public static function selectBestMatchingVersion(array $versions, ?string $constraint = null): ?string
	{
		$normalized = [];

		foreach ($versions as $version) {
			$normalized[(string) $version] = self::normalizeVersion((string) $version);
		}

		uksort($normalized, static fn (string $left, string $right): int => version_compare(
			$normalized[$right],
			$normalized[$left]
		));

		foreach (array_keys($normalized) as $version) {
			if ($constraint === null || self::matches($version, $constraint)) {
				return $version;
			}
		}

		return null;
	}

	/** @param array<int, string> $constraints */
	public static function combineConstraints(array $constraints): string
	{
		$unique = array_values(array_unique(array_filter(
			array_map(static fn (string $constraint): string => trim($constraint), $constraints),
			static fn (string $constraint): bool => $constraint !== ''
		)));
		sort($unique);

		return implode(' ', $unique);
	}

	private static function matchesSingleConstraint(string $normalized_version, string $constraint): bool
	{
		if ($constraint === '*') {
			return true;
		}

		if (str_starts_with($constraint, '^')) {
			return self::matchesRange($normalized_version, ...self::expandCaretConstraint(substr($constraint, 1)));
		}

		if (str_starts_with($constraint, '~')) {
			return self::matchesRange($normalized_version, ...self::expandTildeConstraint(substr($constraint, 1)));
		}

		if (preg_match('/^(<=|>=|<|>)(.+)$/', $constraint, $matches) === 1) {
			$operator = $matches[1];
			$other_version = self::normalizeVersion(trim($matches[2]));

			return version_compare($normalized_version, $other_version, $operator);
		}

		if (str_contains($constraint, '*') || str_contains($constraint, 'x') || str_contains($constraint, 'X')) {
			return self::matchesRange($normalized_version, ...self::expandWildcardConstraint($constraint));
		}

		return version_compare($normalized_version, self::normalizeVersion($constraint), '==');
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private static function expandCaretConstraint(string $version): array
	{
		[$major, $minor, $patch] = self::parseVersionParts($version);
		$lower = self::normalizeVersion($version);

		if ($major > 0) {
			return [$lower, ($major + 1) . '.0.0'];
		}

		if ($minor > 0) {
			return [$lower, "0." . ($minor + 1) . ".0"];
		}

		return [$lower, "0.0." . ($patch + 1)];
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private static function expandTildeConstraint(string $version): array
	{
		$parts = explode('.', trim($version));
		[$major, $minor, $patch] = self::parseVersionParts($version);
		$lower = self::normalizeVersion($version);

		if (count($parts) <= 1) {
			return [$lower, ($major + 1) . '.0.0'];
		}

		return [$lower, "{$major}." . ($minor + 1) . '.0'];
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private static function expandWildcardConstraint(string $version): array
	{
		$parts = preg_split('/\./', trim($version)) ?: [];
		$normalized_parts = [];
		$wildcard_index = null;

		foreach ($parts as $index => $part) {
			if (in_array($part, ['*', 'x', 'X'], true)) {
				$wildcard_index = $index;

				break;
			}

			if (!preg_match('/^\d+$/', $part)) {
				throw new RuntimeException("Unsupported plugin version constraint '{$version}'.");
			}

			$normalized_parts[] = (int) $part;
		}

		if ($wildcard_index === null) {
			throw new RuntimeException("Unsupported plugin version constraint '{$version}'.");
		}

		while (count($normalized_parts) < 3) {
			$normalized_parts[] = 0;
		}

		$lower = implode('.', array_slice($normalized_parts, 0, 3));

		if ($wildcard_index === 0) {
			return ['0.0.0', PHP_INT_MAX . '.0.0'];
		}

		if ($wildcard_index === 1) {
			$upper_major = $normalized_parts[0] + 1;

			return [$lower, "{$upper_major}.0.0"];
		}

		$upper_minor = $normalized_parts[1] + 1;

		return [$lower, "{$normalized_parts[0]}.{$upper_minor}.0"];
	}

	private static function matchesRange(string $version, string $lower_inclusive, string $upper_exclusive): bool
	{
		return version_compare($version, self::normalizeVersion($lower_inclusive), '>=')
			&& version_compare($version, self::normalizeVersion($upper_exclusive), '<');
	}

	/**
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function parseVersionParts(string $version): array
	{
		$normalized = self::normalizeVersion($version);
		$normalized = explode('-', $normalized, 2)[0];

		return array_map('intval', explode('.', $normalized));
	}
}
