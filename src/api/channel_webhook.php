<?php
// Webhook transport for chat channels (optional; polling is the default):
//   POST api/channel_webhook.php?channel=telegram
// Telegram signs each call with the secret registered by TelegramSetup::useWebhook in the
// X-Telegram-Bot-Api-Secret-Token header. Always answers 200 once verified, so Telegram
// does not retry updates that we failed to handle.

ignore_user_abort(true);
set_time_limit(60);

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

use backend\channels\ChannelRegistry;
use backend\channels\conversation\ConversationService;
use backend\Configs;
use backend\Main;

if($_SERVER['REQUEST_METHOD'] !== 'POST' || !Configs::getDataStore()->isReady()) {
	http_response_code(405);
	return;
}
$channel = ChannelRegistry::get((string) ($_GET['channel'] ?? ''));
if($channel === null || !$channel->isConfigured()) {
	http_response_code(404);
	return;
}

$body = Main::getRawPostInput();
$headers = [];
foreach($_SERVER as $key => $value) {
	if(strncmp($key, 'HTTP_', 5) === 0)
		$headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
}
if(!$channel->verifyInbound($headers, $body)) {
	http_response_code(401);
	return;
}

// Answer before handling: replies are paced to reading speed (ReadingPace), so a burst of
// messages can outlast Telegram's patience and it would deliver the update again.
http_response_code(200);
if(function_exists('fastcgi_finish_request')) {
	echo 'ok';
	fastcgi_finish_request();
}
else { // mod_php: tell the client the response is complete, then keep working (ignore_user_abort)
	header('Content-Length: 2');
	header('Connection: close');
	echo 'ok';
	while(ob_get_level() > 0)
		ob_end_flush();
	flush();
}

$service = new ConversationService($channel);
foreach($channel->parseInbound($body) as $event)
	$service->handleEvent($event, Main::getMilliseconds());
