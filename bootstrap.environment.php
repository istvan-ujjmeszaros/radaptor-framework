<?php

require_once __DIR__ . '/bootstrap.package_locator.php';

$resolved_framework_root = radaptorBootstrapResolveFrameworkRoot(__DIR__);

if ($resolved_framework_root !== radaptorBootstrapNormalizePath(__DIR__)) {
	require_once $resolved_framework_root . '/bootstrap.environment.php';

	return;
}

define('DEPLOY_ROOT', rtrim(getenv('RADAPTOR_APP_ROOT'), '/') . '/');

// telling php not to send any cache related headers, because we
// are controlling the cache headers in ResourceHandler manually
session_cache_limiter('');

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');
