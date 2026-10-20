<?php
declare(strict_types=1);

namespace backend\channels\telegram;

use backend\channels\InboundEvent;

/** Telegram Update object → InboundEvents. Only private chats with the bot are handled. */
class TelegramUpdateParser {
	/** @return InboundEvent[] */
	public static function parse(array $update): array {
		$updateId = (string) ($update['update_id'] ?? '');

		if(isset($update['callback_query'])) {
			$cq = $update['callback_query'];
			$msg = $cq['message'] ?? [];
			if(($msg['chat']['type'] ?? 'private') !== 'private' || !isset($msg['chat']['id']))
				return [];
			return [new InboundEvent(
				InboundEvent::KIND_CALLBACK, (string) $msg['chat']['id'], (string) ($cq['data'] ?? ''), '', $updateId,
				(string) ($cq['id'] ?? ''), (string) ($msg['message_id'] ?? ''), (string) ($cq['from']['language_code'] ?? '')
			)];
		}

		$msg = $update['message'] ?? null;
		if(!is_array($msg) || ($msg['chat']['type'] ?? '') !== 'private' || !isset($msg['chat']['id']))
			return [];
		$chat = (string) $msg['chat']['id'];
		$lang = (string) ($msg['from']['language_code'] ?? '');
		$make = function(string $kind, string $payload, string $args = '') use ($chat, $updateId, $lang) {
			return [new InboundEvent($kind, $chat, $payload, $args, $updateId, '', '', $lang)];
		};

		if(isset($msg['text'])) {
			$text = (string) $msg['text'];
			if(preg_match('/^\/([a-z0-9_]{1,32})(?:@\w+)?(?:\s+(.*))?$/is', trim($text), $m)) {
				$command = strtolower($m[1]);
				$args = trim($m[2] ?? '');
				return $command === 'start'
					? $make(InboundEvent::KIND_START, $args)
					: $make(InboundEvent::KIND_COMMAND, $command, $args);
			}
			return $make(InboundEvent::KIND_TEXT, $text);
		}
		if(isset($msg['voice']))
			return $make(InboundEvent::KIND_VOICE, (string) $msg['voice']['file_id'], (string) ($msg['voice']['mime_type'] ?? 'audio/ogg'));
		if(isset($msg['audio']))
			return $make(InboundEvent::KIND_VOICE, (string) $msg['audio']['file_id'], (string) ($msg['audio']['mime_type'] ?? 'audio/mpeg'));
		if(isset($msg['video_note']))
			return $make(InboundEvent::KIND_VOICE, (string) $msg['video_note']['file_id'], 'video/mp4');
		if(isset($msg['photo']) && is_array($msg['photo']) && $msg['photo']) {
			$largest = end($msg['photo']); // sizes are ascending
			return $make(InboundEvent::KIND_PHOTO, (string) $largest['file_id'], 'image/jpeg');
		}
		if(isset($msg['document']))
			return $make(InboundEvent::KIND_DOCUMENT, (string) $msg['document']['file_id'], (string) ($msg['document']['mime_type'] ?? ''));
		if(isset($msg['location']))
			return $make(InboundEvent::KIND_LOCATION, $msg['location']['latitude'] . ',' . $msg['location']['longitude']);
		return $make(InboundEvent::KIND_UNKNOWN, '');
	}
}
