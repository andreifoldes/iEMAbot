<?php
// Cron entry point: receive Telegram updates by long polling for ~55 s (the default
// transport; does nothing when the webhook transport is selected). Invoked once a minute
// (see Dockerfile / cron); a lock keeps it to one poller. CLI-only.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

$result = backend\channels\telegram\TelegramPoller::run(55);
// Without a bot, or while another poller runs, there is nothing worth a log line every minute.
if(!in_array($result['skipped'] ?? '', ['another poller is running', 'no bot token'], true))
	fwrite(STDOUT, date('c') . ' telegram_poll ' . json_encode($result) . "\n");
