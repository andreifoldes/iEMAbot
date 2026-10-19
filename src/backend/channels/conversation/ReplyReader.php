<?php
declare(strict_types=1);

namespace backend\channels\conversation;

use backend\channels\InboundEvent;

/**
 * Interprets a non-button reply (typed text, voice note, photo, location) to the
 * current item, according to how the item was asked (PromptFactory mode).
 *
 * Returns exactly one of:
 *   ['hint' => string]    reply not acceptable — tell the participant, keep the question
 *   ['option' => int]     same as pressing option button i
 *   ['multi' => int[]]    selected option indexes of a multi-select
 *   ['value' => string]   the answer value
 *   ['media' => array]    {kind, ref, mime, identifier} — file fetched at submission
 *   ['skip' => true]      skip the item (typed "Skip" under a native keyboard)
 *   []                    not applicable here
 */
class ReplyReader {
	/** @var array */
	private $ctx;

	public function __construct(array $ctx) {
		$this->ctx = $ctx;
	}

	public function read(array &$s, array $item, InboundEvent $ev, int $nowMs): array {
		$lang = $this->ctx['lang'] ?? 'en';
		$mode = $s['mode'];
		$text = trim($ev->payload);

		if($ev->kind === InboundEvent::KIND_VOICE)
			return $mode === PromptFactory::MODE_VOICE ? ['media' => $this->media('audio', $ev)] : ['hint' => $this->modeHint($mode, $item)];
		if($ev->kind === InboundEvent::KIND_PHOTO || $ev->kind === InboundEvent::KIND_DOCUMENT) {
			if($mode === PromptFactory::MODE_PHOTO || $mode === PromptFactory::MODE_FILE)
				return ['media' => $this->media('image', $ev)];
			return ['hint' => $this->modeHint($mode, $item)];
		}
		if($ev->kind === InboundEvent::KIND_LOCATION)
			return $mode === PromptFactory::MODE_LOCATION ? ['value' => $text] : ['hint' => $this->modeHint($mode, $item)];
		if($ev->kind !== InboundEvent::KIND_TEXT)
			return [];
		if($mode === PromptFactory::MODE_LOCATION && empty($item['required']) && mb_strtolower($text) === mb_strtolower(ChatStrings::t('skip', $lang)))
			return ['skip' => true];

		switch($mode) {
			case PromptFactory::MODE_BUTTONS:
			case PromptFactory::MODE_NUMBERS:
				$i = $this->matchOption($item, $text);
				return $i === null
					? ['hint' => ChatStrings::t($mode === PromptFactory::MODE_NUMBERS ? 'hint_single_number' : 'use_buttons', $lang)]
					: ['option' => $i];
			case PromptFactory::MODE_MULTI_NUMBERS:
				$sel = $this->matchNumbers($text, count($item['options']));
				return $sel === null ? ['hint' => ChatStrings::t('hint_multi_numbers', $lang)] : ['multi' => $sel];
			case PromptFactory::MODE_TYPED:
				return $this->typed($s, $item, $text, $nowMs);
			default:
				return ['hint' => $this->modeHint($mode, $item)];
		}
	}

	private function typed(array &$s, array $item, string $text, int $nowMs): array {
		$lang = $this->ctx['lang'] ?? 'en';
		switch($item['kind']) {
			case 'time':
				$v = AnswerParser::time($text);
				return $v === null ? ['hint' => ChatStrings::t('hint_time', $lang)] : ['value' => $v];
			case 'duration':
				$v = AnswerParser::duration($text);
				return $v === null ? ['hint' => ChatStrings::t('hint_duration', $lang)] : ['value' => $v];
			case 'date':
				$v = AnswerParser::date($text, $nowMs - (int) ($this->ctx['tzOffsetMin'] ?? 0) * 60000);
				return $v === null ? ['hint' => ChatStrings::t('hint_date', $lang)] : ['value' => $v];
			case 'number':
				$v = AnswerParser::number($text, !empty($item['decimal']));
				return $v === null ? ['hint' => ChatStrings::t(empty($item['decimal']) ? 'hint_integer' : 'hint_number', $lang)] : ['value' => $v];
			case 'keystroke':
				// minLength is a soft nudge (as in the PWA): ask once for more, then accept.
				$min = (int) ($item['minLength'] ?? 0);
				if($min > 0 && mb_strlen($text) < $min && !in_array($item['name'], $s['nudged'], true)) {
					$s['nudged'][] = $item['name'];
					return ['hint' => ChatStrings::t('hint_too_short', $lang, $min)];
				}
				return $text === '' ? ['hint' => ChatStrings::t('hint_text', $lang)] : ['value' => $text];
			case 'text':
			default:
				$max = (int) ($item['maxLength'] ?? 0);
				if($max > 0 && mb_strlen($text) > $max)
					return ['hint' => ChatStrings::t('hint_too_long', $lang, $max)];
				return $text === '' ? ['hint' => ChatStrings::t('hint_text', $lang)] : ['value' => $text];
		}
	}

	/** Typed reply to a button question: the option number, or its label (case-insensitive). */
	private function matchOption(array $item, string $text): ?int {
		$labels = PromptFactory::optionLabels($item);
		if($item['kind'] === 'va_scale') { // buttons are 0, 10, …, 100 (percent of the scale)
			if(!ctype_digit($text) || (int) $text > 100)
				return null;
			return (int) round((int) $text / 10);
		}
		$needle = mb_strtolower($text);
		foreach($labels as $i => $label) {
			if(mb_strtolower(HtmlToChatText::plain((string) $label)) === $needle)
				return $i;
		}
		if(ctype_digit($text) && (int) $text >= 1 && (int) $text <= count($labels))
			return (int) $text - 1;
		return null;
	}

	/** "1, 3 5" → [0, 2, 4]; null when any number is out of range or nothing was given. */
	private function matchNumbers(string $text, int $n): ?array {
		if(!preg_match_all('/\d+/', $text, $m) || preg_match('/[^\d\s,;.]/', $text))
			return null;
		$out = [];
		foreach($m[0] as $num) {
			$i = (int) $num - 1;
			if($i < 0 || $i >= $n)
				return null;
			$out[] = $i;
		}
		$out = array_values(array_unique($out));
		sort($out);
		return $out;
	}

	private function media(string $kind, InboundEvent $ev): array {
		return [
			'kind'       => $kind,
			'ref'        => $ev->payload,
			'mime'       => $ev->args,
			// Upload identifier the dataset references; the file follows after the dataset (CreateDataSet::prepareFile).
			'identifier' => random_int(1, 2147483647),
		];
	}

	private function modeHint(string $mode, array $item): string {
		$lang = $this->ctx['lang'] ?? 'en';
		switch($mode) {
			case PromptFactory::MODE_VOICE: return ChatStrings::t('send_voice', $lang);
			case PromptFactory::MODE_PHOTO: return ChatStrings::t('send_photo', $lang);
			case PromptFactory::MODE_FILE: return ChatStrings::t('send_file', $lang);
			case PromptFactory::MODE_LOCATION: return ChatStrings::t('send_location', $lang);
			case PromptFactory::MODE_TYPED: return ChatStrings::t('hint_text', $lang);
			default: return ChatStrings::t('use_buttons', $lang);
		}
	}
}
