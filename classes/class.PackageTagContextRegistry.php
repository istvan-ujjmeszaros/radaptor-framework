<?php

class PackageTagContextRegistry
{
	/**
	 * @return array<string, array{
	 *     package_key: string,
	 *     package_id: string,
	 *     local_context: string,
	 *     label: string|null
	 * }>
	 */
	public static function getAll(): array
	{
		if (!is_file(PackageLockfile::getPath())) {
			return [];
		}

		$lock = PackageLockfile::load();
		$contexts = [];

		foreach ($lock['packages'] as $package_key => $package) {
			$id = PackageTypeHelper::normalizeId($package['id'] ?? null, "Tag context package '{$package_key}'");
			$tag_contexts = self::getPackageTagContexts($package);

			foreach ($tag_contexts as $local_context => $context_data) {
				$context = self::buildContext($id, $local_context);
				$declared_context = trim((string) ($context_data['context'] ?? ''));

				if ($declared_context !== '' && $declared_context !== $context) {
					throw new RuntimeException(
						"Package '{$package_key}' tag context '{$local_context}' resolves to '{$declared_context}', expected '{$context}'."
					);
				}

				if (isset($contexts[$context])) {
					throw new RuntimeException(
						"Duplicate tag context '{$context}' is declared by '{$contexts[$context]['package_key']}' and '{$package_key}'."
					);
				}

				$label = $context_data['label'] ?? null;
				$contexts[$context] = [
					'package_key' => (string) $package_key,
					'package_id' => $id,
					'local_context' => (string) $local_context,
					'label' => is_string($label) && trim($label) !== '' ? trim($label) : null,
				];
			}
		}

		ksort($contexts);

		return $contexts;
	}

	public static function has(string $context): bool
	{
		return array_key_exists(trim($context), self::getAll());
	}

	public static function getOwnerPackageKey(string $context): ?string
	{
		return self::getAll()[trim($context)]['package_key'] ?? null;
	}

	public static function buildContext(string $package_id, string $local_context): string
	{
		$package_id = PackageTypeHelper::normalizeId($package_id, 'Tag context package');
		$local_context = PackageTypeHelper::normalizeId($local_context, 'Tag context');
		$context = $package_id . '_' . $local_context;

		if (strlen($context) > 64) {
			throw new RuntimeException("Tag context '{$context}' exceeds 64 characters.");
		}

		return $context;
	}

	/**
	 * @param array<string, mixed> $package
	 * @return array<string, array{context: string, label: string|null}>
	 */
	private static function getPackageTagContexts(array $package): array
	{
		$tag_contexts = $package['tag_contexts'] ?? null;

		if (is_array($tag_contexts)) {
			return PackageMetadataHelper::normalizeTagContextsMetadata(
				$tag_contexts,
				"locked package '{$package['type']}:{$package['id']}'",
				(string) ($package['package'] ?? "{$package['type']}:{$package['id']}"),
				(string) ($package['id'] ?? '')
			);
		}

		$type = PackageTypeHelper::normalizeType($package['type'] ?? null, 'Tag context package');
		$id = PackageTypeHelper::normalizeId($package['id'] ?? null, 'Tag context package');
		$root = PackagePathHelper::getPackageRoot($type, $id);

		if (!is_string($root) || !is_dir($root) || !is_file(rtrim($root, '/') . '/.registry-package.json')) {
			return [];
		}

		return PackageMetadataHelper::loadFromSourcePath($root)['tag_contexts'];
	}
}
