<?php

class RequestContextHolder
{
	private static iRequestContextStorage $storage;

	public static function setStorage(iRequestContextStorage $storage): void
	{
		self::$storage = $storage;
	}

	public static function current(): RequestContext
	{
		return self::$storage->get();
	}

	/**
	 * Create a fresh context and pre-populate it with input data.
	 *
	 * In FPM: called by Kernel::initialize() with superglobals (already set by PHP before script runs).
	 * In Swoole: called by the request handler with data from $swooleRequest — superglobals are
	 * NEVER set or read; all coroutines share process memory so writing $_GET would be a race condition.
	 */
	public static function initializeRequest(
		array $get = [],
		array $post = [],
		array $server = [],
		array $cookie = []
	): void {
		self::$storage->initialize();
		$ctx = self::$storage->get();
		$ctx->GET = $get;
		$ctx->POST = $post;
		$ctx->SERVER = $server;
		$ctx->COOKIE = $cookie;
	}

	public static function disablePersistentCacheWrite(): void
	{
		self::current()->persistentCacheWriteEnabled = false;
	}

	public static function enablePersistentCacheWrite(): void
	{
		self::current()->persistentCacheWriteEnabled = true;
	}

	public static function isPersistentCacheWriteEnabled(): bool
	{
		if (!Config::APP_PERSISTENT_CACHE_ENABLED->value()) {
			return false;
		}

		return self::current()->persistentCacheWriteEnabled;
	}
}
