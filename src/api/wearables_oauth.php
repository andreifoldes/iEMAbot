<?php

use backend\Main;
use backend\wearables\WearablesOAuthState;
use backend\wearables\WearablesRegistry;
use backend\wearables\WearablesTokenStore;
use backend\wearables\WearablesWebhook;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

// OAuth redirect URI — registered ONCE per provider per ESMira server. The provider
// sends the participant's browser here with `code` + `state` after consent. We exchange
// the code for tokens, store them under the study, and bounce the browser back to the
// PWA. This is a top-level navigation, so it returns a redirect (not JSON).

/** Redirect the browser back to the PWA with a status flag and stop. */
function backToPwa(string $provider, string $status): void {
	$provider = preg_replace('/[^a-z0-9_]/', '', strtolower($provider));
	// The participant PWA is served at the site root /pwa/ (overridable via the
	// wearables_pwa_path server config). Absolute, so it resolves regardless of the
	// callback's own path under /esmira/api/.
	$pwaPath = backend\Configs::get('wearables_pwa_path');
	$pwaPath = $pwaPath !== '' ? rtrim($pwaPath, '/') : '/pwa';
	$target = "$pwaPath/?wearable=$provider&status=$status";
	Main::setHeader('Location: ' . $target);
	if(PHP_SAPI !== 'cli')
		http_response_code(302);
	echo '<!doctype html><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES) . '">'
		. '<a href="' . htmlspecialchars($target, ENT_QUOTES) . '">Continue</a>';
}

if(isset($_GET['error'])) { // user denied or provider error
	backToPwa((string) ($_GET['provider'] ?? ''), 'error');
	return;
}

$code  = isset($_GET['code'])  ? (string) $_GET['code']  : '';
$state = isset($_GET['state']) ? (string) $_GET['state'] : '';
if($code === '' || $state === '') {
	backToPwa('', 'error');
	return;
}

$stateData = WearablesOAuthState::consume($state);
if($stateData === null) {
	backToPwa('', 'error');
	return;
}

$studyId  = (int) ($stateData['studyId'] ?? 0);
$userId   = (string) ($stateData['userId'] ?? '');
$provider = (string) ($stateData['provider'] ?? '');

try {
	$providerObj = WearablesRegistry::get($provider);
	if($providerObj === null) {
		backToPwa($provider, 'error');
		return;
	}
	$token = $providerObj->exchangeCode($code, WearablesRegistry::redirectUri());
	$token['created'] = Main::getMilliseconds();
	WearablesTokenStore::save($studyId, $userId, $provider, $token);
	WearablesWebhook::subscribe($provider, $token); // sensor-triggered prompts; best effort
	backToPwa($provider, 'connected');
}
catch(Throwable $e) {
	Main::reportError($e, 'Wearables OAuth callback failed:');
	backToPwa($provider, 'error');
}
