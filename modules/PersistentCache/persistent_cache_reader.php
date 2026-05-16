<?php

/** @noinspection PhpUndefinedFunctionInspection */
$tmp_user_id = -1;

if (isset($_SESSION['currentuser'])) {
	$tmp_userdata = unserialize($_SESSION['currentuser']);
	$tmp_user_id = $tmp_userdata['user_id'];
}

$framework_root = PackagePathHelper::getFrameworkRoot();

if (!is_string($framework_root) || !is_dir($framework_root)) {
	throw new RuntimeException('Framework package root is unavailable.');
}

require_once rtrim($framework_root, '/') . "/modules/PersistentCache/interfaces/interface.iPersistentCache.php";
require_once rtrim($framework_root, '/') . "/modules/PersistentCache/classes/class.PersistentCacheRedis.php";

define('PERSISTENT_CACHE_KEY_RESOURCETYPE_WEBPAGE', "user:{$tmp_user_id}:REQUEST_URI:{$_SERVER['REQUEST_URI']}");

$is_fragment_request = (($_GET['context'] ?? '') === 'fragment')
	|| Request::isHtmxRequest();
$is_radaptor_debug_request = DebugSession::isCacheBypassRequested();

if ($is_fragment_request) {
	RequestContextHolder::disablePersistentCacheWrite();
}

//$persistentCache = new PersistentCacheMysql($sessionHandler->getConnection());
//$persistentCache = new PersistentCacheMysql();
$persistentCache = new PersistentCacheRedis();
$persistentCache->initConnection();
//$persistentCache->initSocketConnection();

try {
	if (!$is_fragment_request && !$is_radaptor_debug_request && Request::getMethod() === 'GET') {
		$cachedPage = $persistentCache->get(PERSISTENT_CACHE_KEY_RESOURCETYPE_WEBPAGE);

		if (!is_null($cachedPage)) {
			header("x-cache-hit: PHP-redis");

			if (array_key_exists(
				'HTTP_ACCEPT_ENCODING',
				$_SERVER
			) && in_array(
				'br',
				array_map(
					trim(...),
					explode(
						',',
						(string)$_SERVER['HTTP_ACCEPT_ENCODING']
					)
				)
			)) {
				header("content-encoding: br");
				echo $cachedPage;
			} else {
				echo brotli_uncompress($cachedPage);
			}

			exit;
		}
	}
} catch (Exception) {
	// Not in cache, just assigning a variable so a debug stop can be added for testing purposes
	$success = false;
}
