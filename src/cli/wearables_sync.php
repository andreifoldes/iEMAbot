<?php
// Cron entry point: pull newly-completed days of wearable data for every connected
// participant. Invoked hourly (see Dockerfile / cron). CLI-only.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

$result = backend\wearables\WearablesDataSync::run();
fwrite(STDOUT, date('c') . ' wearables_sync ' . json_encode($result) . "\n");
