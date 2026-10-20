<?php
declare(strict_types=1);

namespace backend\channels\telegram;

use backend\channels\ChannelException;
use backend\channels\conversation\ConversationService;
use backend\channels\store\JsonFile;
use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\Main;

/**
 * Long-polling transport (the default): receives updates with getUpdates, so the bot
 * works even when Telegram cannot reach this server (internal TLS certificate, firewall).
 * Run by cron every minute (cli/telegram_poll.php); each run polls for ~55 s and an
 * flock guarantees a single poller. The offset is saved before an update is handled, so a
 * crash never replays it.
 */
class TelegramPoller {
	public static function run(int $maxSeconds = 55): array {
		$channel = new TelegramChannel();
		if(!$channel->isConfigured())
			return ['skipped' => 'no bot token'];
		if(Configs::get('telegram_transport') === 'webhook')
			return ['skipped' => 'webhook transport'];

		$lockPath = PathsFS::folderChannel(TelegramChannel::ID) . 'poll.lock';
		JsonFile::ensureFolder(dirname($lockPath));
		$lock = fopen($lockPath, 'c');
		if($lock === false || !flock($lock, LOCK_EX | LOCK_NB))
			return ['skipped' => 'another poller is running'];

		$service = new ConversationService($channel);
		$handled = 0;
		$deadline = time() + $maxSeconds;
		try {
			while(($remaining = $deadline - time()) > 2) {
				$transport = JsonFile::read(PathsFS::fileChannelTransport(TelegramChannel::ID)) ?? [];
				$offset = (int) ($transport['offset'] ?? 0);
				$wait = min(25, $remaining - 2);
				try {
					$updates = $channel->client()->call('getUpdates', [
						'offset' => $offset, 'timeout' => $wait, 'allowed_updates' => ['message', 'callback_query'],
					], $wait + 10);
				}
				catch(ChannelException $e) {
					$transport['lastError'] = $e->getMessage();
					$transport['lastPollMs'] = Main::getMilliseconds();
					JsonFile::write(PathsFS::fileChannelTransport(TelegramChannel::ID), $transport);
					sleep(min(max(3, $e->retryAfterSec), max(1, $deadline - time())));
					continue;
				}
				$transport['lastPollMs'] = Main::getMilliseconds();
				unset($transport['lastError']);
				foreach((array) $updates as $update) {
					$transport['offset'] = (int) $update['update_id'] + 1;
					JsonFile::write(PathsFS::fileChannelTransport(TelegramChannel::ID), $transport);
					foreach(TelegramUpdateParser::parse($update) as $event) {
						$service->handleEvent($event, Main::getMilliseconds());
						$handled++;
					}
				}
				JsonFile::write(PathsFS::fileChannelTransport(TelegramChannel::ID), $transport);
			}
		}
		finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
		return ['handled' => $handled];
	}
}
