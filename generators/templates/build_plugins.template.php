<?php
/** @var string $plugins_export */
/** @var string $tag_contexts_export */
/** @var string $comment_contexts_export */
?>
class PluginList
{
	private static array $_plugins = <?= $plugins_export ?>;
	private static array $_tagContexts = <?= $tag_contexts_export ?>;
	private static array $_commentContexts = <?= $comment_contexts_export ?>;

	/** @return array<string, array<string, mixed>> */
	public static function getAll(): array
	{
		return self::$_plugins;
	}

	/** @return array<string, mixed>|null */
	public static function get(string $id): ?array
	{
		return self::$_plugins[$id] ?? null;
	}

	/** @return array<string, string> */
	public static function getTagContexts(): array
	{
		return self::$_tagContexts;
	}

	/** @return array<string, string> */
	public static function getCommentContexts(): array
	{
		return self::$_commentContexts;
	}
}
