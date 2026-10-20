<?php
declare(strict_types=1);

namespace backend\notifications;

/**
 * Computes which questionnaire reminders are due for one participant, from the
 * study's existing schedule config (the same config the native apps consume).
 *
 * Supports fixed AND random signal times, reminder repeats, and completion-aware
 * suppression:
 *  - Fixed signal time → fires at startTimeOfDay (participant-local).
 *  - Random signal window → `frequency` times are sampled within
 *    [startTimeOfDay, endTimeOfDay] ONCE per day and persisted in `$realized`
 *    (the per-participant .state), so they stay stable across the per-minute runs
 *    instead of jittering. (They need not match the native app's own random roll.)
 *  - Reminders (reminder_count × reminder_delay_minu) follow each base, and are
 *    suppressed once the participant has submitted anything since the base time
 *    (a study-wide lastDataSetTime heuristic — good enough for EMA, where a
 *    submission right after a prompt is almost always the prompted survey).
 *
 * Times of day are participant-local via the tz offset captured at subscribe time.
 * The day/weekday/duration math is a best-effort port of helpers/Scheduler.ts and
 * should be validated against the canonical Kotlin sharedCode for advanced cases.
 */
class PushScheduler {
	const ONE_DAY = 86400000;
	// Action.type values that produce a participant-facing notification: 1=invitation
	// (the standard EMA "answer this questionnaire" prompt) and 3=simple notification.
	// 2=message is an in-app message (no push), so it is excluded.
	const NOTIFICATION_ACTION_TYPES = [1, 3];

