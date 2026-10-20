<?php
define('DIR_BASE', dirname(__FILE__, 2) .'/');
// Composer dependencies (e.g. minishlink/web-push) live next to this file in
// backend/vendor/ after `composer install`. Optional: absent in test setups.
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if(file_exists($composerAutoload))
	require_once $composerAutoload;
spl_autoload_register(function($class) {
	$class = str_replace('\\', '/', $class);
	if(file_exists(DIR_BASE . "$class.php"))
		include DIR_BASE . "$class.php";
});