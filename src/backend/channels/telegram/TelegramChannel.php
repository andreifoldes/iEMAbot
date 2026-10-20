<?php
declare(strict_types=1);

namespace backend\channels\telegram;

use backend\channels\ChannelCapabilities;
use backend\channels\ChannelException;
use backend\channels\ConversationalChannel;
use backend\channels\OutboundMessage;
use backend\Configs;

/**
 * Telegram adapter. One bot per ESMira server, configured by the admin:
 *   telegram_bot_token        (secret; never returned by the API)
 *   telegram_bot_username     (public; used for t.me deep links)
 *   telegram_webhook_secret   (secret; checked on webhook calls)
 *   telegram_transport        'polling' (default) | 'webhook'
 */
class TelegramChannel implements ConversationalChannel {
	const ID = 'telegram';

	/** @var TelegramApiClient|null */
	private $client;

	public function __construct(?TelegramApiClient $client = null) {
		$this->client = $client;
	}

	public function id(): string {
		return self::ID;
	}

	public function displayName(): string {
		return 'Telegram';
	}

	public function appType(): string {
		return 'Telegram';
	}

	public function capabilities(): ChannelCapabilities {
		return new ChannelCapabilities([
			'maxButtons'          => 90,
			'maxButtonsPerRow'    => 8,
			'maxButtonLabel'      => 40,
			'supportsMessageEdit' => true,
			'supportsVoice'       => true,
			'supportsPhoto'       => true,
			'supportsLocation'    => true,
			'supportsWebView'     => true,
			'supportsLinks'       => true,
			'supportsImages'      => true,
			'maxTextLength'       => TelegramRenderer::MAX_TEXT,
		]);
	}

	public function isConfigured(): bool {
		return (string) Configs::get('telegram_bot_token') !== '';
	}

	public function client(): TelegramApiClient {
		if($this->client === null)
			$this->client = new TelegramApiClient((string) Configs::get('telegram_bot_token'));
		return $this->client;
	}

	public function send(string $chatRef, OutboundMessage $message): string {
		$messageId = '';
		foreach(TelegramRenderer::sendCalls($chatRef, $message) as [$method, $params]) {
			$result = $this->client()->call($method, $params);
			$messageId = is_array($result) ? (string) ($result['message_id'] ?? '') : '';
		}
		return $messageId;
	}

	public function edit(string $chatRef, string $messageRef, OutboundMessage $message): bool {
		if($messageRef === '')
			return false;
		[$method, $params] = TelegramRenderer::editCall($chatRef, $messageRef, $message);
		try {
			$this->client()->call($method, $params);
			return true;
		}
		catch(ChannelException $e) {
			// Unchanged content is not a failure; anything else (too old, deleted) → send anew.
			return stripos($e->getMessage(), 'message is not modified') !== false;
		}
	}

	public function ack(string $ackId, string $toast = ''): void {
		if($ackId === '')
			return;
		try {
			$this->client()->call('answerCallbackQuery', $toast === '' ? ['callback_query_id' => $ackId] : ['callback_query_id' => $ackId, 'text' => $toast]);
		}
		catch(ChannelException $e) { /* the spinner times out by itself */ }
	}

	public function verifyInbound(array $headers, string $body): bool {
		$secret = (string) Configs::get('telegram_webhook_secret');
		$given = (string) ($headers['x-telegram-bot-api-secret-token'] ?? '');
		return $secret !== '' && hash_equals($secret, $given);
	}

	public function parseInbound(string $body): array {
		$update = json_decode($body, true);
		return is_array($update) ? TelegramUpdateParser::parse($update) : [];
	}

	public function startLink(string $payload): string {
		$username = ltrim((string) Configs::get('telegram_bot_username'), '@');
		return $username === '' ? '' : "https://t.me/$username?start=" . rawurlencode($payload);
	}

	/** Download a participant's voice note / photo / document; temp path or null. */
	public function fetchMedia(array $media): ?string {
		return $this->client()->download((string) $media['ref'], min(20000000, (int) Configs::get('max_filesize_for_uploads')));
	}
}
