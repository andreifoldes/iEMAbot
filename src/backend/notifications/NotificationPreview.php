<?php
declare(strict_types=1);

namespace backend\notifications;

use backend\schedule\Schedule;

/**
 * What a participant will be sent, for the researcher's notification panel: the prompts and reminders of the first
 * days after enrolment, computed by the same code that sends them (Schedule, which the Android app's planner mirrors),
 * and grouped per minute the way PushSender and the app show them. So the preview is, by construction, what both the
 * web app and the Android app display.
 *
 * Times are participant-local (an enrolment at local midnight, no offset), for a sample participant without answers or
 * wearable events: wake-up and bedtime are the schedule's defaults, event prompts come at their fallback, and random
 * prompts are drawn for that sample participant (each real participant gets their own draw within the same range).
 */
class NotificationPreview {
	const DAYS = 3;
	const ONE_DAY = 86400000;
	const SAMPLE_USER = 'preview';

	/** @return array<int, array{day:int, timeOfDay:int, title:string, body:string, items:array}> */
	public static function build(array $study, int $nowMs, int $days = self::DAYS): array {
		$anchor = intdiv($nowMs, self::ONE_DAY) * self::ONE_DAY;
		$inputs = ['events' => [], 'variables' => [], 'completions' => []];
		$occurrences = Schedule::upcoming($study, (int) ($study['id'] ?? 0), self::SAMPLE_USER, $inputs, $anchor, 0, $anchor, $anchor, $anchor + $days * self::ONE_DAY);

		$byMinute = [];
		foreach($occurrences as $occ) {
			$minute = intdiv((int) $occ['timestamp'], 60000);
			$qid = (int) $occ['qid'];
			if(!isset($byMinute[$minute][$qid]) || (int) $occ['timestamp'] > (int) $byMinute[$minute][$qid]['timestamp'])
				$byMinute[$minute][$qid] = $occ;
		}
		ksort($byMinute);

		$out = [];
		foreach($byMinute as $minute => $group) {
			$items = array_values($group);
			[$title, $body] = NotificationSpec::coalesce($items, $study);
			$ts = $minute * 60000;
			$out[] = [
				'day'       => intdiv($ts - $anchor, self::ONE_DAY),
				'timeOfDay' => ($ts - $anchor) % self::ONE_DAY,
				'title'     => $title,
				'body'      => $body,
				'items'     => array_map(fn($o) => ['qid' => (int) $o['qid'], 'type' => $o['type'], 'title' => $o['title'], 'body' => $o['body']], $items),
			];
		}
		return $out;
	}
}
