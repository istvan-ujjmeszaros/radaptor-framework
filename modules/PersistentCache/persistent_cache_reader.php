<?php

/** @noinspection PhpUndefinedFunctionInspection */
$tmp_user_id = -1;

if (isset($_SESSION['currentuser'])) {
	$tmp_userdata = unserialize($_SESSION['currentuser']);
	$tmp_user_id = $tmp_userdata['user_id'];
}

$framework_root = PackagePathHelper::getFrameworkRoot() ?? (DEPLOY_ROOT . 'radaptor/radaptor-framework');
require_once rtrim($framework_root, '/') . "/modules/PersistentCache/interfaces/interface.iPersistentCache.php";
require_once rtrim($framework_root, '/') . "/modules/PersistentCache/classes/class.PersistentCacheRedis.php";

define('PERSISTENT_CACHE_KEY_RESOURCETYPE_WEBPAGE', "user:{$tmp_user_id}:REQUEST_URI:{$_SERVER['REQUEST_URI']}");

//$persistentCache = new PersistentCacheMysql($sessionHandler->getConnection());
//$persistentCache = new PersistentCacheMysql();
$persistentCache = new PersistentCacheRedis();
$persistentCache->initConnection();
//$persistentCache->initSocketConnection();

try {
	if ($_SERVER['REQUEST_METHOD'] === 'GET') {
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
