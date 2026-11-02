<?php
declare(strict_types=1);

namespace backend\schedule;

use stdClass;

/**
 * Turns an older study config into the one schedule (Study.schedule, see Schedule.php) and removes the old
 * scheduling fields, so no other schedule is left anywhere in the config:
 *  - the earlier daily check-in plan (`dailyPlan`): its anchors and slots become the schedule's (those
 *    questionnaires' own schedules and sensor triggers were already ignored and are dropped);
 *  - per questionnaire (in each action trigger): a fixed signal time becomes a check-in prompted at that time, open
 *    until the end of the study day (or for "visible for" minutes); a random signal time drawn N times in a window
 *    becomes N check-ins, one per equal part of the window, each prompted at a random minute; a wearable sensor trigger
 *    becomes an `event` check-in with the same event, window, delay and fallback;
 *  - a fixed completion window (`completableAtSpecificTime`) becomes a check-in open in that window, prompted when it
 *    opens; a questionnaire with no schedule at all a check-in open any time;
 *  - the active period (`durationStartingAfterDays`, `durationPeriodDays`, `durationStart`, `durationEnd`) becomes
 *    each check-in's days; `completableOnce` / `completableOncePerDay` the limit `once` / `day` (else `window`, or
 *    `none` for a questionnaire without a schedule); `limitCompletionFrequency` `minGapMinutes`; the notifying
 *    action's text, title and reminders and `notificationIncludeDeadline` the check-in's notification;
 *  - the study's `dayStartTimeOfDay` becomes `dayStart`, a questionnaire's `askAtOnboarding` `autoStart`.
 * Check-ins converted from per-questionnaire schedules are `parallel`, as those were independent of each other; the
 * daily plan's check-ins stay one at a time. ESMira event cues other than `wearable_event` were never run by this
 * server and are left as they are.
 *
 * Run once for every study by admin/updateScripts/3.7.0.php, on saving a config that is still in the old form
 * (SaveStudy), and in memory by Schedule::config() for a config that was not upgraded yet.
 */
class ScheduleUpgrade {
	const ONE_DAY = 86400000;
	const MINUTE = 60000;
	const CUE_WEARABLE = 'wearable_event';
	/** Action.type values that notify (1 = invitation, 3 = notification); 2 = in-app message. */
	const NOTIFYING_ACTION_TYPES = [1, 3];
	/** Action.reminder_delay_minu default: the JSON omits fields that hold their default. */
	const DEFAULT_REMINDER_DELAY_MIN = 5;

	/** Questionnaire fields that only scheduled or gated it; removed by the upgrade. */
	const QUESTIONNAIRE_FIELDS = [
		'durationStart', 'durationEnd', 'durationPeriodDays', 'durationStartingAfterDays',
		'completableOnce', 'completableOncePerDay', 'completableOncePerNotification', 'completableMinutesAfterNotification',
		'limitCompletionFrequency', 'completionFrequencyMinutes',
		'completableAtSpecificTime', 'completableAtSpecificTimeStart', 'completableAtSpecificTimeEnd',
		'notificationIncludeDeadline', 'askAtOnboarding',
	];

	/** Is this config still in the old form (no `schedule`)? */
	public static function needed(array $study): bool {
		return !is_array($study['schedule'] ?? null);
	}

	/** The study's schedule, upgraded in memory when the config is still in the old form. */
	public static function scheduleOf(array $study): ?array {
		if(!self::needed($study))
			return $study['schedule'];
		return self::upgrade($study)['study']['schedule'];
	}

	/** upgrade() for a config decoded as objects (the study store's form). */
	public static function upgradeObject(stdClass $study, array &$notes = []): stdClass {
		$result = self::upgrade(json_decode(json_encode($study), true));
		$notes = $result['notes'];
		return json_decode(json_encode($result['study']));
	}

