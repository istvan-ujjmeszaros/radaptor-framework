<?php

class SessionContextHolder
{
	private static iSessionStorage $storage;
	private static bool $initialized = false;

	public static function setStorage(iSessionStorage $storage): void
	{
		self::$storage = $storage;
		self::$initialized = true;
	}

	public static function hasStorage(): bool
	{
		return self::$initialized;
	}

	public static function current(): iSessionStorage
	{
		return self::$storage;
	}
}
