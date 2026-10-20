<?php
declare(strict_types=1);

namespace backend\channels\conversation;

use backend\channels\ChannelCapabilities;
use backend\channels\OutboundMessage;

/**
 * Turns one flattened item into the message that asks it, choosing per item the best
 * form the channel supports (the "fallback ladder"):
 *   native buttons/toggles → typed numbered reply → web handoff → skipped with a note.
 * The returned `mode` tells ConversationEngine how to read the participant's reply.
 *
 * Context ($ctx): lang, baseUrl (absolute prefix for relative media URLs), selected
 * (int[] current multi-select toggles), handoffUrl (?string — page that runs this item
 * in a web view; null when handoff is unavailable).
 */
class PromptFactory {
	const MODE_DISPLAY = 'display';       // show and move on
	const MODE_SKIP = 'skip';             // nothing to show, move on without a value
	const MODE_BUTTONS = 'buttons';       // one of several buttons (o<i>); typed number/label also accepted
	const MODE_NUMBERS = 'numbers';       // typed number of an option (no buttons available)
	const MODE_TOGGLE = 'toggle';         // multi-select with toggle buttons + Done
	const MODE_MULTI_NUMBERS = 'multi_numbers';
	const MODE_TYPED = 'typed';           // free text / number / time / duration / date / keystroke
	const MODE_VOICE = 'voice';
	const MODE_PHOTO = 'photo';
	const MODE_FILE = 'file';
	const MODE_LOCATION = 'location';
	const MODE_HANDOFF = 'handoff';       // answered in a web page; result arrives via the handoff API
	const MODE_CONTINUE = 'continue';     // countdown: press Continue

	const LIKERT_MAX_PER_ROW = 7;

