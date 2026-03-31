<?php
/** @var string $class_index_export */
?>
class AutoloaderGeneratedMap
{
	/** @var array<class-string, string> */
	private static array $_autoload_map = <?= $class_index_export ?>;

	/** @return array<class-string, string> */
	public static function getAutoloadMap(): array
	{
		return self::$_autoload_map;
	}
}