	/**
	 * @return array{study: array, notes: string[]} the upgraded config (unchanged when it already has a schedule) and
	 *   what could not be carried over exactly
	 */
	public static function upgrade(array $study): array {
		if(!self::needed($study))
			return ['study' => $study, 'notes' => []];
		$notes = [];
		$questionnaires = is_array($study['questionnaires'] ?? null) ? $study['questionnaires'] : [];
		$byId = [];
		foreach($questionnaires as $q) {
			if(is_array($q))
				$byId[(int) ($q['internalId'] ?? -1)] = $q;
		}

		$schedule = [];
		$checkins = [];
		$plan = $study['dailyPlan'] ?? null;
		$planned = [];
		if(is_array($plan) && !empty($plan['enabled']) && !empty($plan['slots'])) {
			foreach(array_intersect_key($plan, Schedule::SCHEDULE_DEFAULTS) as $key => $value)
				$schedule[$key] = $value;
			foreach($plan['slots'] as $slot) {
				if(!is_array($slot))
					continue;
				$qid = (int) ($slot['questionnaireId'] ?? -1);
				$q = $byId[$qid] ?? [];
				$planned[$qid] = true;
				$checkin = ['questionnaireId' => $qid]
					+ array_intersect_key($slot, Schedule::CHECKIN_DEFAULTS)
					+ self::daysOf($q)
					+ self::completionOf($q, false)
					+ self::notificationOf($q, self::firstAction($q));
				$checkin['limit'] = Schedule::LIMIT_WINDOW; // a plan slot is done once its own window is
				$checkins[] = $checkin;
			}
		}
		$dayStart = (int) ($study['dayStartTimeOfDay'] ?? 0);
		if($dayStart > 0)
			$schedule['dayStart'] = $dayStart;

		foreach($questionnaires as $q) {
			if(!is_array($q))
				continue;
			$qid = (int) ($q['internalId'] ?? -1);
			if(isset($planned[$qid]))
				continue;
			foreach(self::checkinsOf($q, $dayStart, $notes) as $checkin)
				$checkins[] = $checkin;
		}
		$schedule['checkins'] = array_map([self::class, 'withoutDefaults'], $checkins);

		$study['schedule'] = $schedule;
		unset($study['dailyPlan'], $study['dayStartTimeOfDay']);
		foreach($questionnaires as $i => $q) {
			if(is_array($q))
				$study['questionnaires'][$i] = self::stripped($q);
		}
		return ['study' => $study, 'notes' => $notes];
	}

