<?php

class PluginIdHelper
{
	public static function normalize(mixed $plugin_id, string $context = 'Plugin'): string
	{
		$plugin_id = trim((string) $plugin_id);

		if ($plugin_id === '') {
			throw new RuntimeException("{$context} is missing a plugin_id.");
		}

		if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $plugin_id) !== 1) {
			throw new RuntimeException(
				"{$context} '{$plugin_id}' is invalid. Plugin IDs must match ^[a-z0-9][a-z0-9_-]*$."
			);
		}

		return $plugin_id;
	}
}
