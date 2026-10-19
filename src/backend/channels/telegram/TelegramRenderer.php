<?php
declare(strict_types=1);

namespace backend\channels\telegram;

use backend\channels\OutboundMessage;

/**
 * OutboundMessage → Bot API parameters. The neutral markup (<b>, <i>, <u>, <s>,
 * <a href>) is Telegram's own HTML subset, so text passes through with
 * parse_mode=HTML.
 */
class TelegramRenderer {
	const MAX_TEXT = 4096;
	const MAX_CAPTION = 1024;

	public static function replyMarkup(OutboundMessage $m): ?array {
		switch($m->keyboard) {
			case OutboundMessage::KEYBOARD_LOCATION:
				$rows = [];
				foreach($m->buttons as $row) {
					$out = [];
					foreach($row as $b)
						$out[] = ($b['data'] ?? '') === '' ? ['text' => $b['label'], 'request_location' => true] : ['text' => $b['label']];
					$rows[] = $out;
				}
				return ['keyboard' => $rows, 'one_time_keyboard' => true, 'resize_keyboard' => true];
			case OutboundMessage::KEYBOARD_REMOVE:
				return ['remove_keyboard' => true];
		}
		if(empty($m->buttons))
			return null;
		$rows = [];
		foreach($m->buttons as $row) {
			$out = [];
			foreach($row as $b) {
				if(isset($b['url']))
					$out[] = ['text' => $b['label'], 'url' => $b['url']];
				else if(isset($b['webview']))
					$out[] = ['text' => $b['label'], 'web_app' => ['url' => $b['webview']]];
				else
					$out[] = ['text' => $b['label'], 'callback_data' => (string) $b['data']];
			}
			$rows[] = $out;
		}
		return ['inline_keyboard' => $rows];
	}

	/**
	 * Calls needed to send $m: one sendMessage / sendPhoto, preceded by extra sendMessage
	 * calls when the text exceeds Telegram's limit (buttons stay on the last part).
	 * @return array<int, array{0: string, 1: array}>
	 */
	public static function sendCalls(string $chatId, OutboundMessage $m): array {
		$markup = self::replyMarkup($m);
		$base = ['chat_id' => $chatId, 'parse_mode' => 'HTML'];
		if($m->imageUrl !== '') {
			if(mb_strlen($m->text) <= self::MAX_CAPTION) {
				$params = $base + ['photo' => $m->imageUrl, 'caption' => $m->text];
				if($markup !== null)
					$params['reply_markup'] = $markup;
				return [['sendPhoto', $params]];
			}
			$calls = [['sendPhoto', ['chat_id' => $chatId, 'photo' => $m->imageUrl]]];
			return array_merge($calls, self::textCalls($base, $m->text, $markup));
		}
		return self::textCalls($base, $m->text, $markup);
	}

	public static function editCall(string $chatId, string $messageId, OutboundMessage $m): array {
		if($m->keepText)
			return ['editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => (int) $messageId, 'reply_markup' => ['inline_keyboard' => []]]];
		$params = [
			'chat_id' => $chatId, 'message_id' => (int) $messageId, 'parse_mode' => 'HTML',
			'text' => mb_substr($m->text, 0, self::MAX_TEXT),
			'link_preview_options' => ['is_disabled' => true],
			// An empty inline keyboard removes the buttons of the answered question.
			'reply_markup' => self::replyMarkup($m) ?? ['inline_keyboard' => []],
		];
		return ['editMessageText', $params];
	}

	private static function textCalls(array $base, string $text, ?array $markup): array {
		$chunks = \backend\channels\conversation\HtmlToChatText::split($text === '' ? '…' : $text, self::MAX_TEXT);
		$calls = [];
		foreach($chunks as $i => $chunk) {
			$params = $base + ['text' => $chunk, 'link_preview_options' => ['is_disabled' => true]];
			if($i === count($chunks) - 1 && $markup !== null)
				$params['reply_markup'] = $markup;
			$calls[] = ['sendMessage', $params];
		}
		return $calls;
	}
}