	/** @return array{message: ?OutboundMessage, mode: string} */
	public static function build(array $item, string $sess, int $idx, ChannelCapabilities $caps, array $ctx): array {
		$lang = $ctx['lang'] ?? 'en';
		$text = self::questionText($item);
		$skipRow = empty($item['required']) ? [[OutboundMessage::button(ChatStrings::t('skip', $lang), ButtonData::answer($sess, $idx, 's'))]] : [];
		$canButtons = $caps->maxButtons > 0;

		switch($item['kind']) {
			case 'unsupported':
				return ['message' => null, 'mode' => self::MODE_SKIP];
			case 'info':
				return ['message' => $text === '' ? null : OutboundMessage::text($text), 'mode' => self::MODE_DISPLAY];
			case 'image':
				$m = OutboundMessage::text($text);
				if($caps->supportsImages && ($item['url'] ?? '') !== '')
					$m->imageUrl = self::absoluteUrl($item['url'], $ctx['baseUrl'] ?? '');
				return ['message' => ($text === '' && $m->imageUrl === '') ? null : $m, 'mode' => self::MODE_DISPLAY];
			case 'video':
			case 'share':
				$url = self::absoluteUrl((string) ($item['url'] ?? ''), $ctx['baseUrl'] ?? '');
				$buttons = ($url !== '' && $caps->supportsLinks && $canButtons) ? [[OutboundMessage::linkButton(ChatStrings::t('open_link', $lang), $url)]] : [];
				if(!$buttons && $url !== '')
					$text .= "\n" . HtmlToChatText::escape($url);
				return ['message' => new OutboundMessage($text, $buttons), 'mode' => self::MODE_DISPLAY];

			case 'likert':
				$n = (int) $item['steps'];
				$labels = array_map('strval', range(1, $n));
				$ends = self::scaleEnds($item, $lang);
				return self::choiceButtons($text . $ends, $labels, $sess, $idx, $caps, $lang, $skipRow, self::LIKERT_MAX_PER_ROW);
			case 'yesno':
				return self::choiceButtons($text, [$item['left'], $item['right']], $sess, $idx, $caps, $lang, $skipRow, 2);
			case 'choice':
				return self::choiceButtons($text, $item['options'], $sess, $idx, $caps, $lang, $skipRow, 1);
			case 'va_scale':
				if(($ctx['handoffUrl'] ?? null) !== null)
					return self::handoff($text, $item, $sess, $idx, $caps, $ctx, $skipRow);
				$labels = [];
				for($i = 0; $i <= 10; $i++)
					$labels[] = (string) ($i * 10);
				return self::choiceButtons($text . self::scaleEnds($item, $lang), $labels, $sess, $idx, $caps, $lang, $skipRow, 6);

			case 'multi':
				$options = $item['options'];
				if($canButtons && $caps->canToggle(count($options))) {
					$selected = $ctx['selected'] ?? [];
					$rows = [];
					foreach($options as $i => $opt) {
						$label = (in_array($i, $selected, true) ? '✅ ' : '▫️ ') . HtmlToChatText::truncate(HtmlToChatText::plain($opt), $caps->maxButtonLabel);
						$rows[] = [OutboundMessage::button($label, ButtonData::answer($sess, $idx, 't' . $i))];
					}
					$rows[] = [OutboundMessage::button(ChatStrings::t('done', $lang), ButtonData::answer($sess, $idx, 'd'))];
					return ['message' => OutboundMessage::prompt($text, $rows), 'mode' => self::MODE_TOGGLE];
				}
				$body = $text . "\n\n" . self::numberedList($options) . "\n\n<i>" . ChatStrings::t('hint_multi_numbers', $lang) . '</i>';
				return ['message' => OutboundMessage::prompt($body, $canButtons ? $skipRow : []), 'mode' => self::MODE_MULTI_NUMBERS];

			case 'date':
				$rows = $canButtons ? [[
					OutboundMessage::button(ChatStrings::t('today', $lang), ButtonData::answer($sess, $idx, 'o0')),
					OutboundMessage::button(ChatStrings::t('yesterday', $lang), ButtonData::answer($sess, $idx, 'o1')),
				]] : [];
				return self::typed($text, 'hint_date', $lang, array_merge($rows, $skipRow));
			case 'time':
				return self::typed($text, 'hint_time', $lang, $skipRow);
			case 'duration':
				return self::typed($text, 'hint_duration', $lang, $skipRow);
			case 'number':
				return self::typed($text, empty($item['decimal']) ? 'hint_integer' : 'hint_number', $lang, $skipRow);
			case 'text':
				return self::typed($text, 'hint_text', $lang, $skipRow);
			case 'keystroke':
				if(($ctx['handoffUrl'] ?? null) !== null)
					return self::handoff($text, $item, $sess, $idx, $caps, $ctx, $skipRow);
				return self::typed($text, 'hint_text', $lang, $skipRow);

			case 'audio':
				return self::media($text, 'send_voice', $caps->supportsVoice, self::MODE_VOICE, $lang, $sess, $idx, $caps);
			case 'photo':
				return self::media($text, 'send_photo', $caps->supportsPhoto, self::MODE_PHOTO, $lang, $sess, $idx, $caps, $skipRow);
			case 'file':
				return self::media($text, 'send_file', $caps->supportsPhoto, self::MODE_FILE, $lang, $sess, $idx, $caps, $skipRow);
			case 'location':
				if(!$caps->supportsLocation)
					return ['message' => null, 'mode' => self::MODE_SKIP];
				// A native location keyboard cannot carry inline buttons, so Skip is a keyboard
				// button too (its label comes back as text; see ReplyReader).
				$rows = [[OutboundMessage::button(ChatStrings::t('share_location', $lang), '')]];
				if(empty($item['required']))
					$rows[] = [OutboundMessage::button(ChatStrings::t('skip', $lang), 'skip')];
				$m = OutboundMessage::prompt($text . "\n\n" . ChatStrings::t('send_location', $lang), $rows, OutboundMessage::KEYBOARD_LOCATION);
				return ['message' => $m, 'mode' => self::MODE_LOCATION];
			case 'countdown':
				$body = $text . "\n\n" . ChatStrings::t('countdown', $lang, (int) $item['seconds']);
				return ['message' => OutboundMessage::prompt($body, $canButtons ? [[OutboundMessage::button(ChatStrings::t('continue', $lang), ButtonData::answer($sess, $idx, 'c'))]] : []), 'mode' => self::MODE_CONTINUE];

			case 'cognitive':
				if(($ctx['handoffUrl'] ?? null) !== null)
					return self::handoff($text, $item, $sess, $idx, $caps, $ctx, $skipRow);
				return ['message' => OutboundMessage::text('<b>' . HtmlToChatText::escape($item['cogTitle']) . '</b>: ' . ChatStrings::t('task_web_only', $lang)), 'mode' => self::MODE_DISPLAY];
		}
		return ['message' => $text === '' ? null : OutboundMessage::text($text), 'mode' => self::MODE_DISPLAY];
	}

	/** Stored value for option button $i of an item (mirrors the PWA value formats). */
	public static function optionValue(array $item, int $i, int $todayLocalMs = 0): ?string {
		switch($item['kind']) {
			case 'likert':
				return ($i >= 0 && $i < (int) $item['steps']) ? (string) ($i + 1) : null;
			case 'yesno':
				return ($i === 0 || $i === 1) ? (string) $i : null;
			case 'choice':
				return isset($item['options'][$i]) ? (string) $item['options'][$i] : null;
			case 'va_scale':
				return ($i >= 0 && $i <= 10) ? (string) (int) round($i * (int) $item['maxValue'] / 10) : null;
			case 'date':
				return ($i === 0 || $i === 1) ? gmdate('Y-m-d', intdiv($todayLocalMs, 1000) - $i * 86400) : null;
		}
		return null;
	}

	/** Labels of the option buttons, for matching a typed reply against them. */
	public static function optionLabels(array $item): array {
		switch($item['kind']) {
			case 'likert':
				return array_map('strval', range(1, (int) $item['steps']));
			case 'yesno':
				return [$item['left'], $item['right']];
			case 'choice':
			case 'multi':
				return $item['options'];
		}
		return [];
	}

	public static function questionText(array $item): string {
		$text = HtmlToChatText::convert((string) ($item['text'] ?? ''));
		if(($item['description'] ?? '') !== '') {
			$desc = HtmlToChatText::convert($item['description']);
			if($desc !== '')
				$text .= ($text === '' ? '' : "\n") . '<i>' . $desc . '</i>';
		}
		return $text;
	}

