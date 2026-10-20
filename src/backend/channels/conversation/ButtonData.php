<?php
declare(strict_types=1);

namespace backend\channels\conversation;

/**
 * Compact, channel-neutral button payloads (Telegram allows 64 bytes, WhatsApp 256).
 *   a.<session>.<itemIndex>.<op>  answer an item   (op: o<i> option, t<i> toggle, d done,
 *                                                     s skip, c continue, x cancel)
 *   e.<step>.<value>              enrolment step   (consent / timezone)
 *   q.<questionnaireId>           open a questionnaire from /checkin
 * The session id + item index make every answer button single-use: presses on an old
 * question are recognised as stale instead of answering the current one.
 */
class ButtonData {
	public static function answer(string $session, int $index, string $op): string {
		return "a.$session.$index.$op";
	}

	public static function enrol(string $step, string $value): string {
		return "e.$step.$value";
	}

	public static function openQuestionnaire(int $qid): string {
		return "q.$qid";
	}

	/**
	 * @return array{type:string, session?:string, index?:int, op?:string, arg?:int, step?:string, value?:string, qid?:int}|null
	 */
	public static function parse(string $data): ?array {
		if(preg_match('/^a\.([a-z0-9]{1,12})\.(\d{1,4})\.([a-z])(-?\d{0,6})$/', $data, $m))
			return ['type' => 'answer', 'session' => $m[1], 'index' => (int) $m[2], 'op' => $m[3], 'arg' => $m[4] === '' ? -1 : (int) $m[4]];
		if(preg_match('/^e\.([a-z]{1,8})\.(-?[A-Za-z0-9_]{1,40})$/', $data, $m))
			return ['type' => 'enrol', 'step' => $m[1], 'value' => $m[2]];
		if(preg_match('/^q\.(\d{1,12})$/', $data, $m))
			return ['type' => 'open', 'qid' => (int) $m[1]];
		return null;
	}
}
