<?php

require_once __DIR__ . '/bootstrap.package_locator.php';

$resolved_framework_root = radaptorBootstrapResolveFrameworkRoot(__DIR__);

if ($resolved_framework_root !== radaptorBootstrapNormalizePath(__DIR__)) {
	require_once $resolved_framework_root . '/bootstrap.testing.php';

	return;
}

// Enforce 'test' environment for PHPStan and PHPUnit
putenv('ENVIRONMENT=test');

require_once __DIR__ . '/bootstrap.php';

// Tests run with native PHP sessions to avoid external Redis dependency in unit/integration execution.
SessionContextHolder::setStorage(new NativeSessionStorage());
$bootstrap = TestDatabaseSchemaSyncService::bootstrap();

if ($bootstrap['schema_rebuilt']) {
	fwrite(STDERR, "[Test Bootstrap] Schema mismatch detected, synced test databases from dev and reloaded fixtures.\n");
} elseif ($bootstrap['fixtures_loaded']) {
	fwrite(STDERR, "[Test Bootstrap] Fixtures missing, loading...\n");
	fwrite(STDERR, "[Test Bootstrap] Fixtures loaded.\n");
}
