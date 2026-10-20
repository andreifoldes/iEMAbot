<?php
// Cron entry point: open the questionnaires that became due for participants who take part
// through a chat channel (Telegram). Invoked once a minute (see Dockerfile / cron). CLI-only.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

// Skip if the previous run is still busy (many participants / slow API).
$lock = fopen(sys_get_temp_dir() . '/esmira_channels_dispatch.lock', 'c');
if($lock === false || !flock($lock, LOCK_EX | LOCK_NB))
	exit(0);

$result = backend\channels\dispatch\ChannelDispatcher::run(backend\Main::getMilliseconds());
fwrite(STDOUT, date('c') . ' channels_dispatch ' . json_encode($result) . "\n");
