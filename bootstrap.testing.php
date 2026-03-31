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

// Test database schema verification and sync
// Classes are autoloaded by the failsafe autoloader from tests/helpers/

// Safety check: ensure we're connected to test database
TestDatabaseGuard::assertTestDatabase();

// Compare schema between dev and test databases
$schemaErrors = TestDatabaseGuard::getSchemaErrors();

if (!empty($schemaErrors)) {
	// Schema mismatch detected - recreate from dev and load fixtures
	fwrite(STDERR, "[Test Bootstrap] Schema mismatch detected, syncing from dev database...\n");

	TestDatabaseGuard::recreateSchema();
	Fixtures::loadAll();

	fwrite(STDERR, "[Test Bootstrap] Schema synced and fixtures loaded.\n");
} else {
	// Schema matches - check if fixtures are present
	$stmt = Db::instance()->query('SELECT COUNT(*) FROM users');
	$userCount = (int) $stmt->fetchColumn();

	if ($userCount === 0) {
		fwrite(STDERR, "[Test Bootstrap] Fixtures missing, loading...\n");
		Fixtures::loadAll();
		fwrite(STDERR, "[Test Bootstrap] Fixtures loaded.\n");
	}
}
