<?php

// TODO: use nette class generator

if (!headers_sent()) {
	header('Content-Type: text/html; charset=UTF-8');
}

echo "<pre>\config_generator...\n";
GeneratorHelper::flushOutput();

require_once DEPLOY_ROOT . 'config/ApplicationConfig.php';
require_once dirname(__DIR__) . '/bootstrap.package_locator.php';

$resolved_framework_root = radaptorBootstrapResolveFrameworkRoot(dirname(__DIR__));

define('PATH_KERNEL_CLASS', $resolved_framework_root . '/classes/class.Kernel.php');
define('PATH_HELPERS_CLASS', $resolved_framework_root . '/classes/class.Helpers.php');
define('PATH_DB_CLASS', $resolved_framework_root . '/classes/class.Db.php');

define('PATH_BOOTSTRAP_AUTOLOADER', $resolved_framework_root . '/bootstrap.autoloader.php');
