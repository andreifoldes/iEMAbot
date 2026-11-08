<?php

declare(strict_types=1);

// Loads the module's classes: StudyCli\Name is study-cli/src/Name.php. The module needs the backend's autoloader (backend/autoload.php)
// loaded first, for the classes it calls; the entry points in ../bin do both.
spl_autoload_register(function(string $class): void {
	$prefix = 'StudyCli\\';
	if(strncmp($class, $prefix, strlen($prefix)) !== 0)
		return;
	$file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if(is_file($file))
		require_once $file;
});
