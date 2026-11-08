<?php
declare(strict_types=1);

namespace backend\schedule;

/**
 * Ties a participant's logged push events (backend/notifications/PushEvents) to the notifications the schedule
 * planned for them, so the researcher's calendar can show, per prompt and reminder, when it was sent and
 * whether the participant received and tapped it.
 *
 * The log does not say which prompt an event belongs to, so the match is by time:
 * - `sent` / `failed` (the server handing it to the push service) belong to the latest planned notification
 *   at or before them, if that was less than SEND_WINDOW earlier. The sender runs once a minute.
 * - `received` (the device reporting a notification) belongs to the latest planned one at or before it, unless that
 *   is more than SEND_WINDOW earlier and the server pushed an earlier one: then it is that push arriving late
 *   (a phone that was offline). Android shows its notifications itself and reports no `sent`, so this also covers it.
 * - `clicked` (a tap) belongs to the notification on the phone: the last one pushed or received before it (a new
 *   push replaces the previous one, they share a tag), up to RECEIPT_WINDOW earlier.
 * The first event of each kind is kept.
 *
 * Times are real (UTC) ms, in and out. Pure: no storage, no clock.
 */
class PushTimeline {
	/** How long after its planned time a notification can still be the one a `sent`/`failed` belongs to. */
	const SEND_WINDOW = 15 * 60000;
	/** How long after its planned time a receipt (received, tapped) is still tied to a notification. */
	const RECEIPT_WINDOW = 86400000;

	const KINDS = ['sent', 'failed', 'received', 'clicked'];

	/**
	 * @param int[] $slots planned notification times (prompts and reminders), any order, duplicates allowed
	 * @param array<array{t: int, e: string}> $events the participant's push events, any order
	 * @return array<int, array{sent: ?int, failed: ?int, received: ?int, clicked: ?int}> by planned time
	 */
	public static function match(array $slots, array $events): array {
		$slots = array_values(array_unique(array_map('intval', $slots)));
		sort($slots);
		$out = [];
		foreach($slots as $slot)
			$out[$slot] = ['sent' => null, 'failed' => null, 'received' => null, 'clicked' => null];
		if(!$slots)
			return $out;

		usort($events, fn($a, $b) => $a['t'] <=> $b['t']);
		$lastSent = null;  // the planned time of the last notification the server pushed
		$onPhone = null;   // ... of the one the phone currently shows (last pushed or reported)
		foreach($events as $event) {
			$t = (int) $event['t'];
			$kind = (string) $event['e'];
			if(!in_array($kind, self::KINDS, true))
				continue;
			$latest = self::latestAtOrBefore($slots, $t);
			if($kind === 'sent' || $kind === 'failed') {
				if($latest === null || $t - $latest >= self::SEND_WINDOW)
					continue; // a welcome, a study-complete message, a manual push: not a planned notification
				if($out[$latest][$kind] === null)
					$out[$latest][$kind] = $t;
				if($kind === 'sent')
					$lastSent = $onPhone = $latest;
				continue;
			}
			if($kind === 'received') {
				$slot = $latest;
				if($latest !== null && $lastSent !== null && $lastSent < $latest && $t - $latest >= self::SEND_WINDOW)
					$slot = $lastSent;
			}
			else
				$slot = $onPhone !== null && $t - $onPhone < self::RECEIPT_WINDOW ? $onPhone : $latest;
			if($slot === null || $t - $slot >= self::RECEIPT_WINDOW)
				continue;
			if($out[$slot][$kind] === null)
				$out[$slot][$kind] = $t;
			$onPhone = $slot; // an app that never reports `sent` (Android) still anchors its taps
		}
		return $out;
	}

	/** @param int[] $sorted */
	private static function latestAtOrBefore(array $sorted, int $t): ?int {
		$found = null;
		foreach($sorted as $slot) {
			if($slot > $t)
				break;
			$found = $slot;
		}
		return $found;
	}
}
