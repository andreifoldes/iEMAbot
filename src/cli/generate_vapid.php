<?php
// One-off server setup: generate and persist a VAPID keypair for web push.
// Run once per server: `php cli/generate_vapid.php` (add --force to rotate).
// The public key is then served to the PWA via api/studies.php. CLI-only.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

use backend\Configs;
use backend\FileSystemBasics;
use Minishlink\WebPush\VAPID;

$force = in_array('--force', $argv ?? [], true);
$existing = Configs::get('vapid_public_key');
if(!empty($existing) && !$force) {
	fwrite(STDOUT, "VAPID already configured. Public key:\n$existing\n(use --force to rotate — existing subscriptions will stop working)\n");
	exit(0);
}

$keys = VAPID::createVapidKeys(); // ['publicKey' => base64url, 'privateKey' => base64url]
$config = [
	'vapid_public_key'  => $keys['publicKey'],
	'vapid_private_key' => $keys['privateKey'],
];
if(empty(Configs::get('vapid_subject')))
	$config['vapid_subject'] = 'mailto:noreply@esmira';

FileSystemBasics::writeServerConfigs($config);
fwrite(STDOUT, "Generated VAPID keys. Public key:\n" . $keys['publicKey'] . "\n");
