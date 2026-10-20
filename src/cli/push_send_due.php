<?php
// Cron entry point: send any web-push reminders that are now due.
// Invoked once a minute (see Dockerfile / cron). CLI-only.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

$result = backend\notifications\PushSender::run();
fwrite(STDOUT, date('c') . ' push_send_due ' . json_encode($result) . "\n");
