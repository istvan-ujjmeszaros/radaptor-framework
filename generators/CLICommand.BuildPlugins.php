<?php

class CLICommandBuildPlugins extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Build plugins';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Regenerate the plugin registry.

			Usage: radaptor build:plugins

			Discovers installed plugins and generates the plugin list cache
			with tag and comment context mappings.
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}
	public function getRiskLevel(): string
	{
		return 'build';
	}

	public function run(): void
	{
		self::create();
	}

	public static function create(): void
	{
		$plugins = file_exists(PluginLockfile::getPath())
			? PluginDescriptorDiscovery::discoverInstalled()
			: PluginDescriptorDiscovery::discover();
		$tag_contexts = self::buildContextMap($plugins, 'tag_contexts');
		$comment_contexts = self::buildContextMap($plugins, 'comment_contexts');

		$cache_content = GeneratorHelper::fetchTemplate(
			'build_plugins',
			[
				'plugins_export' => GeneratorHelper::formatArrayForExport($plugins),
				'tag_contexts_export' => GeneratorHelper::formatArrayForExport($tag_contexts),
				'comment_contexts_export' => GeneratorHelper::formatArrayForExport($comment_contexts),
			]
		);

		GeneratorHelper::writeGeneratedFile(
			DEPLOY_ROOT . ApplicationConfig::GENERATED_PLUGINS_FILE,
			$cache_content
		);

		PluginRegistry::setGeneratedPluginsCache($plugins);
	}

	/**
	 * @param array<string, array<string, mixed>> $plugins
	 * @return array<string, string>
	 */
	private static function buildContextMap(array $plugins, string $field): array
	{
		$contexts = [];

		foreach ($plugins as $plugin_id => $plugin) {
			foreach (($plugin[$field] ?? []) as $context) {
				$contexts[(string) $context] = $plugin_id;
			}
		}

		ksort($contexts);

		return $contexts;
	}
}
