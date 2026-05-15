<?php

class PluginConfigHelper
{
	/**
	 * @return array<string, mixed>
	 */
	public static function load(string $plugin_id, string $plugin_base_path): array
	{
		return PackageConfig::load('plugin', $plugin_id, $plugin_base_path);
	}
}
