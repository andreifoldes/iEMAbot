<?php
declare(strict_types=1);

namespace backend\channels;

/**
 * Whether a questionnaire can be answered right now — a port of
 * web-pwa/src/lib/availability.ts (computeAvailability), so a chat participant can open
 * exactly the questionnaires a web participant could (/checkin):
 *  - active period: durationStart/End, durationStartingAfterDays/PeriodDays (local days
 *    since joining);
 *  - completion limits: completableOnce, completableOncePerDay, limitCompletionFrequency;
 *  - intra-day windows: completableAtSpecificTime, or each signal time until
 *    completableMinutesAfterNotification (with completableOncePerNotification) / end of day;
 *  - questionnaires without any signal time are open all day while active.
 * Times are participant-local via the JS-convention tz offset (UTC − local, minutes).
 *
 * Completions come from the chat state: qdone[qid] = [completedAtMs…],
 * done["qid|occKey"] = completedAtMs.
 */
class QuestionnaireAvailability {
	const DAY = 86400000;

	/** @return array{state: string, occKey: ?string} state: available|upcoming|ended|completed|locked */
	public static function compute(array $q, int $joinedMs, int $nowMs, int $tzOffsetMin, array $state): array {
		$offset = $tzOffsetMin * 60000;
		$local = $nowMs - $offset;
		$today = intdiv($local, self::DAY) * self::DAY;
		$dayIndex = intdiv($local, self::DAY) - intdiv($joinedMs - $offset, self::DAY);

		$ds = (int) ($q['durationStart'] ?? 0);
		$de = (int) ($q['durationEnd'] ?? 0);
		if($ds > 0 && $nowMs < $ds)
			return self::result('upcoming');
		if($de > 0 && $nowMs > $de)
			return self::result('ended');
		$startAfter = (int) ($q['durationStartingAfterDays'] ?? 0);
		$period = (int) ($q['durationPeriodDays'] ?? 0);
		if($startAfter > 0 && $dayIndex < $startAfter)
			return self::result('upcoming');
		if($period > 0 && $dayIndex > $period)
			return self::result('ended');

		$qid = (string) ($q['internalId'] ?? '');
		$completions = $state['qdone'][$qid] ?? [];
		$last = $completions ? (int) end($completions) : 0;
		if(!empty($q['completableOnce']) && $completions)
			return self::result('completed');
		if(!empty($q['completableOncePerDay']) && $last && intdiv($last - $offset, self::DAY) * self::DAY === $today)
			return self::result('locked');
		if(!empty($q['limitCompletionFrequency']) && $last) {
			$gap = (int) ($q['completionFrequencyMinutes'] ?? 60) * 60000;
			if($nowMs - $last < $gap)
				return self::result('locked');
		}

		$windows = self::windowsForDay($q, $dayIndex, $today);
		if($windows === null)
			return self::result('available');
		$tod = $local - $today;
		foreach($windows as $w) {
			if($tod >= $w['start'] && $tod <= $w['end'] && !isset($state['done'][$qid . '|' . $w['key']]))
				return self::result('available', $w['key']);
		}
		return self::result('locked');
	}

	/** Completion windows of a local day, or null for a passive questionnaire (no schedule). */
	private static function windowsForDay(array $q, int $dayIndex, int $dayMs): ?array {
		if(!empty($q['completableAtSpecificTime'])) {
			$s = (int) ($q['completableAtSpecificTimeStart'] ?? -1);
			$e = (int) ($q['completableAtSpecificTimeEnd'] ?? -1);
			return [['start' => $s >= 0 ? $s : 0, 'end' => $e >= 0 ? $e : self::DAY, 'key' => "spec:$dayIndex"]];
		}
		$triggers = $q['actionTriggers'] ?? [];
		$hasSignals = false;
		foreach($triggers as $t) {
			foreach(($t['schedules'] ?? []) as $s) {
				if(!empty($s['signalTimes']))
					$hasSignals = true;
			}
		}
		if(!$hasSignals)
			return null;

		$timeout = (!empty($q['completableOncePerNotification']) && (int) ($q['completableMinutesAfterNotification'] ?? 0) > 0)
			? (int) $q['completableMinutesAfterNotification'] * 60000
			: 0;
		$startAfter = (int) ($q['durationStartingAfterDays'] ?? 0);
		$out = [];
		foreach($triggers as $t) {
			foreach(($t['schedules'] ?? []) as $s) {
				if(!self::scheduledOnDay($s, $dayIndex, $dayMs, $startAfter))
					continue;
				foreach(($s['signalTimes'] ?? []) as $st) {
					$start = (int) ($st['startTimeOfDay'] ?? 0);
					$out[] = ['start' => $start, 'end' => $timeout > 0 ? min(self::DAY, $start + $timeout) : self::DAY, 'key' => "$dayIndex:$start"];
				}
			}
		}
		usort($out, function($a, $b) { return $a['start'] <=> $b['start']; });
		return $out;
	}

	private static function scheduledOnDay(array $s, int $dayIndex, int $dayMs, int $startAfter): bool {
		$repeat = max(1, (int) ($s['dailyRepeatRate'] ?? 1));
		$first = $startAfter + (!empty($s['skipFirstInLoop']) ? $repeat : 0) + (!empty($s['startDayOne']) ? 1 : 0);
		if($dayIndex < $first || ($dayIndex - $first) % $repeat !== 0)
			return false;
		$weekdays = (int) ($s['weekdays'] ?? 0);
		if($weekdays !== 0 && ($weekdays & (1 << (int) gmdate('w', intdiv($dayMs, 1000)))) === 0)
			return false;
		$dom = (int) ($s['dayOfMonth'] ?? 0);
		return $dom === 0 || (int) gmdate('j', intdiv($dayMs, 1000)) === $dom;
	}

	private static function result(string $state, ?string $occKey = null): array {
		return ['state' => $state, 'occKey' => $occKey];
	}
}
