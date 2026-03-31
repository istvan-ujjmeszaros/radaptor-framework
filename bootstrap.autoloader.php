<?php

require_once __DIR__ . '/bootstrap.package_locator.php';

$resolved_framework_root = radaptorBootstrapResolveFrameworkRoot(__DIR__);

if ($resolved_framework_root !== radaptorBootstrapNormalizePath(__DIR__)) {
	require_once $resolved_framework_root . '/bootstrap.autoloader.php';

	return;
}

require_once $resolved_framework_root . '/classes/class.PackagePathHelper.php';

// Just a fail-safe setting, all require and include call should use an absolute path with DEPLOY_ROOT
set_include_path(DEPLOY_ROOT);

/* NO_ONEFILER_BEGIN */

// Autoloader using generated class map
require_once DEPLOY_ROOT . 'radaptor/autoloader_from_generated_map.php';
AutoloaderFromGeneratedMap::register_mapped_autoloader();

require_once DEPLOY_ROOT . 'vendor/autoload.php';

// Autoloader parsing all project files (slow, but should discover any existing class - btw not that slow, about 50ms on WSL2)
require_once DEPLOY_ROOT . 'radaptor/autoloader_failsafe.php';
AutoloaderFailsafe::register_failsafe_autoloader();

/**
 * Checks if the XDEBUG_PROFILE parameter is set in the request (GET, POST, or COOKIE).
 * If it is set, it preloads all classes using the AutoloadHandler::preload_all() method.
 *
 * This is done to avoid polluting the generated cachegrind files with autoloader calls,
 * as preloading every file on every request would degrade performance. Instead, an
 * individual opcache preloader is needed for production environments.
 */
if (isset($_GET['XDEBUG_PROFILE']) || isset($_POST['XDEBUG_PROFILE']) || isset($_COOKIE['XDEBUG_PROFILE'])) {
	AutoloaderFromGeneratedMap::preloadAll();
}
/* NO_ONEFILER_END */
