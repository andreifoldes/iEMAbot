<?php
// Configure the server-wide Telegram bot. CLI-only; run as www-data inside the container:
//   php cli/telegram_setup.php set-token            (reads the token from stdin, not argv)
//   php cli/telegram_setup.php status
//   php cli/telegram_setup.php polling               (default transport)
//   php cli/telegram_setup.php webhook <https-url>   (…/esmira/api/channel_webhook.php?channel=telegram)
//   php cli/telegram_setup.php base-url <https-url>  (public …/esmira/ URL for media + task links)
//   php cli/telegram_setup.php invite <studyId> [accessKey] [pid]
//   php cli/telegram_setup.php remove-token
// The same settings are available to admins under Server settings → Messaging channels.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

use backend\channels\store\InviteStore;
use backend\channels\telegram\TelegramChannel;
use backend\channels\telegram\TelegramSetup;

$cmd = $argv[1] ?? 'status';
try {
	switch($cmd) {
		case 'set-token':
			fwrite(STDOUT, "Paste the bot token from @BotFather and press Enter:\n");
			$token = trim((string) fgets(STDIN));
			$me = TelegramSetup::setToken($token);
			fwrite(STDOUT, "Bot @{$me['username']} ({$me['name']}) configured, transport: polling.\n");
			break;
		case 'remove-token':
			TelegramSetup::removeToken();
			fwrite(STDOUT, "Bot token removed.\n");
			break;
		case 'polling':
			TelegramSetup::usePolling();
			fwrite(STDOUT, "Transport: polling (cli/telegram_poll.php).\n");
			break;
		case 'webhook':
			TelegramSetup::useWebhook((string) ($argv[2] ?? ''));
			fwrite(STDOUT, "Transport: webhook.\n");
			break;
		case 'base-url':
			TelegramSetup::setPublicBaseUrl((string) ($argv[2] ?? ''));
			fwrite(STDOUT, "Public base URL saved.\n");
			break;
		case 'invite':
			$payload = InviteStore::payloadFor((int) ($argv[2] ?? 0), (string) ($argv[3] ?? ''), (string) ($argv[4] ?? ''));
			fwrite(STDOUT, (new TelegramChannel())->startLink($payload) . "\n");
			break;
		case 'status':
		default:
			fwrite(STDOUT, json_encode(TelegramSetup::status(true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	}
}
catch(Throwable $e) {
	fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
	exit(1);
}