	/**
	 * @param array $study           decoded, language-resolved study config
	 * @param int   $anchorMs        participant schedule anchor (join time, UTC ms)
	 * @param int   $tzOffsetMin     participant tz offset (JS getTimezoneOffset(), minutes)
	 * @param int   $sinceMs         exclusive lower bound (per-participant cursor)
	 * @param int   $nowMs           inclusive upper bound (now)
	 * @param int   $lastDataSetTime participant's last submission time (UTC ms), or -1
	 * @param array $realized        persisted realized random times; mutated in place
	 * @return array<int, array{title:string, body:string, timestamp:int}>
	 */
	public static function computeDueOccurrences(
		array $study, int $anchorMs, int $tzOffsetMin, int $sinceMs, int $nowMs,
		int $lastDataSetTime, array &$realized
	): array {
		$out = [];
		$offsetMs = $tzOffsetMin * 60000;
		$studyTitle = (is_string($study['title'] ?? null) && $study['title'] !== '') ? $study['title'] : 'ESMira';
		$anchorDayLocal = intdiv($anchorMs - $offsetMs, self::ONE_DAY) * self::ONE_DAY;
		$toDay = self::dayIndex($nowMs - $offsetMs, $anchorDayLocal);

		foreach(($study['questionnaires'] ?? []) as $qi => $q) {
			$qTitle = (is_string($q['title'] ?? null) && $q['title'] !== '') ? $q['title'] : $studyTitle;
			$qInternalId     = (int) ($q['internalId'] ?? $qi);
			$includeDeadline = !empty($q['notificationIncludeDeadline']);
			$durStartDay = (int) ($q['durationStartingAfterDays'] ?? 0);
			$durPeriod   = (int) ($q['durationPeriodDays'] ?? 0);
			$durStart    = (int) ($q['durationStart'] ?? 0);
			$durEnd      = (int) ($q['durationEnd'] ?? 0);

			// Whether any action trigger carries a signal-time beep. Questionnaires with a
			// fixed completion window but no beep get a synthetic "window opened" availability
			// notification below (see the window-open pass after this loop).
			$hasSignals = false;
			foreach(($q['actionTriggers'] ?? []) as $atProbe) {
				foreach(($atProbe['schedules'] ?? []) as $sProbe) {
					if(!empty($sProbe['signalTimes'])) { $hasSignals = true; break 2; }
				}
			}

			foreach(($q['actionTriggers'] ?? []) as $ai => $at) {
				$action = self::notificationAction($at);
				if($action === null)
					continue;
				[$body, $reminderCount, $reminderDelayMs] = $action;

				foreach(($at['schedules'] ?? []) as $si => $s) {
					$repeat     = max(1, (int) ($s['dailyRepeatRate'] ?? 1));
					$weekdays   = (int) ($s['weekdays'] ?? 0);
					$dayOfMonth = (int) ($s['dayOfMonth'] ?? 0);
					$skipFirst  = !empty($s['skipFirstInLoop']);
					$firstDay   = $durStartDay + ($skipFirst ? $repeat : 0);
					$maxDay     = $durPeriod > 0 ? ($firstDay + $durPeriod - 1) : PHP_INT_MAX;

					// Look back enough days that reminders of an earlier base still surface.
					$lookback = $reminderCount > 0 ? (int) ceil(($reminderCount * $reminderDelayMs) / self::ONE_DAY) + 1 : 0;
					$fromDay  = max($firstDay, self::dayIndex($sinceMs - $offsetMs, $anchorDayLocal) - $lookback);

					foreach(($s['signalTimes'] ?? []) as $sti => $st) {
						$isRandom = !empty($st['random']);
						for($d = $fromDay; $d <= $toDay; $d++) {
							if($d < $firstDay || $d > $maxDay)
								continue;
							if(($d - $firstDay) % $repeat !== 0)
								continue;
							$dayMidnightLocal = $anchorDayLocal + $d * self::ONE_DAY;
							if($weekdays !== 0 && !self::weekdayMatches($dayMidnightLocal, $weekdays))
								continue;
							if($dayOfMonth !== 0 && (int) gmdate('j', intdiv($dayMidnightLocal, 1000)) !== $dayOfMonth)
								continue;

							if($isRandom) {
								$key = "$qi:$ai:$si:$sti:$d";
								if(!isset($realized[$key]))
									$realized[$key] = self::sampleRandomTimes($st, $dayMidnightLocal, $offsetMs);
								$baseTimes = $realized[$key];
							}
							else {
								$baseTimes = [$dayMidnightLocal + (int) ($st['startTimeOfDay'] ?? 0) + $offsetMs];
							}

							// occKey identifies the SIGNAL WINDOW (day index + configured start-of-day),
							// matching the PWA's own occurrence key in availability.ts (windowsForDay:
							// `${dayIndex}:${startTimeOfDay}`). It lets the service worker suppress a
							// completed once-per-notification occurrence. Random draws deliberately share
							// their window's key (the configured start, not the sampled time), exactly as
							// the PWA keys them — so client and server agree despite differing random rolls.
							$occKey = "$d:" . (int) ($st['startTimeOfDay'] ?? 0);

							foreach($baseTimes as $T) {
								$T = (int) $T;
								if($durStart > 0 && $T < $durStart)
									continue;
								if($durEnd > 0 && $T > $durEnd)
									continue;
								// Completion deadline (shared by the base + its reminders): the
								// completion window closes at this local time-of-day.
								$baseLocalTod = $T - $offsetMs - $dayMidnightLocal;
								$deadlineTod  = self::deadlineTod($q, $baseLocalTod);
								$deadlineUtc  = $deadlineTod === null ? null : $dayMidnightLocal + $deadlineTod + $offsetMs;
								$displayBody  = self::appendDeadline($body, $includeDeadline, $deadlineTod);
								if($T > $sinceMs && $T <= $nowMs)
									$out[] = [
										'type' => 'availability', 'qid' => $qInternalId, 'title' => $qTitle,
										'body' => $displayBody, 'timestamp' => $T, 'windowStart' => $T, 'deadline' => $deadlineUtc, 'occKey' => $occKey,
									];
								for($k = 1; $k <= $reminderCount; $k++) {
									$rt = $T + $k * $reminderDelayMs;
									if($rt <= $sinceMs || $rt > $nowMs)
										continue;
									if($lastDataSetTime >= $T) // completed since the base — stop nagging
										continue;
									$out[] = [
										'type' => 'reminder', 'qid' => $qInternalId, 'title' => $qTitle,
										'body' => $displayBody, 'timestamp' => $rt, 'windowStart' => $T, 'deadline' => $deadlineUtc, 'occKey' => $occKey,
									];
								}
							}
						}
					}
				}
			}

			// ── Window-open availability for questionnaires with a fixed daily completion
			// window but no signal-time beep (e.g. "morning diary, complete 08:00–12:00").
			// Fires once per active day at the window-open time. Event-triggered
			// questionnaires keep start = -1 (open) and are skipped here — they open on the
			// event, not on a wall-clock time.
			$specStart = (int) ($q['completableAtSpecificTimeStart'] ?? -1);
			if(!empty($q['completableAtSpecificTime']) && $specStart >= 0 && !$hasSignals) {
				$firstDay = $durStartDay;
				$maxDay   = $durPeriod > 0 ? ($firstDay + $durPeriod - 1) : PHP_INT_MAX;
				$fromDay  = max($firstDay, self::dayIndex($sinceMs - $offsetMs, $anchorDayLocal));
				$availBody = self::firstNotificationBody($q);
				for($d = $fromDay; $d <= $toDay && $d <= $maxDay; $d++) {
					$dayMidnightLocal = $anchorDayLocal + $d * self::ONE_DAY;
					$T = $dayMidnightLocal + $specStart + $offsetMs;
					if($durStart > 0 && $T < $durStart)
						continue;
					if($durEnd > 0 && $T > $durEnd)
						continue;
					if($T > $sinceMs && $T <= $nowMs) {
						$deadlineTod = self::deadlineTod($q, $specStart);
						$deadlineUtc = $deadlineTod === null ? null : $dayMidnightLocal + $deadlineTod + $offsetMs;
						$out[] = [
							'type' => 'availability', 'qid' => $qInternalId, 'title' => $qTitle,
							'body' => self::appendDeadline($availBody, $includeDeadline, $deadlineTod), 'occKey' => "spec:$d",
							'timestamp' => $T, 'windowStart' => $T, 'deadline' => $deadlineUtc,
						];
					}
				}
			}
		}

		self::pruneRealized($realized, $toDay);
		return $out;
	}

