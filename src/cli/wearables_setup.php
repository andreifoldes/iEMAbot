<?php
// One-off server setup for wearables data sharing. Stores the *server-wide* OAuth app
// credentials for a provider (one app per provider per server) and generates the token
// encryption key on first use. CLI-only. Re-run per provider.
//
// Usage:
//   php cli/wearables_setup.php <withings|googlehealth> <client_id> <client_secret> [redirect_uri]
//   php cli/wearables_setup.php genkey [--rotate] # create the token key; --rotate replaces an existing one (every connected device must reconnect)
//   php cli/wearables_setup.php googlehealth-webhook   # secret + request body to register the Google Health subscriber
//
// Sensor-triggered prompts need webhooks. Withings URLs are registered per participant
// automatically when they link their device; Google Health needs the one-off subscriber
// request printed by the googlehealth-webhook command.
//
// The optional redirect_uri overrides the auto-derived callback URL. Whatever you
// register with the provider must match: https://<your-server>/esmira/api/wearables_oauth.php

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

use backend\Configs;
use backend\FileSystemBasics;
use backend\wearables\WearablesEventKinds;
use backend\wearables\WearablesRegistry;

$args = array_slice($argv ?? [], 1);

/** Generate a base64 libsodium secretbox key (or 32 random bytes if sodium is absent). */
function makeTokenKey(): string {
	$raw = function_exists('sodium_crypto_secretbox_keygen')
		? sodium_crypto_secretbox_keygen()
		: random_bytes(32);
	return base64_encode($raw);
}

$config = [];
$hadKey = !empty(Configs::get('wearables_token_key'));
if(!$hadKey)
	$config['wearables_token_key'] = makeTokenKey();

if(($args[0] ?? '') === 'genkey') {
	if($hadKey && !in_array('--rotate', $args, true)) {
		fwrite(STDERR, "A token key already exists. Replacing it makes every stored wearable token unreadable: all participants would have to connect their devices again.\nRun 'php cli/wearables_setup.php genkey --rotate' if that is what you want.\n");
		exit(1);
	}
	$config['wearables_token_key'] = makeTokenKey(); // new key, or rotate on request
	FileSystemBasics::writeServerConfigs($config);
	fwrite(STDOUT, "Generated wearables token key.\n");
	if(!function_exists('sodium_crypto_secretbox'))
		fwrite(STDOUT, "WARNING: libsodium is not available, so wearable tokens cannot be stored at all.\n");
	exit(0);
}

if(($args[0] ?? '') === 'googlehealth-webhook') {
	$secret = (string) Configs::get('wearables_googlehealth_webhook_secret');
	if($secret === '') {
		$secret = 'Bearer ' . bin2hex(random_bytes(24));
		FileSystemBasics::writeServerConfigs(['wearables_googlehealth_webhook_secret' => $secret]);
	}
	$body = [
		'endpointUri' => WearablesRegistry::webhookUri('googlehealth'),
		'endpointAuthorization' => ['secret' => $secret],
		'subscriberConfigs' => [[
			'dataTypes' => array_merge(...array_values(WearablesEventKinds::GOOGLEHEALTH_DATA_TYPES)),
			'subscriptionCreatePolicy' => 'AUTOMATIC',
		]],
	];
	fwrite(STDOUT, "Register ONE Google Health subscriber for this server (needs a Google Cloud project with the Google\n");
	fwrite(STDOUT, "Health API enabled, and IAM permission health.subscribers.create):\n\n");
	fwrite(STDOUT, "  POST https://health.googleapis.com/v4beta/projects/<PROJECT_NUMBER>/subscribers?subscriberId=esmira\n");
	fwrite(STDOUT, "  (use the project NUMBER, not the project id)\n\n");
	fwrite(STDOUT, json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n");
	fwrite(STDOUT, "The endpoint must be publicly reachable over HTTPS (TLS 1.2+). Google verifies it during registration.\n");
	exit(0);
}

if(count($args) < 3) {
	fwrite(STDERR, "Usage: php cli/wearables_setup.php <withings|googlehealth> <client_id> <client_secret> [redirect_uri]\n");
	fwrite(STDERR, "       php cli/wearables_setup.php genkey\n");
	exit(1);
}

[$provider, $clientId, $clientSecret] = $args;
$provider = strtolower($provider);
if(!WearablesRegistry::isKnown($provider)) {
	fwrite(STDERR, "Unknown provider '$provider'. Supported: " . implode(', ', array_keys(WearablesRegistry::PROVIDERS)) . "\n");
	exit(1);
}

$config["wearables_{$provider}_client_id"]     = $clientId;
$config["wearables_{$provider}_client_secret"] = $clientSecret;
if(isset($args[3]) && $args[3] !== '')
	$config['wearables_redirect_uri'] = $args[3];

FileSystemBasics::writeServerConfigs($config);

fwrite(STDOUT, "Configured wearables provider: $provider\n");
if(isset($config['wearables_token_key']))
	fwrite(STDOUT, "Generated wearables token key (encrypts stored tokens at rest).\n");
if(!function_exists('sodium_crypto_secretbox'))
	fwrite(STDOUT, "WARNING: libsodium not available — tokens will be stored in plaintext.\n");
$redirect = $config['wearables_redirect_uri'] ?? WearablesRegistry::redirectUri();
fwrite(STDOUT, "Register this redirect URI with $provider's developer console:\n  $redirect\n");