	/** Check-ins for one questionnaire that is not in a daily plan. */
	private static function checkinsOf(array $q, int $dayStart, array &$notes): array {
		$qid = (int) ($q['internalId'] ?? -1);
		$label = '"' . ($q['title'] ?? $qid) . '"';
		$days = self::daysOf($q);
		$timeout = !empty($q['completableOncePerNotification']) ? (int) ($q['completableMinutesAfterNotification'] ?? 0) : 0;
		$spec = !empty($q['completableAtSpecificTime']);
		$specStart = (int) ($q['completableAtSpecificTimeStart'] ?? -1);
		$specEnd = (int) ($q['completableAtSpecificTimeEnd'] ?? -1);
		// Until the end of the study day.
		$endOfDay = ['endType' => Schedule::TYPE_FIXED, 'endTime' => $dayStart, 'endNextDay' => true];
		// From the prompt for "visible for" minutes, else until the end of the study day.
		$open = $timeout > 0
			? ['available' => Schedule::AVAILABLE_PROMPT, 'responseOverride' => true, 'responseMinutes' => $timeout]
			: ['available' => Schedule::AVAILABLE_DAY];
		$out = [];
		$hasSignals = false;
		$firstAction = null;

		foreach(($q['actionTriggers'] ?? []) as $at) {
			if(!is_array($at))
				continue;
			$action = self::notifyingAction($at);
			$firstAction = $firstAction ?? $action;
			$notify = self::notificationOf($q, $action);
			$prompts = $action !== null;
			foreach(($at['schedules'] ?? []) as $s) {
				if(!is_array($s))
					continue;
				$repeat = max(1, (int) ($s['dailyRepeatRate'] ?? 1));
				$scheduleDays = [
					// The first day as the schedulers had it (startDayOne only when stored).
					'dayFrom' => $days['dayFrom'] + (!empty($s['skipFirstInLoop']) ? $repeat : 0) + (($s['startDayOne'] ?? false) === true ? 1 : 0),
					'everyDays' => $repeat,
					'weekdays' => (int) ($s['weekdays'] ?? 0),
					'dayOfMonth' => (int) ($s['dayOfMonth'] ?? 0),
				] + $days;
				foreach(($s['signalTimes'] ?? []) as $st) {
					if(!is_array($st))
						continue;
					$hasSignals = true;
					if($spec)
						continue; // the fixed completion window decides when it is open (below)
					$start = (int) ($st['startTimeOfDay'] ?? 0);
					if(!empty($st['random'])) {
						$end = max($start, (int) ($st['endTimeOfDay'] ?? $start));
						$frequency = max(1, (int) ($st['frequency'] ?? 1));
						$block = intdiv($end - $start, $frequency);
						for($k = 0; $k < $frequency; $k++) {
							$out[] = ['questionnaireId' => $qid, 'parallel' => true,
								'startType' => Schedule::TYPE_FIXED, 'startTime' => $start + $k * $block,
								'endType' => Schedule::TYPE_FIXED, 'endTime' => $start + ($k + 1) * $block,
								'prompt' => $prompts ? Schedule::PROMPT_RANDOM : Schedule::PROMPT_NONE,
							] + $open + $scheduleDays + self::completionOf($q, true) + $notify;
						}
					}
					else {
						$out[] = ['questionnaireId' => $qid, 'parallel' => true,
							'startType' => Schedule::TYPE_FIXED, 'startTime' => $start,
							'endType' => Schedule::TYPE_FIXED, 'endTime' => $start,
							'prompt' => $prompts ? Schedule::PROMPT_START : Schedule::PROMPT_NONE,
						] + $open + $scheduleDays + self::completionOf($q, true) + $notify;
					}
				}
			}
			foreach(($at['eventTriggers'] ?? []) as $et) {
				if(!is_array($et) || ($et['cueCode'] ?? '') !== self::CUE_WEARABLE)
					continue;
				if((int) ($et['wearableMaxPerDay'] ?? 1) > 1)
					$notes[] = "$label: a sensor trigger with up to " . (int) $et['wearableMaxPerDay'] . " prompts a day now prompts once a day";
				$window = !empty($et['wearableWindowEnabled']);
				$maxDelay = max(0, (int) ($et['delaySec'] ?? 0));
				$minDelay = !empty($et['randomDelay']) ? min($maxDelay, max(0, (int) ($et['delayMinimumSec'] ?? 0))) : $maxDelay;
				$fallback = empty($et['fallbackEnabled']) ? Schedule::FALLBACK_NONE
					: (($et['fallbackMode'] ?? '') === Schedule::FALLBACK_VARIABLE && trim((string) ($et['fallbackVariable'] ?? '')) !== ''
						? Schedule::FALLBACK_VARIABLE : Schedule::FALLBACK_FIXED);
				// "Must be completed by" (completableAtSpecificTimeEnd) closes it, else "visible for", else the end of the day.
				$close = $spec && $specEnd >= 0
					? ['endType' => Schedule::TYPE_FIXED, 'endTime' => $specEnd, 'available' => Schedule::AVAILABLE_PROMPT, 'responseOverride' => true, 'responseMinutes' => 0]
					: $endOfDay + $open;
				$out[] = ['questionnaireId' => $qid, 'parallel' => true,
					'startType' => Schedule::TYPE_FIXED, 'startTime' => 0,
					'prompt' => Schedule::PROMPT_EVENT,
					'eventProvider' => (string) ($et['wearableProvider'] ?? 'any'),
					'eventKind' => (string) ($et['wearableEvent'] ?? 'sleep'),
					'eventFrom' => $window ? (int) ($et['wearableWindowStart'] ?? 18000000) : -1,
					'eventUntil' => $window ? (int) ($et['wearableWindowEnd'] ?? 43200000) : -1,
					'eventDelayMin' => intdiv($minDelay, 60),
					'eventDelayMaxMin' => intdiv($maxDelay, 60),
					'fallback' => $fallback,
					'fallbackTime' => (int) ($et['fallbackTimeOfDay'] ?? 43200000),
					'fallbackVariable' => trim((string) ($et['fallbackVariable'] ?? '')),
					'fallbackOffsetMin' => (int) ($et['fallbackOffsetMin'] ?? 0),
					'fallbackDefault' => ($et['fallbackDefaultEnabled'] ?? true) !== false,
				] + $close + $days + self::completionOf($q, true) + $notify;
			}
		}

		if($spec) {
			if($hasSignals)
				$notes[] = "$label: signal times combined with a fixed completion window now prompt when the window opens";
			$out[] = ['questionnaireId' => $qid, 'parallel' => true,
				'startType' => Schedule::TYPE_FIXED, 'startTime' => max(0, $specStart),
				'prompt' => $firstAction !== null && $specStart >= 0 ? Schedule::PROMPT_START : Schedule::PROMPT_NONE,
				'available' => Schedule::AVAILABLE_RANGE,
			] + ($specEnd >= 0 ? ['endType' => Schedule::TYPE_FIXED, 'endTime' => $specEnd] : $endOfDay)
				+ $days + self::completionOf($q, true) + self::notificationOf($q, $firstAction);
		}
		if(!$out) { // no schedule: open any time while it is active
			$out[] = ['questionnaireId' => $qid, 'parallel' => true,
				'startType' => Schedule::TYPE_FIXED, 'startTime' => $dayStart,
				'prompt' => Schedule::PROMPT_NONE, 'available' => Schedule::AVAILABLE_RANGE,
			] + $endOfDay + $days + self::completionOf($q, false) + self::notificationOf($q, null);
		}
		return $out;
	}

