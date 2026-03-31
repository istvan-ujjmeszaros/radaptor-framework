<?php

class PackageTypeHelper
{
	/** @var array<string, string> */
	private const array SECTION_TO_TYPE = [
		'core' => 'core',
		'themes' => 'theme',
		'plugins' => 'plugin',
	];

	/** @var array<string, string> */
	private const array TYPE_TO_SECTION = [
		'core' => 'core',
		'theme' => 'themes',
		'plugin' => 'plugins',
	];

	public static function normalizeId(mixed $id, string $context = 'Package'): string
	{
		$id = trim((string) $id);

		if ($id === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id) !== 1) {
			throw new RuntimeException("{$context} ID '{$id}' is invalid. Use lowercase letters, numbers, underscores, and hyphens.");
		}

		return $id;
	}

	public static function normalizeType(mixed $type, string $context = 'Package'): string
	{
		$type = trim((string) $type);

		if (isset(self::TYPE_TO_SECTION[$type])) {
			return $type;
		}

		throw new RuntimeException("{$context} type '{$type}' is unsupported.");
	}

	public static function normalizeSection(mixed $section, string $context = 'Package'): string
	{
		$section = trim((string) $section);

		if (isset(self::SECTION_TO_TYPE[$section])) {
			return $section;
		}

		throw new RuntimeException("{$context} section '{$section}' is unsupported.");
	}

	public static function getTypeForSection(string $section): string
	{
		$section = self::normalizeSection($section);

		return self::SECTION_TO_TYPE[$section];
	}

	public static function getSectionForType(string $type): string
	{
		$type = self::normalizeType($type);

		return self::TYPE_TO_SECTION[$type];
	}

	public static function getDefaultPath(string $type, string $source_type, string $id): string
	{
		$type = self::normalizeType($type);
		$id = self::normalizeId($id);
		$source_type = trim($source_type);

		if (!in_array($source_type, ['dev', 'registry'], true)) {
			throw new RuntimeException("Package '{$type}:{$id}' uses unsupported source type '{$source_type}'.");
		}

		return match ($type) {
			'core' => "core/{$source_type}/{$id}",
			'theme' => "themes/{$source_type}/{$id}",
			'plugin' => "plugins/{$source_type}/{$id}",
			default => throw new RuntimeException("Package '{$type}:{$id}' uses unsupported type '{$type}'."),
		};
	}

	public static function getKey(string $type, string $id): string
	{
		return self::normalizeType($type) . ':' . self::normalizeId($id);
	}
}
