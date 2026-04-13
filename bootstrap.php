<?php

require_once __DIR__ . '/bootstrap.package_locator.php';

$resolved_framework_root = radaptorBootstrapResolveFrameworkRoot(__DIR__);

if ($resolved_framework_root !== radaptorBootstrapNormalizePath(__DIR__)) {
	require_once $resolved_framework_root . '/bootstrap.php';

	return;
}

require_once __DIR__ . '/bootstrap.environment.php';
require_once __DIR__ . '/bootstrap.autoloader.php';
require_once __DIR__ . '/bootstrap.error_handlers.php';
require_once __DIR__ . '/bootstrap.view.helpers.php';

/**
 * Validate required runtime services and fail fast with actionable messages.
 */
if (!function_exists('assertRequiredServicesAvailable')) {
	function assertRequiredServicesAvailable(): void
	{
		try {
			Db::instance()->query('SELECT 1');
		} catch (Throwable $e) {
			Kernel::abort_unexpectedly(new RuntimeException(
				"Required database service is unavailable. Check DB_DEFAULT_DSN.",
				0,
				$e
			));
		}

		try {
			$sessionStorage = new RedisSessionStorage();
			$sessionStorage->assertAvailable();
		} catch (Throwable $e) {
			Kernel::abort_unexpectedly(new RuntimeException(
				"Required session Redis service is unavailable. Check SESSION_REDIS_HOST/SESSION_REDIS_PORT.",
				0,
				$e
			));
		}

		try {
			$cacheStorage = new PersistentCacheRedis();
			$cacheStorage->assertAvailable();
		} catch (Throwable $e) {
			Kernel::abort_unexpectedly(new RuntimeException(
				"Required cache Redis service is unavailable. Check CACHE_REDIS_HOST/CACHE_REDIS_PORT.",
				0,
				$e
			));
		}
	}
}

// Register FPM-default storage adapters (overridden in Swoole entry point)
RequestContextHolder::setStorage(new FpmRequestContextStorage());

$environment = getenv('ENVIRONMENT') ?: 'production';

// Initialize a safe fallback so error handlers can access session data even when
// assertRequiredServicesAvailable() triggers Kernel::abort_unexpectedly().
SessionContextHolder::setStorage(new NativeSessionStorage());

if ($environment !== 'test') {
	assertRequiredServicesAvailable();

	$sessionStorage = new RedisSessionStorage();
	SessionContextHolder::setStorage($sessionStorage);
}
