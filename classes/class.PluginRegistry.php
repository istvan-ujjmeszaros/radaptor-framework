<?php

class PluginRegistry
{
	/** @var array<string, array<string, mixed>> */
	private static array $_plugins = [];

	/** @var array<string, string> */
	private static array $_tagContexts = [];

	/** @var array<string, string> */
	private static array $_commentContexts = [];

	/** @var array<string, array<string, mixed>>|null */
	private static ?array $_generatedPluginsCache = null;
	private static bool $_initialized = false;

	public static function register(AbstractPlugin $plugin): void
	{
		self::$_plugins[$plugin->getId()] = [
			'id' => $plugin->getId(),
			'base_path' => self::toRelativePath($plugin->getBasePath()),
			'descriptor_class' => get_class($plugin),
			'descriptor_file' => '',
			'tag_contexts' => array_values($plugin->getTagContexts()),
			'comment_contexts' => array_values($plugin->getCommentContexts()),
			'dependencies' => PluginPackageMetadataHelper::loadDependenciesFromSourcePath($plugin->getBasePath()),
		];

		foreach ($plugin->getTagContexts() as $context) {
			self::$_tagContexts[$context] = $plugin->getId();
		}

		foreach ($plugin->getCommentContexts() as $context) {
			self::$_commentContexts[$context] = $plugin->getId();
		}
		self::$_initialized = true;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get(string $id): ?array
	{
		self::ensureInitialized();

		return self::$_plugins[$id] ?? null;
	}

	/** @return array<string, array<string, mixed>> */
	public static function getAll(): array
	{
		self::ensureInitialized();

		return self::$_plugins;
	}

	/**
	 * Discover plugins from the plugins/dev and plugins/registry directory descriptors.
	 */
	public static function discoverAll(): void
	{
		self::$_plugins = PluginDescriptorDiscovery::discover();
		self::$_tagContexts = self::buildContextMap(self::$_plugins, 'tag_contexts');
		self::$_commentContexts = self::buildContextMap(self::$_plugins, 'comment_contexts');
		self::$_initialized = true;
	}

	/**
	 * Get all registered tag contexts across all plugins.
	 * @return array<string, string> context => pluginId
	 */
	public static function getAllTagContexts(): array
	{
		self::ensureInitialized();

		return self::$_tagContexts;
	}

	public static function hasTagContext(string $context): bool
	{
		return array_key_exists($context, self::getAllTagContexts());
	}

	public static function getTagContextPluginId(string $context): ?string
	{
		return self::getAllTagContexts()[$context] ?? null;
	}

	/**
	 * Get all registered comment contexts across all plugins.
	 * @return array<string, string> context => pluginId
	 */
	public static function getAllCommentContexts(): array
	{
		self::ensureInitialized();

		return self::$_commentContexts;
	}

	public static function hasCommentContext(string $context): bool
	{
		return array_key_exists($context, self::getAllCommentContexts());
	}

	public static function getCommentContextPluginId(string $context): ?string
	{
		return self::getAllCommentContexts()[$context] ?? null;
	}

	public static function reset(): void
	{
		self::$_plugins = [];
		self::$_tagContexts = [];
		self::$_commentContexts = [];
		self::$_initialized = false;
	}

	/**
	 * @param array<string, array<string, mixed>> $plugins
	 */
	public static function setGeneratedPluginsCache(array $plugins): void
	{
		self::$_generatedPluginsCache = $plugins;
	}

	public static function clearGeneratedPluginsCache(): void
	{
		self::$_generatedPluginsCache = null;
	}

	public static function hasGeneratedRegistry(): bool
	{
		return file_exists(DEPLOY_ROOT . ApplicationConfig::GENERATED_PLUGINS_FILE);
	}

	/** @return array<string, array<string, mixed>> */
	public static function getGeneratedPlugins(): array
	{
		if (self::$_generatedPluginsCache !== null) {
			return self::$_generatedPluginsCache;
		}

		if (!self::hasGeneratedRegistry()) {
			return [];
		}

		if (!class_exists('PluginList', false)) {
			require_once DEPLOY_ROOT . ApplicationConfig::GENERATED_PLUGINS_FILE;
		}

		if (!class_exists('PluginList', false)) {
			return [];
		}

		/** @var array<string, array<string, mixed>> $plugins */
		$plugins = PluginList::getAll();
		self::$_generatedPluginsCache = $plugins;

		return $plugins;
	}

	/** @return array<string, string> */
	public static function getGeneratedTagContexts(): array
	{
		if (!self::hasGeneratedRegistry()) {
			return [];
		}

		if (!class_exists('PluginList', false)) {
			require_once DEPLOY_ROOT . ApplicationConfig::GENERATED_PLUGINS_FILE;
		}

		if (!class_exists('PluginList', false)) {
			return [];
		}

		/** @var array<string, string> $contexts */
		$contexts = call_user_func(['PluginList', 'getTagContexts']);

		return $contexts;
	}

	/** @return array<string, string> */
	public static function getGeneratedCommentContexts(): array
	{
		if (!self::hasGeneratedRegistry()) {
			return [];
		}

		if (!class_exists('PluginList', false)) {
			require_once DEPLOY_ROOT . ApplicationConfig::GENERATED_PLUGINS_FILE;
		}

		if (!class_exists('PluginList', false)) {
			return [];
		}

		/** @var array<string, string> $contexts */
		$contexts = call_user_func(['PluginList', 'getCommentContexts']);

		return $contexts;
	}

	private static function ensureInitialized(): void
	{
		if (self::$_initialized) {
			return;
		}

		if (!self::hasGeneratedRegistry()) {
			throw new RuntimeException(
				'Generated plugin registry is missing. Run `php radaptor.php build:plugins` (or `build:all`) before using plugin-backed runtime features.'
			);
		}

		self::$_plugins = self::getGeneratedPlugins();
		self::$_tagContexts = self::getGeneratedTagContexts();
		self::$_commentContexts = self::getGeneratedCommentContexts();
		self::$_initialized = true;
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

	private static function toRelativePath(string $path): string
	{
		$normalized = str_replace('\\', '/', $path);
		$root = str_replace('\\', '/', DEPLOY_ROOT);

		if (str_starts_with($normalized, $root)) {
			$normalized = substr($normalized, strlen($root));
		}

		return ltrim($normalized, '/');
	}
}
