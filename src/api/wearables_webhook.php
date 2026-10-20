<?php

use backend\Configs;
use backend\Main;
use backend\wearables\GoogleHealthWebhook;
use backend\wearables\WearablesRegistry;
use backend\wearables\WearablesWebhook;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

// Server-to-server callback for wearable "new data" webhooks (Withings Notify, Google
// Health). Called by the provider, never by a browser, so it returns bare HTTP
// statuses instead of JSON. Providers retry on non-2xx and may disable the endpoint
// after repeated failures, so anything that is merely uninteresting answers 200.
//
//   withings: ?provider=withings&k=<webhook key>   HEAD/GET = URL check, POST = notification
//   googlehealth: ?provider=googlehealth           POST only: {"type":"verification"} probes and
//                                                  notifications; secret in the Authorization header

/** Plain status reply. */
function respond(int $status): void {
	if(PHP_SAPI !== 'cli')
		http_response_code($status);
}

if(!Configs::getDataStore()->isReady()) {
	respond(503);
	return;
}

$provider = (string) ($_GET['provider'] ?? '');
if(!in_array($provider, ['withings', 'googlehealth'], true) || WearablesRegistry::credentials($provider) === null) {
	respond(404);
	return;
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$now = Main::getMilliseconds();

if($provider === 'withings') {
	$key = WearablesRegistry::webhookKey('withings');
	if($key === null || !hash_equals($key, (string) ($_GET['k'] ?? ''))) {
		respond(403);
		return;
	}
	if($method === 'POST')
		WearablesWebhook::handleWithings($_POST, $now);
	respond(200);
	return;
}

if($provider === 'googlehealth') {
	// Apache may hide the Authorization header from PHP; honour the usual rewrite fallbacks.
	$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
	respond(GoogleHealthWebhook::handleRequest(
		$method, Main::getRawPostInput(), $auth, (string) ($_SERVER['HTTP_GOOGLE_HEALTH_API_SIGNATURE'] ?? ''), $now
	));
	return;
}