	/** [body, reminderCount, reminderDelayMs] for a trigger's notifying action, or null. */
	public static function notificationAction(array $at): ?array {
		foreach(($at['actions'] ?? []) as $a) {
			// Action.type defaults to 1 (Invitation) in the model, and DataStructure omits
			// default-valued fields when serialising — so a plain invitation action has NO
			// `type` key. Default the missing value to 1, not 0, or those (very common)
			// actions would never notify.
			if(in_array((int) ($a['type'] ?? 1), self::NOTIFICATION_ACTION_TYPES, true)) {
				$msg   = $a['msgText'] ?? '';
				$body  = (is_string($msg) && $msg !== '') ? $msg : 'You have a questionnaire to complete.';
				$count = max(0, (int) ($a['reminder_count'] ?? 0));
				$delay = max(0, (int) ($a['reminder_delay_minu'] ?? 0)) * 60000;
				if($delay === 0)
					$count = 0; // no spacing configured → no reminders
				return [$body, $count, $delay];
			}
		}
		return null;
	}

	/** Body of the first notifying action of a questionnaire, or a default availability body. */
	private static function firstNotificationBody(array $q): string {
		foreach(($q['actionTriggers'] ?? []) as $at) {
			$action = self::notificationAction($at);
			if($action !== null)
				return $action[0];
		}
		return 'A new questionnaire is available.';
	}

	/**
	 * The completion deadline as ms-since-local-midnight for a window opening at
	 * $baseLocalTod, or null when the questionnaire has no meaningful deadline.
	 * Mirrors the PWA's availability.ts window logic: a per-notification timeout
	 * takes precedence, else the fixed completion-window end.
	 */
	public static function deadlineTod(array $q, int $baseLocalTod): ?int {
		$timeout = (int) ($q['completableMinutesAfterNotification'] ?? 0);
		if(!empty($q['completableOncePerNotification']) && $timeout > 0)
			return min(self::ONE_DAY, $baseLocalTod + $timeout * 60000);
		if(!empty($q['completableAtSpecificTime'])) {
			$end = (int) ($q['completableAtSpecificTimeEnd'] ?? -1);
			if($end >= 0)
				return $end;
		}
		return null;
	}

	/** Append " Complete by HH:MM." to a body when the questionnaire opts in and a deadline exists. */
	public static function appendDeadline(string $body, bool $include, ?int $deadlineTod): string {
		if(!$include || $deadlineTod === null)
			return $body;
		$secs = intdiv($deadlineTod, 1000);
		if($secs >= 86400)
			$secs = 86340; // clamp end-of-day to 23:59 rather than showing 00:00
		return $body . ' ' . sprintf('Complete by %s.', gmdate('H:i', $secs));
	}

	/** Sample `frequency` random times within [start,end] for one day, as UTC ms. */
	private static function sampleRandomTimes(array $st, int $dayMidnightLocal, int $offsetMs): array {
		$start = (int) ($st['startTimeOfDay'] ?? 0);
		$end   = (int) ($st['endTimeOfDay'] ?? $start);
		if($end < $start)
			$end = $start;
		$frequency = max(1, (int) ($st['frequency'] ?? 1));
		$block = intdiv($end - $start, $frequency);
		$times = [];
		for($i = 0; $i < $frequency; $i++) {
			$blockStart = $start + $i * $block;
			$tod = $block > 0 ? $blockStart + random_int(0, $block) : $blockStart;
			$times[] = $dayMidnightLocal + $tod + $offsetMs;
		}
		return $times;
	}

	/** Drop realized entries for days now safely in the past (keeps today + yesterday). */
	private static function pruneRealized(array &$realized, int $toDay): void {
		foreach(array_keys($realized) as $key) {
			$parts = explode(':', (string) $key);
			if((int) end($parts) < $toDay - 1)
				unset($realized[$key]);
		}
	}

	public static function dayIndex(int $localMs, int $anchorDayLocal): int {
		return intdiv((intdiv($localMs, self::ONE_DAY) * self::ONE_DAY) - $anchorDayLocal, self::ONE_DAY);
	}

	/** weekdays is a bitfield, bit 0 = Sunday … bit 6 = Saturday. */
	private static function weekdayMatches(int $localMs, int $weekdays): bool {
		$dayNum = intdiv($localMs, self::ONE_DAY); // days since (local) epoch
		$dow = (($dayNum % 7) + 4) % 7;            // 1970-01-01 was a Thursday (index 4); 0=Sun
		return ($weekdays & (1 << $dow)) !== 0;
	}
}
