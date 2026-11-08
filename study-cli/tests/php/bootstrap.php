<?php

// The backend as the server loads it (src/backend/autoload.php defines DIR_BASE as src/), the module's own classes, and the test helpers
// in this folder (StudyCli\Tests\Name is tests/php/Name.php).
require_once dirname(__DIR__, 3) . '/src/backend/autoload.php';
require_once dirname(__DIR__, 2) . '/src/autoload.php';

spl_autoload_register(function(string $class): void {
	$prefix = 'StudyCli\\Tests\\';
	if(strncmp($class, $prefix, strlen($prefix)) === 0 && is_file($file = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php'))
		require_once $file;
});
