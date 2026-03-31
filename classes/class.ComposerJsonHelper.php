<?php

class ComposerJsonHelper
{
	public static function getPath(): string
	{
		return DEPLOY_ROOT . 'composer.json';
	}

	/**
	 * @return array{
	 *     data: array<string, mixed>,
	 *     require: array<string, string>,
	 *     path: string
	 * }
	 */
	public static function load(): array
	{
		return self::loadFromPath(self::getPath());
	}

	/**
	 * @return array{
	 *     data: array<string, mixed>,
	 *     require: array<string, string>,
	 *     path: string
	 * }
	 */
	public static function loadFromPath(string $path): array
	{
		if (!file_exists($path)) {
			throw new RuntimeException("Composer file not found: {$path}");
		}

		$json = file_get_contents($path);

		if ($json === false) {
			throw new RuntimeException("Unable to read Composer file: {$path}");
		}

		try {
			$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException("Invalid JSON in {$path}: " . $e->getMessage(), 0, $e);
		}

		if (!is_array($data)) {
			throw new RuntimeException("Composer JSON root must be an object: {$path}");
		}

		return [
			'data' => $data,
			'require' => self::normalizeRequire($data['require'] ?? [], $path),
			'path' => $path,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function write(array $data, ?string $path = null): void
	{
		$path ??= self::getPath();
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$result = file_put_contents($path, $json . "\n");

		if ($result === false) {
			throw new RuntimeException("Unable to write Composer file: {$path}");
		}
	}

	/**
	 * @param array<string, mixed> $document
	 * @param array<string, string> $require
	 * @return array<string, mixed>
	 */
	public static function withRequire(array $document, array $require): array
	{
		$existing = is_array($document['require'] ?? null) ? $document['require'] : [];
		$ordered = [];

		foreach ($existing as $package => $constraint) {
			if (!is_string($package) || !array_key_exists($package, $require)) {
				continue;
			}

			$ordered[$package] = $require[$package];
		}

		$new_packages = array_diff_key($require, $ordered);
		ksort($new_packages);

		foreach ($new_packages as $package => $constraint) {
			$ordered[$package] = $constraint;
		}

		$document['require'] = $ordered;

		return $document;
	}

	/**
	 * @return array<string, string>
	 */
	public static function normalizeRequire(mixed $require, string $context = 'composer.json require'): array
	{
		return PluginDependencyHelper::normalizeDependencies($require, "{$context}");
	}
}