	/** A questionnaire's active period as check-in days. */
	private static function daysOf(array $q): array {
		$period = (int) ($q['durationPeriodDays'] ?? 0);
		return [
			'dayFrom' => max(0, (int) ($q['durationStartingAfterDays'] ?? 0)),
			'dayUntil' => $period > 0 ? $period : -1,
			'dateFrom' => max(0, (int) ($q['durationStart'] ?? 0)),
			'dateUntil' => max(0, (int) ($q['durationEnd'] ?? 0)),
		];
	}

	/**
	 * A questionnaire's completion rules as a check-in's limit and gap.
	 * @param bool $scheduled false for a questionnaire without a schedule, which could be completed as often as wanted
	 */
	private static function completionOf(array $q, bool $scheduled): array {
		$limit = !empty($q['completableOnce']) ? Schedule::LIMIT_ONCE
			: (!empty($q['completableOncePerDay']) ? Schedule::LIMIT_DAY : ($scheduled ? Schedule::LIMIT_WINDOW : Schedule::LIMIT_NONE));
		$out = ['limit' => $limit];
		if(!empty($q['limitCompletionFrequency']))
			$out['minGapMinutes'] = max(0, (int) ($q['completionFrequencyMinutes'] ?? 60));
		if(!empty($q['askAtOnboarding']))
			$out['autoStart'] = true;
		return $out;
	}

	/** A notifying action (notifyingAction()) as a check-in's notification fields. */
	private static function notificationOf(array $q, ?array $action): array {
		$out = [];
		if($action !== null) {
			[$text, $count, $delayMin, $title] = $action;
			$out = ['title' => $title, 'text' => $text, 'reminderCount' => $count, 'reminderDelayMin' => $delayMin];
		}
		if(!empty($q['notificationIncludeDeadline']))
			$out['includeDeadline'] = true;
		return $out;
	}

	/** The notifying action of an action trigger as [text, reminderCount, reminderDelayMin, title], or null. */
	private static function notifyingAction(array $trigger): ?array {
		foreach(($trigger['actions'] ?? []) as $a) {
			if(!is_array($a) || !in_array((int) ($a['type'] ?? 1), self::NOTIFYING_ACTION_TYPES, true))
				continue;
			$delay = max(0, (int) ($a['reminder_delay_minu'] ?? self::DEFAULT_REMINDER_DELAY_MIN));
			return [
				is_string($a['msgText'] ?? null) ? trim($a['msgText']) : '',
				$delay > 0 ? max(0, (int) ($a['reminder_count'] ?? 0)) : 0,
				$delay,
				is_string($a['msgTitle'] ?? null) ? trim($a['msgTitle']) : '',
			];
		}
		return null;
	}

	private static function firstAction(array $q): ?array {
		foreach(($q['actionTriggers'] ?? []) as $at) {
			$action = is_array($at) ? self::notifyingAction($at) : null;
			if($action !== null)
				return $action;
		}
		return null;
	}

	/**
	 * The questionnaire without its scheduling: the gating fields go, and so do the action triggers' schedules,
	 * sensor triggers and notifying actions. A trigger left with nothing is removed.
	 */
	private static function stripped(array $q): array {
		foreach(self::QUESTIONNAIRE_FIELDS as $field)
			unset($q[$field]);
		$triggers = [];
		foreach(($q['actionTriggers'] ?? []) as $at) {
			if(!is_array($at))
				continue;
			unset($at['schedules']);
			$at['eventTriggers'] = array_values(array_filter($at['eventTriggers'] ?? [], fn($et) => is_array($et) && ($et['cueCode'] ?? '') !== self::CUE_WEARABLE));
			$at['actions'] = array_values(array_filter($at['actions'] ?? [], fn($a) => is_array($a) && !in_array((int) ($a['type'] ?? 1), self::NOTIFYING_ACTION_TYPES, true)));
			if($at['eventTriggers'] || $at['actions'])
				$triggers[] = array_filter($at, fn($v) => $v !== []);
		}
		if($triggers)
			$q['actionTriggers'] = $triggers;
		else
			unset($q['actionTriggers']);
		return $q;
	}

	/** A check-in without the fields that hold their default (the editor's way of saving). */
	private static function withoutDefaults(array $checkin): array {
		$out = [];
		foreach($checkin as $key => $value) {
			if(!array_key_exists($key, Schedule::CHECKIN_DEFAULTS))
				continue;
			$default = Schedule::CHECKIN_DEFAULTS[$key];
			$value = is_int($default) ? (int) $value : (is_bool($default) ? (bool) $value : (string) $value);
			if($key === 'questionnaireId' || $value !== $default)
				$out[$key] = $value;
		}
		return $out;
	}
}