	private static function choiceButtons(string $text, array $labels, string $sess, int $idx, ChannelCapabilities $caps, string $lang, array $skipRow, int $perRow): array {
		$plain = array_map(function($l) { return HtmlToChatText::plain((string) $l); }, $labels);
		if($caps->maxButtons < count($labels) + count($skipRow)) {
			$body = $text . "\n\n" . self::numberedList($labels) . "\n\n<i>" . ChatStrings::t('hint_single_number', $lang) . '</i>';
			return ['message' => OutboundMessage::prompt($body, $caps->maxButtons > 0 ? $skipRow : []), 'mode' => self::MODE_NUMBERS];
		}
		// Long labels get truncated on the buttons; then the full text goes in a numbered list.
		$truncated = false;
		foreach($plain as $l) {
			if(mb_strlen($l) > $caps->maxButtonLabel)
				$truncated = true;
		}
		if($truncated)
			$text .= "\n\n" . self::numberedList($labels);

		$perRow = max(1, min($perRow, $caps->maxButtonsPerRow));
		$n = count($plain);
		$rowsNeeded = (int) ceil($n / $perRow);
		$perRow = (int) ceil($n / max(1, $rowsNeeded)); // balance rows (e.g. 11 → 6 + 5)
		$rows = [];
		$row = [];
		foreach($plain as $i => $label) {
			$shown = $truncated ? ($i + 1) . '. ' . HtmlToChatText::truncate($label, $caps->maxButtonLabel - 4) : $label;
			$row[] = OutboundMessage::button($shown, ButtonData::answer($sess, $idx, 'o' . $i));
			if(count($row) >= $perRow) {
				$rows[] = $row;
				$row = [];
			}
		}
		if($row)
			$rows[] = $row;
		return ['message' => OutboundMessage::prompt($text, array_merge($rows, $skipRow)), 'mode' => self::MODE_BUTTONS];
	}

	private static function typed(string $text, string $hintKey, string $lang, array $rows): array {
		$body = $text . "\n\n<i>" . ChatStrings::t($hintKey, $lang) . '</i>';
		return ['message' => OutboundMessage::prompt($body, $rows), 'mode' => self::MODE_TYPED];
	}

	private static function media(string $text, string $hintKey, bool $supported, string $mode, string $lang, string $sess, int $idx, ChannelCapabilities $caps, array $skipRow = []): array {
		if(!$supported)
			return ['message' => null, 'mode' => self::MODE_SKIP];
		// A voice memo is always skippable: its typed fallback (if any) is revealed on skip.
		$rows = $mode === self::MODE_VOICE && $caps->maxButtons > 0
			? [[OutboundMessage::button(ChatStrings::t('skip', $lang), ButtonData::answer($sess, $idx, 's'))]]
			: $skipRow;
		return ['message' => OutboundMessage::prompt($text . "\n\n" . ChatStrings::t($hintKey, $lang), $rows), 'mode' => $mode];
	}

	private static function handoff(string $text, array $item, string $sess, int $idx, ChannelCapabilities $caps, array $ctx, array $skipRow): array {
		$lang = $ctx['lang'] ?? 'en';
		$url = (string) $ctx['handoffUrl'];
		$title = $item['kind'] === 'cognitive' ? $item['cogTitle'] : HtmlToChatText::truncate(HtmlToChatText::plain($item['text']), 24);
		$label = ChatStrings::t('open_task', $lang, $title !== '' ? $title : '…');
		$open = $caps->supportsWebView ? OutboundMessage::webviewButton($label, $url) : OutboundMessage::linkButton($label, $url);
		$body = $item['kind'] === 'cognitive'
			? '<b>' . HtmlToChatText::escape($item['cogTitle']) . '</b>' . ($item['cogDescription'] !== '' ? "\n" . HtmlToChatText::convert($item['cogDescription']) : '')
			: $text;
		$skip = ($item['kind'] === 'cognitive' && $skipRow)
			? [[OutboundMessage::button(ChatStrings::t('skip_task', $lang), ButtonData::answer($sess, $idx, 's'))]]
			: $skipRow;
		return ['message' => OutboundMessage::prompt($body, array_merge([[$open]], $skip)), 'mode' => self::MODE_HANDOFF];
	}

	private static function scaleEnds(array $item, string $lang): string {
		$left = HtmlToChatText::plain((string) ($item['left'] ?? ''));
		$right = HtmlToChatText::plain((string) ($item['right'] ?? ''));
		if($left === '' && $right === '')
			return '';
		return "\n<i>" . HtmlToChatText::escape(ChatStrings::t('scale_ends', $lang, $left, $right)) . '</i>';
	}

	private static function numberedList(array $labels): string {
		$lines = [];
		foreach($labels as $i => $l)
			$lines[] = ($i + 1) . '. ' . HtmlToChatText::escape(HtmlToChatText::plain((string) $l));
		return implode("\n", $lines);
	}

	public static function absoluteUrl(string $url, string $baseUrl): string {
		if($url === '' || preg_match('#^https?://#i', $url))
			return $url;
		return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
	}
}
