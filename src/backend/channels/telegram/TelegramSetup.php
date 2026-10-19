<?php
declare(strict_types=1);

namespace backend\channels\telegram;

use backend\channels\ChannelException;
use backend\channels\SecretBox;
use backend\channels\store\ChannelBindingStore;
use backend\channels\store\JsonFile;
use backend\Configs;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\Main;

/**
 * Server-wide bot setup, shared by the admin panel (admin.php?type=…Telegram…) and
 * cli/telegram_setup.php. Secrets (token, webhook secret, channel key) are written to the
 * server config and never returned; status() only reports whether they are set.
 */
class TelegramSetup {
	/**
	 * Validate a BotFather token with getMe and store it with the bot's username. Creates the
	 * channel encryption key and webhook secret on first use, and switches to polling.
	 * @return array{username: string, name: string}
	 * @throws ChannelException when Telegram rejects the token
	 */
	public static function setToken(string $token, ?TelegramApiClient $client = null): array {
		$token = trim($token);
		if(!preg_match('/^\d{5,16}:[A-Za-z0-9_-]{30,}$/', $token))
			throw new ChannelException('This does not look like a bot token from @BotFather.');
		$client = $client ?? new TelegramApiClient($token);
		$me = $client->call('getMe');
		if(!is_array($me) || empty($me['is_bot']))
			throw new ChannelException('The token does not belong to a bot.');

		$values = [
			'telegram_bot_token'    => $token,
			'telegram_bot_username' => (string) ($me['username'] ?? ''),
			'telegram_transport'    => 'polling',
		];
		if((string) Configs::get(ChannelBindingStore::SECRET_KEY) === '')
			$values[ChannelBindingStore::SECRET_KEY] = SecretBox::generateKey();
		if((string) Configs::get('telegram_webhook_secret') === '')
			$values['telegram_webhook_secret'] = bin2hex(random_bytes(24));
		self::writeConfigs($values);
		try {
			$client->call('deleteWebhook', ['drop_pending_updates' => false]);
			self::registerCommands($client);
		}
		catch(ChannelException $e) { /* not fatal for storing the token */ }
		return ['username' => $values['telegram_bot_username'], 'name' => (string) ($me['first_name'] ?? '')];
	}

	/** Forget the bot (participants stay bound and work again once a token is set). */
	public static function removeToken(): void {
		self::writeConfigs(['telegram_bot_token' => '', 'telegram_bot_username' => '']);
	}

	public static function usePolling(): void {
		$channel = new TelegramChannel();
		$channel->client()->call('deleteWebhook', ['drop_pending_updates' => false]);
		self::writeConfigs(['telegram_transport' => 'polling']);
	}

	/** Have Telegram push updates to $url (…/api/channel_webhook.php?channel=telegram). */
	public static function useWebhook(string $url): void {
		if(!preg_match('#^https://#', $url))
			throw new ChannelException('Telegram webhooks need an https:// URL.');
		$channel = new TelegramChannel();
		$channel->client()->call('setWebhook', [
			'url' => $url, 'secret_token' => (string) Configs::get('telegram_webhook_secret'),
			'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => false,
		]);
		self::writeConfigs(['telegram_transport' => 'webhook']);
	}

	/** Public, secret-free status for the admin panel and CLI. */
	public static function status(bool $live = false): array {
		$transport = JsonFile::read(PathsFS::fileChannelTransport(TelegramChannel::ID)) ?? [];
		$heartbeat = JsonFile::read(PathsFS::fileChannelsHeartbeat());
		$out = [
			'configured'       => (string) Configs::get('telegram_bot_token') !== '',
			'username'         => (string) Configs::get('telegram_bot_username'),
			'transport'        => Configs::get('telegram_transport') === 'webhook' ? 'webhook' : 'polling',
			'encryptionKey'    => SecretBox::hasKey(ChannelBindingStore::SECRET_KEY),
			'publicBaseUrl'    => (string) Configs::get('channels_public_base_url'),
			'lastPollMs'       => (int) ($transport['lastPollMs'] ?? 0),
			'lastPollError'    => (string) ($transport['lastError'] ?? ''),
			'dispatcherLastRunMs' => (int) ($heartbeat['lastRunMs'] ?? 0),
			'boundParticipants'   => (int) ($heartbeat['bindings'] ?? 0),
			'nowMs'            => Main::getMilliseconds(),
		];
		if($live && $out['configured']) {
			try {
				$info = (new TelegramChannel())->client()->call('getWebhookInfo');
				$out['webhookUrl'] = (string) ($info['url'] ?? '');
				$out['webhookLastError'] = (string) ($info['last_error_message'] ?? '');
				$out['pendingUpdates'] = (int) ($info['pending_update_count'] ?? 0);
			}
			catch(ChannelException $e) {
				$out['apiError'] = $e->getMessage();
			}
		}
		return $out;
	}

	public static function setPublicBaseUrl(string $url): void {
		self::writeConfigs(['channels_public_base_url' => rtrim(trim($url), '/') . '/']);
	}

	private static function registerCommands(TelegramApiClient $client): void {
		$client->call('setMyCommands', ['commands' => [
			['command' => 'checkin', 'description' => 'Answer an open questionnaire'],
			['command' => 'timezone', 'description' => 'Update your local time'],
			['command' => 'stop', 'description' => 'Leave the study'],
			['command' => 'help', 'description' => 'What I can do'],
		]]);
	}

	private static function writeConfigs(array $values): void {
		FileSystemBasics::writeServerConfigs($values);
		Configs::resetConfig();
	}
}
