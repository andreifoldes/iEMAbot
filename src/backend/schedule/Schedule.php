<?php
declare(strict_types=1);

namespace backend\schedule;

use backend\notifications\NotificationSpec;
use backend\notifications\NotificationText;
use backend\notifications\ParticipantVariableStore;
use backend\wearables\WearablesEventStore;

/**
 * The study's one schedule (Study.schedule): which check-in windows open when, when participants are prompted
 * and when a check-in is done, for every channel. Web push (PushSender), Telegram (ChannelDispatcher,
 * ConversationService), the Android app's server nudges (NativePending), the researcher preview
 * (NotificationPreview) and the participant app (web-pwa/src/lib/schedule.ts, a line-by-line port) all use it;
 * none of them has scheduling rules of its own. Questionnaires carry no scheduling settings: a questionnaire
 * without a check-in is never open and never prompted. Older configs are upgraded by ScheduleUpgrade.
 *
 * A schedule has study-wide rules and anchors and an ordered list of check-ins. Each check-in is one
 * questionnaire with
 *  - days: from `dayFrom` (days since the enrolment day, day 0) to `dayUntil`, every `everyDays` days, on
 *    `weekdays` / `dayOfMonth`, between `dateFrom` and `dateUntil`;
 *  - a daily range whose ends are a clock time (`fixed`), wake-up, the usual wake-up or bedtime plus an offset
 *    (`wake`, `usualWake`, `bed`), or a point of the waking day split into equal parts (`split`);
 *  - a prompt, at most once a day: at the `start` of the range, at a `random` minute inside it, at `wake`-up
 *    (the wearable's event, else the usual wake-up + `promptGraceMin`), at a wearable `event` (else its
 *    fallback), or `none`;
 *  - when it can be answered (`available`): from the `prompt` for the response window, throughout the `range`,
 *    or from the prompt until the study `day` ends;
 *  - a completion `limit`: once per `window`, per study `day`, `once` in the whole study, or `none`; and
 *    `minGapMinutes` between two completions of its questionnaire;
 *  - its notification: `title`, `text`, `reminderCount` × `reminderDelayMin`, `includeDeadline`.
 * Check-ins are open one at a time: where windows overlap, `overlap` decides: `defer` (default) opens the next
 * check-in no earlier than the previous window closes, `truncate` closes the previous window when the next one
 * opens. With `cooldownMinutes`, a check-in opens no earlier than that long after another one was completed. A
 * `parallel` check-in (e.g. a questionnaire open any time) is outside of this: it neither moves nor is moved by
 * the others, and takes no part in the cool-down.
 *
 * Wake-up and bedtime are anchors with tiered fallbacks: observed (a wearable event in the anchor's window; for
 * wake-up also answering the wake-up check-in, `wakeFromCheckin`), else tier 1 the participant's answer to
 * `wakeVariable` / `bedVariable` (+ offset; on Saturdays and Sundays `wakeVariableWeekend` first, on Friday and
 * Saturday nights `bedVariableWeekend` first), else tier 2 `wakeDefault` / `bedDefault`. An observation only
 * counts while it comes before the fallback. Split points use the planned bedtime (tiers 1-2).
 *
 * The pure part (normalize … applyCompletions) is ported line by line to web-pwa/src/lib/schedule.ts;
 * test/fixtures/channels/schedule.json holds both to the same output. Times of day are ms since the
 * participant's local midnight and may exceed one day for ranges that run past midnight.
 * See website/docs/backend/scheduling.md.
 */
class Schedule {
	const ONE_DAY = 86400000;
	const MINUTE = 60000;
	const MAX_AGE_MS = 7200000; // never send a prompt more than 2 h late
	const LEDGER_KEEP_DAYS = 4;
	const EVENT_LOOKBACK_MS = 3 * self::ONE_DAY;

	const TYPE_FIXED = 'fixed';
	const TYPE_WAKE = 'wake';
	const TYPE_BED = 'bed';
	const TYPE_SPLIT = 'split';
	const TYPE_USUAL_WAKE = 'usualWake';
	const PROMPT_START = 'start';
	const PROMPT_RANDOM = 'random';
	const PROMPT_WAKE = 'wake';
	const PROMPT_EVENT = 'event';
	const PROMPT_NONE = 'none';
	const AVAILABLE_PROMPT = 'prompt';
	const AVAILABLE_RANGE = 'range';
	const AVAILABLE_DAY = 'day';
	const OVERLAP_DEFER = 'defer';
	const OVERLAP_TRUNCATE = 'truncate';
	const LIMIT_WINDOW = 'window';
	const LIMIT_DAY = 'day';
	const LIMIT_ONCE = 'once';
	const LIMIT_NONE = 'none';
	/** ended(): the participant's study ended yesterday or today, or before that. */
	const ENDED_JUST = 'just';
	const ENDED_LONG = 'long';
	const FALLBACK_FIXED = 'fixed';
	const FALLBACK_VARIABLE = 'variable';
	const FALLBACK_NONE = 'none';

	// Defaults of the omitted-when-default fields; keep in sync with data/study/Schedule.ts and schedule.ts.
	const SCHEDULE_DEFAULTS = [
		'dayStart' => 0,               // when a study day starts, ms after midnight (e.g. 04:00)
		'segments' => 0,               // 0 = one segment per check-in
		'responseMinutes' => 60,       // 0 = until the end of the check-in's range
		'overlap' => self::OVERLAP_DEFER,
		'cooldownMinutes' => 0,        // 0 = off
		'wakeSensor' => false,
		'wakeFromCheckin' => false,
		'wakeProvider' => 'any',
		'wakeEvent' => 'bed_out',
		'wakeWindowStart' => 18000000, // 05:00
		'wakeWindowEnd' => 43200000,   // 12:00
		'wakeVariable' => '',
		'wakeVariableWeekend' => '',   // tier 1 on Saturdays and Sundays; '' = wakeVariable
		'wakeOffsetMin' => 0,
		'wakeDefault' => 28800000,     // 08:00
		'bedSensor' => false,
		'bedProvider' => 'any',
		'bedEvent' => 'bed_in',
		'bedWindowStart' => 72000000,  // 20:00
		'bedWindowEnd' => 14400000,    // 04:00 (the next day)
		'bedVariable' => '',
		'bedVariableWeekend' => '',    // tier 1 on Friday and Saturday nights; '' = bedVariable
		'bedOffsetMin' => 0,
		'bedDefault' => 82800000,      // 23:00
	];
	const CHECKIN_DEFAULTS = [
		'questionnaireId' => -1,
		// days
		'dayFrom' => 0,
		'dayUntil' => -1,              // -1 = no end
		'everyDays' => 1,
		'weekdays' => 0,               // bitmask, Sunday = bit 0; 0 = every day
		'dayOfMonth' => 0,
		'dateFrom' => 0,               // absolute ms; 0 = none
		'dateUntil' => 0,
		// range
		'startType' => self::TYPE_SPLIT,
		'startTime' => 32400000,       // 09:00
		'startOffsetMin' => 0,
		'startSegment' => 0,
		'startNextDay' => false,
		'endType' => self::TYPE_SPLIT,
		'endTime' => 39600000,         // 11:00
		'endOffsetMin' => 0,
		'endSegment' => 1,
		'endNextDay' => false,
		// prompt
		'prompt' => self::PROMPT_START,
		'promptGraceMin' => 0,         // PROMPT_WAKE: minutes after the usual wake-up before the fallback prompt
		'eventProvider' => 'any',
		'eventKind' => 'bed_out',
		'eventFrom' => -1,             // -1 = the whole range
		'eventUntil' => -1,
		'eventDelayMin' => 0,
		'eventDelayMaxMin' => 0,       // > eventDelayMin: a stable random delay in between
		'fallback' => self::FALLBACK_FIXED,
		'fallbackTime' => 43200000,    // 12:00
		'fallbackVariable' => '',
		'fallbackOffsetMin' => 0,
		'fallbackDefault' => true,     // FALLBACK_VARIABLE: fallbackTime while the variable is unanswered
		// when it can be answered
		'available' => self::AVAILABLE_PROMPT,
		'responseOverride' => false,
		'responseMinutes' => 60,
		'limit' => self::LIMIT_WINDOW,
		'minGapMinutes' => 0,
		'parallel' => false,           // open alongside the others: no part in one-at-a-time and the cool-down
		'autoStart' => false,
		// notification
		'title' => '',
		'text' => '',
		'reminderCount' => 0,
		'reminderDelayMin' => 5,
		'includeDeadline' => false,
	];

	/** The study's schedule with defaults filled in (older configs upgraded on the fly), or null without check-ins. */
	public static function config(array $study): ?array {
		$raw = ScheduleUpgrade::scheduleOf($study);
		if($raw === null)
			return null;
		$schedule = self::normalize($raw);
		return $schedule['checkins'] ? $schedule : null;
	}

	public static function normalize(array $raw): array {
		$schedule = array_merge(self::SCHEDULE_DEFAULTS, array_intersect_key($raw, self::SCHEDULE_DEFAULTS));
		$dayStart = (int) $schedule['dayStart'];
		$schedule['dayStart'] = $dayStart > 0 && $dayStart < self::ONE_DAY / 2 ? $dayStart : 0;
		$schedule['checkins'] = [];
		foreach(($raw['checkins'] ?? []) as $checkin) {
			if(is_array($checkin))
				$schedule['checkins'][] = array_merge(self::CHECKIN_DEFAULTS, array_intersect_key($checkin, self::CHECKIN_DEFAULTS));
		}
		return $schedule;
	}

	/** @return int[] internalIds of the questionnaires that have a check-in */
	public static function questionnaireIds(array $study): array {
		$schedule = self::config($study);
		if($schedule === null)
			return [];
		return array_values(array_unique(array_map(fn($c) => (int) $c['questionnaireId'], $schedule['checkins'])));
	}

	/** The study day start (ms after local midnight) of a study, 0 without a schedule. */
	public static function dayStart(array $study): int {
		$schedule = self::config($study);
		return $schedule === null ? 0 : (int) $schedule['dayStart'];
	}

	/** @return string[] item names whose answers the schedule reads (anchors, event fallbacks), for ParticipantVariableStore */
	public static function variableNames(array $study): array {
		$schedule = self::config($study);
		if($schedule === null)
			return [];
		$names = array_map(fn($key) => trim((string) $schedule[$key]), ['wakeVariable', 'wakeVariableWeekend', 'bedVariable', 'bedVariableWeekend']);
		foreach($schedule['checkins'] as $c) {
			if($c['prompt'] === self::PROMPT_EVENT && $c['fallback'] === self::FALLBACK_VARIABLE)
				$names[] = trim((string) $c['fallbackVariable']);
		}
		return array_values(array_unique(array_filter($names)));
	}

	/**
	 * Index of the wake-up check-in: the first check-in that starts right at wake-up (`wake` + 0 or split point 0),
	 * or -1. Only with `wakeFromCheckin`.
	 */
	public static function wakeCheckinIndex(array $schedule): int {
		if(empty($schedule['wakeFromCheckin']))
			return -1;
		foreach($schedule['checkins'] as $i => $c) {
			if(($c['startType'] === self::TYPE_WAKE && (int) $c['startOffsetMin'] === 0)
				|| ($c['startType'] === self::TYPE_SPLIT && (int) $c['startSegment'] === 0))
				return $i;
		}
		return -1;
	}

	/** Does the schedule read wearable events (wake-up, bedtime or an event prompt)? */
	public static function usesSensor(array $study): bool {
		$schedule = self::config($study);
		if($schedule === null)
			return false;
		if(!empty($schedule['wakeSensor']) || !empty($schedule['bedSensor']))
			return true;
		foreach($schedule['checkins'] as $c) {
			if($c['prompt'] === self::PROMPT_EVENT)
				return true;
		}
		return false;
	}

	/** Does the schedule react to this provider/kind (WearablesWebhook keeps only those events)? */
	public static function wantsEvent(array $study, string $provider, string $kind): bool {
		$schedule = self::config($study);
		if($schedule === null)
			return false;
		if((!empty($schedule['wakeSensor']) && self::anchorEventMatches($schedule, 'wake', $provider, $kind))
			|| (!empty($schedule['bedSensor']) && self::anchorEventMatches($schedule, 'bed', $provider, $kind)))
			return true;
		foreach($schedule['checkins'] as $c) {
			if($c['prompt'] === self::PROMPT_EVENT && self::checkinEventMatches($c, $provider, $kind))
				return true;
		}
		return false;
	}

	/**
	 * Does a check-in's window depend on wearable events, which only the server receives? The Android app plans
	 * the others on the device and gets these from the server (NativePending).
	 */
	public static function needsServer(array $schedule, int $index): bool {
		$c = $schedule['checkins'][$index] ?? null;
		if($c === null)
			return false;
		if($c['prompt'] === self::PROMPT_EVENT)
			return true;
		if(!empty($schedule['wakeSensor'])) {
			if($c['prompt'] === self::PROMPT_WAKE)
				return true;
			foreach(['start', 'end'] as $side) {
				if(in_array($c[$side . 'Type'], [self::TYPE_WAKE, self::TYPE_SPLIT], true))
					return true;
			}
		}
		if(!empty($schedule['bedSensor'])) {
			foreach(['start', 'end'] as $side) {
				if($c[$side . 'Type'] === self::TYPE_BED)
					return true;
			}
		}
		return false;
	}

	// ── pure part (ported to schedule.ts) ───────────────────────────────────────────────────────────

	/**
	 * A time answer as ms since midnight: "HH:MM" / "HH:MM:SS", what time items save. Anything else is null:
	 * empty, "7am", out of range, and plain numbers, which are the minutes a duration picker saves.
	 */
	public static function parseTimeOfDay($value): ?int {
		$s = trim((string) ($value ?? ''));
		if(!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $s, $m))
			return null;
		[$h, $min, $sec] = [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];
		return ($h < 24 && $min < 60 && $sec < 60) ? (($h * 60 + $min) * 60 + $sec) * 1000 : null;
	}

	/** A participant variable as a time of day plus an offset (wraps over midnight), or null when unanswered. */
	private static function variableTod(string $name, int $offsetMin, array $variables): ?int {
		$name = trim($name);
		if($name === '')
			return null;
		$entry = $variables[$name] ?? null;
		$tod = self::parseTimeOfDay(is_array($entry) ? ($entry['v'] ?? null) : $entry);
		if($tod === null)
			return null;
		return (($tod + $offsetMin * self::MINUTE) % self::ONE_DAY + self::ONE_DAY) % self::ONE_DAY;
	}

	/** The usual wake-up: participant variable + offset (on a weekend day the weekend variable first), else the default. */
	public static function wakeFallbackTod(array $schedule, array $variables, bool $weekend = false): ?int {
		$tod = $weekend ? self::variableTod((string) $schedule['wakeVariableWeekend'], (int) $schedule['wakeOffsetMin'], $variables) : null;
		$tod = $tod ?? self::variableTod((string) $schedule['wakeVariable'], (int) $schedule['wakeOffsetMin'], $variables);
		if($tod !== null)
			return $tod;
		return (int) $schedule['wakeDefault'] >= 0 ? (int) $schedule['wakeDefault'] : null;
	}

	/** The planned bedtime: participant variable + offset (on Friday and Saturday nights the weekend one first), else the default. */
	public static function resolveBedTod(array $schedule, array $variables, bool $weekendNight = false): int {
		$tod = $weekendNight ? self::variableTod((string) $schedule['bedVariableWeekend'], (int) $schedule['bedOffsetMin'], $variables) : null;
		$tod = $tod ?? self::variableTod((string) $schedule['bedVariable'], (int) $schedule['bedOffsetMin'], $variables);
		return $tod ?? max(0, (int) $schedule['bedDefault']);
	}

	/**
	 * Wake-up time of one day, or null while it is not known yet (nothing observed and the fallback time has not come).
	 * @param int[] $observedTods that day's observed wake-ups (sensor events, answers to the wake-up check-in)
	 * @param int   $nowTod       now, relative to that day's local midnight (> one day for a past day)
	 */
	public static function resolveWakeTod(array $schedule, array $observedTods, ?int $fallback, int $nowTod): ?int {
		if(empty($schedule['wakeSensor']) && empty($schedule['wakeFromCheckin']))
			return $fallback;
		$first = self::firstObservedWake($schedule, $observedTods);
		if($first !== null && ($fallback === null || $first <= $fallback))
			return $first;
		if($fallback !== null && $nowTod >= $fallback)
			return $fallback;
		return null;
	}

	/** The earliest observed wake-up inside the wake-up window (whole minutes), or null when none is (yet). */
	public static function firstObservedWake(array $schedule, array $observedTods): ?int {
		$first = null;
		foreach($observedTods as $tod) {
			if(self::inWindow((int) $schedule['wakeWindowStart'], (int) $schedule['wakeWindowEnd'], (int) $tod) && ($first === null || $tod < $first))
				$first = (int) $tod;
		}
		return $first === null ? null : intdiv($first, self::MINUTE) * self::MINUTE;
	}

	/** An event prompt's fallback time of day: fixed, or the participant's variable + offset (else fixed if allowed); null for none. */
	public static function eventFallbackTod(array $checkin, array $variables): ?int {
		$fixed = (int) $checkin['fallbackTime'] >= 0 ? (int) $checkin['fallbackTime'] : null;
		switch($checkin['fallback']) {
			case self::FALLBACK_NONE:
				return null;
			case self::FALLBACK_VARIABLE:
				$tod = self::variableTod((string) $checkin['fallbackVariable'], (int) $checkin['fallbackOffsetMin'], $variables);
				return $tod ?? (!empty($checkin['fallbackDefault']) ? $fixed : null);
			default:
				return $fixed;
		}
	}

	/**
	 * When an event check-in is prompted on one day: the first matching event inside its event window (whole minutes)
	 * plus the delay, if it comes no later than the fallback; else the fallback once it has come; else null.
	 * @param int[] $eventTods that day's matching events relative to its midnight
	 */
	public static function resolveEventTod(array $checkin, array $eventTods, ?int $fallback, int $nowTod, string $seed): ?int {
		$first = null;
		foreach($eventTods as $tod) {
			if(self::inWindow((int) $checkin['eventFrom'], (int) $checkin['eventUntil'], (int) $tod) && ($first === null || $tod < $first))
				$first = (int) $tod;
		}
		if($first !== null && ($fallback === null || $first <= $fallback)) {
			$min = max(0, (int) $checkin['eventDelayMin']);
			$max = (int) $checkin['eventDelayMaxMin'];
			$delay = $max > $min ? $min + NotificationSpec::hash32("$seed:delay") % ($max - $min + 1) : $min;
			return intdiv($first, self::MINUTE) * self::MINUTE + $delay * self::MINUTE;
		}
		if($fallback !== null && $nowTod >= $fallback)
			return $fallback;
		return null;
	}

	/** Is a time of day inside [start, end] (wrapping over midnight)? A negative end means no window. */
	private static function inWindow(int $start, int $end, int $tod): bool {
		if($start < 0 || $end < 0)
			return true;
		return $start <= $end ? ($tod >= $start && $tod <= $end) : ($tod >= $start || $tod <= $end);
	}

	/**
	 * When the participant went to bed, relative to the day's midnight: the first in-bed event of the night inside
	 * the bed window, after waking up and not after the planned bedtime (the fallback, used otherwise).
	 */
	public static function bedEndTod(array $schedule, array $eventTods, ?int $wake, int $bedAdj): int {
		if(empty($schedule['bedSensor']))
			return $bedAdj;
		$start = (int) $schedule['bedWindowStart'];
		$end = (int) $schedule['bedWindowEnd'];
		if($end <= $start)
			$end += self::ONE_DAY;
		$first = null;
		foreach($eventTods as $tod) {
			$tod = (int) $tod;
			if(($start < 0 || ($tod >= $start && $tod <= $end)) && $tod >= ($wake ?? 0) && $tod <= $bedAdj && ($first === null || $tod < $first))
				$first = $tod;
		}
		return $first === null ? $bedAdj : intdiv($first, self::MINUTE) * self::MINUTE;
	}

	/** `wake` prompt: the observed wake-up if no later than the usual wake-up + the grace period, else that time. */
	private static function wakePromptTod(array $checkin, ?int $seen, ?int $wakeBy): ?int {
		$fallback = $wakeBy === null ? null : $wakeBy + (int) $checkin['promptGraceMin'] * self::MINUTE;
		$at = $seen !== null && ($fallback === null || $seen <= $fallback) ? $seen : $fallback;
		return $at === null ? null : intdiv($at, self::MINUTE) * self::MINUTE;
	}

	/**
	 * Does a check-in run on this day?
	 * @param array $day index (days since the enrolment day), weekday (0 = Sunday), dayOfMonth
	 */
	public static function runsOnDay(array $checkin, array $day): bool {
		$index = (int) $day['index'];
		$from = (int) $checkin['dayFrom'];
		$until = (int) $checkin['dayUntil'];
		if($index < $from || ($until >= 0 && $index > $until))
			return false;
		if(($index - $from) % max(1, (int) $checkin['everyDays']) !== 0)
			return false;
		$weekdays = (int) $checkin['weekdays'];
		if($weekdays !== 0 && ($weekdays & (1 << (int) $day['weekday'])) === 0)
			return false;
		$dom = (int) $checkin['dayOfMonth'];
		return $dom === 0 || $dom === (int) $day['dayOfMonth'];
	}

	/**
	 * The day's check-in windows (times of day), ordered by when they open. A window can be answered from `open`
	 * until `close` and is prompted at `prompt` when `notify` is set. Check-ins whose range needs a wake-up not known
	 * yet, or whose prompt (wake-up, event) is not known yet and that are only open from it, are left out. While the
	 * wake-up is unknown and observed from the wake-up check-in, that check-in is open early (`early`: from the start
	 * of the wake-up window until the usual wake-up), without a prompt.
	 * @param array $day the day:
	 *   index, weekday, dayOfMonth  for the check-ins' days (runsOnDay)
	 *   base         int    the day's local midnight in absolute ms (dateFrom / dateUntil)
	 *   wake         ?int   wake-up time of day (null = not known yet)
	 *   wakeBy       ?int   the usual wake-up (wakeFallbackTod)
	 *   wakeSeen     ?int   the day's earliest wake-up event from the wearable (firstObservedWake), for wake prompts
	 *   bed          int    planned bedtime (resolveBedTod)
	 *   bedEvents    int[]  in-bed events relative to the day's midnight (bedEndTod)
	 *   wakeNext     ?int   the next day's wake-up, if known
	 *   eventPrompts array  check-in index => event prompt time of day or null (resolveEventTod)
	 * @param string $seed draws the random prompts (studyId:userId:date), stable per participant and day
	 * @return array<int, array{checkin:int, qid:int, start:int, end:int, open:int, prompt:int, close:int, notify:bool, early:bool}>
	 */
	public static function windowsForDay(array $schedule, array $day, string $seed): array {
		$wake = $day['wake'] ?? null;
		$wakeBy = $day['wakeBy'] ?? null;
		$bed = (int) $day['bed'];
		$base = (int) ($day['base'] ?? 0);
		$checkins = $schedule['checkins'];
		$overlap = $schedule['overlap'];
		$dayEnd = self::ONE_DAY + (int) $schedule['dayStart'];
		$ctx = [
			'wake' => $wake,
			'wakeBy' => $wakeBy,
			'segments' => (int) $schedule['segments'] > 0 ? (int) $schedule['segments'] : count($checkins),
			// The planned bedtime ends the waking day: on the next calendar day when it is not after waking up (or,
			// while that is unknown, when it is before noon).
			'bedAdj' => ($wake !== null ? $bed <= $wake : $bed < self::ONE_DAY / 2) ? $bed + self::ONE_DAY : $bed,
			'wakeNext' => $day['wakeNext'] ?? $wakeBy, // not known yet: the latest it can be
		];
		$ctx['bedEnd'] = self::bedEndTod($schedule, $day['bedEvents'] ?? [], $wake, $ctx['bedAdj']);

		$ranges = [];
		foreach($checkins as $i => $c) {
			if(!self::runsOnDay($c, $day))
				continue;
			$start = self::boundary($c, 'start', $ctx);
			$end = self::boundary($c, 'end', $ctx);
			if($start === null || $end === null)
				continue;
			if($end < $start) // a clock time before the start is the next day's; any other end leaves no range
				$end = $c['endType'] === self::TYPE_FIXED && empty($c['endNextDay']) ? $end + self::ONE_DAY : $start;
			$ranges[] = ['checkin' => $i, 'start' => $start, 'end' => $end];
		}
		usort($ranges, fn($a, $b) => [$a['start'], $a['checkin']] <=> [$b['start'], $b['checkin']]);

		$out = [];
		$prevClose = null;
		foreach($ranges as ['checkin' => $i, 'start' => $start, 'end' => $end]) {
			$c = $checkins[$i];
			$parallel = !empty($c['parallel']);
			// OVERLAP_DEFER: a check-in opens no earlier than the previous one's window closes.
			$earliest = !$parallel && $overlap === self::OVERLAP_DEFER && $prevClose !== null ? max($start, $prevClose) : $start;
			$last = max($end, $earliest);
			$prompt = $earliest;
			$notify = $c['prompt'] !== self::PROMPT_NONE;
			$at = $earliest;
			if($c['prompt'] === self::PROMPT_RANDOM) {
				$minutes = intdiv($last - $earliest, self::MINUTE);
				$at = $earliest + ($minutes > 0 ? (NotificationSpec::hash32("$seed:$i") % ($minutes + 1)) * self::MINUTE : 0);
			}
			else if($c['prompt'] === self::PROMPT_WAKE)
				$at = self::wakePromptTod($c, $day['wakeSeen'] ?? null, $wakeBy);
			else if($c['prompt'] === self::PROMPT_EVENT)
				$at = $day['eventPrompts'][$i] ?? null;
			if($at === null) { // not known yet: only a window open throughout its range exists, unprompted
				if($c['available'] !== self::AVAILABLE_RANGE)
					continue;
				$notify = false;
			}
			else
				$prompt = min(max($at, $earliest), $last);
			if($c['available'] === self::AVAILABLE_RANGE) {
				$open = $earliest;
				$close = $last;
			}
			else if($c['available'] === self::AVAILABLE_DAY) {
				$open = $prompt;
				$close = $dayEnd;
			}
			else {
				$response = !empty($c['responseOverride']) ? (int) $c['responseMinutes'] : (int) $schedule['responseMinutes'];
				$open = $prompt;
				$close = $response > 0 ? $prompt + $response * self::MINUTE : $last;
			}
			if(((int) $c['dateFrom'] > 0 && $base + $open < (int) $c['dateFrom']) || ((int) $c['dateUntil'] > 0 && $base + $open > (int) $c['dateUntil']))
				continue;
			if(!$parallel)
				$prevClose = $prevClose === null ? $close : max($prevClose, $close);
			$out[] = [
				'checkin' => $i, 'qid' => (int) $c['questionnaireId'], 'start' => $start, 'end' => $end,
				'open' => $open, 'prompt' => $prompt, 'close' => $close, 'notify' => $notify, 'early' => false,
			];
		}
		$wakeCheckin = self::wakeCheckinIndex($schedule);
		if($wake === null && $wakeCheckin >= 0 && self::runsOnDay($checkins[$wakeCheckin], $day)) {
			$start = max(0, (int) $schedule['wakeWindowStart']);
			$close = $wakeBy ?? ((int) $schedule['wakeWindowEnd'] >= 0 ? (int) $schedule['wakeWindowEnd'] : self::ONE_DAY);
			if($close < $start)
				$close += self::ONE_DAY;
			$out[] = [
				'checkin' => $wakeCheckin, 'qid' => (int) $checkins[$wakeCheckin]['questionnaireId'], 'start' => $start, 'end' => $close,
				'open' => $start, 'prompt' => $start, 'close' => $close, 'notify' => false, 'early' => true,
			];
		}
		usort($out, fn($a, $b) => [$a['open'], $a['checkin']] <=> [$b['open'], $b['checkin']]);
		// One check-in at a time: a window closes when the next one opens (OVERLAP_TRUNCATE, the early window; with
		// OVERLAP_DEFER the regular windows already follow one another). Parallel check-ins are left out.
		$next = null;
		for($k = count($out) - 1; $k >= 0; $k--) {
			if(!empty($checkins[$out[$k]['checkin']]['parallel']))
				continue;
			if($next !== null)
				$out[$k]['close'] = min($out[$k]['close'], $next);
			$next = $out[$k]['open'];
		}
		return self::closed($out);
	}

	/** Drops windows left without time and the prompts that would come once a window has closed. */
	private static function closed(array $windows): array {
		$out = [];
		foreach($windows as $w) {
			if($w['close'] <= $w['open'])
				continue;
			$w['notify'] = $w['notify'] && $w['prompt'] < $w['close'];
			$out[] = $w;
		}
		return $out;
	}

	/**
	 * One check-in at a time across midnight too: a window of the earlier day that runs into the next day closes
	 * when the next day's first check-in opens (parallel check-ins are left out).
	 * @param array $earlier, $later windows with absolute (or same-base) times
	 */
	public static function capAcrossDays(array $schedule, array $earlier, array $later): array {
		$first = null;
		foreach($later as $w) {
			if(empty($schedule['checkins'][$w['checkin']]['parallel']))
				$first = $first === null ? $w['open'] : min($first, $w['open']);
		}
		if($first === null)
			return $earlier;
		foreach($earlier as $k => $w) {
			if(empty($schedule['checkins'][$w['checkin']]['parallel']))
				$earlier[$k]['close'] = min($w['close'], $first);
		}
		return self::closed($earlier);
	}

	/**
	 * Applies the participant's completions to absolute windows (days()):
	 *  - `done`: by the check-in's limit, a completion with the window's key for `window` (a completion without a key,
	 *    from an upload, counts for the earliest window of its questionnaire that it falls into and that is not done
	 *    yet), inside the window's study day [dayFrom, dayTo) for `day`, any for `once`; never for `none`;
	 *  - the cool-down (check-ins that are not parallel): a window opens, and is prompted, no earlier than
	 *    `cooldownMinutes` after a completion of another such questionnaire (before the window closed) or of the same
	 *    one before the window opened;
	 *  - `minGapMinutes`: no earlier than that long after a completion of its own questionnaire.
	 * A window the cool-down or gap outlasts is missed. `answered` is set when its questionnaire was completed since
	 * the window opened (reminders stop).
	 * @param array $completions [['qid' => int, 't' => ms, 'key' => ?string], …]
	 */
	public static function applyCompletions(array $schedule, array $windows, array $completions): array {
		$cooldown = max(0, (int) $schedule['cooldownMinutes']) * self::MINUTE;
		$exclusive = []; // questionnaires with a check-in that is not parallel: only they start and receive the cool-down
		foreach($schedule['checkins'] as $c) {
			if(empty($c['parallel']))
				$exclusive[(int) $c['questionnaireId']] = true;
		}
		// Completions of a window: by its key, and each completion without a key for one window it falls into.
		$claimed = [];
		foreach($windows as $k => $w) {
			foreach($completions as $completion) {
				if((int) $completion['qid'] === $w['qid'] && ($completion['key'] ?? null) === $w['key'])
					$claimed[$k] = true;
			}
		}
		$order = array_keys($windows);
		usort($order, fn($a, $b) => [$windows[$a]['open'], $windows[$a]['checkin']] <=> [$windows[$b]['open'], $windows[$b]['checkin']]);
		$keyless = array_values(array_filter($completions, fn($c) => ($c['key'] ?? null) === null));
		usort($keyless, fn($a, $b) => (int) $a['t'] <=> (int) $b['t']);
		foreach($keyless as $completion) {
			foreach($order as $k) {
				$w = $windows[$k];
				if(!isset($claimed[$k]) && (int) $completion['qid'] === $w['qid'] && (int) $completion['t'] >= $w['open'] && (int) $completion['t'] < $w['close']) {
					$claimed[$k] = true;
					break;
				}
			}
		}
		$out = [];
		foreach($windows as $index => $w) {
			$c = $schedule['checkins'][$w['checkin']];
			$cooled = $cooldown > 0 && empty($c['parallel']);
			$limit = $c['limit'];
			$gap = max(0, (int) $c['minGapMinutes']) * self::MINUTE;
			$done = false;
			$answered = false;
			$from = $w['open'];
			foreach($completions as $completion) {
				$t = (int) $completion['t'];
				$same = (int) $completion['qid'] === $w['qid'];
				if($same) {
					if($limit === self::LIMIT_ONCE
						|| ($limit === self::LIMIT_DAY && $t >= $w['dayFrom'] && $t < $w['dayTo'])
						|| ($limit === self::LIMIT_WINDOW && isset($claimed[$index])))
						$done = true;
					if($t >= $w['open'])
						$answered = true;
					if($gap > 0 && $t < $w['close'])
						$from = max($from, $t + $gap);
				}
				if($cooled && isset($exclusive[(int) $completion['qid']]) && $t < $w['close'] && (!$same || $t < $w['open']))
					$from = max($from, $t + $cooldown);
			}
			$w['done'] = $done;
			$w['answered'] = $answered || $done;
			if($from > $w['open']) {
				$w['open'] = $from;
				$w['prompt'] = max($w['prompt'], $from);
			}
			$out[] = $w;
		}
		return self::closed($out);
	}

	/** One end of a check-in's range as a time of day (whole minutes), or null while the wake-up time is unknown. */
	private static function boundary(array $c, string $side, array $ctx): ?int {
		$offset = (int) $c[$side . 'OffsetMin'] * self::MINUTE;
		$nextDay = !empty($c[$side . 'NextDay']);
		$wake = $ctx['wake'];
		switch($c[$side . 'Type']) {
			case self::TYPE_FIXED:
				$value = (int) $c[$side . 'Time'];
				if($value < 0)
					return null;
				$value += $nextDay ? self::ONE_DAY : 0;
				break;
			case self::TYPE_WAKE:
				if($nextDay)
					$wake = $ctx['wakeNext'] === null ? null : self::ONE_DAY + $ctx['wakeNext'];
				if($wake === null)
					return null;
				$value = $wake + $offset;
				break;
			case self::TYPE_USUAL_WAKE:
				if($ctx['wakeBy'] === null)
					return null;
				$value = $ctx['wakeBy'] + $offset + ($nextDay ? self::ONE_DAY : 0);
				break;
			case self::TYPE_BED:
				$value = $ctx['bedEnd'] + $offset;
				break;
			default: // split
				if($wake === null)
					return null;
				$value = $wake + intdiv(($ctx['bedAdj'] - $wake) * (int) $c[$side . 'Segment'], $ctx['segments']);
		}
		return intdiv($value, self::MINUTE) * self::MINUTE;
	}

	/**
	 * A questionnaire's state from absolute windows that have completions applied: `available` (with the window's key)
	 * inside an open window not done, else `upcoming` (before its first day), `locked` until the next window,
	 * `completed` (limit once, done), `ended` (after its last day), or `locked`. `opensAt` is the next window's opening
	 * when it is among the windows.
	 * @return array{state: string, occKey: ?string, opensAt: ?int}
	 */
	public static function availability(array $schedule, int $qid, array $windows, int $nowMs, int $dayIndex): array {
		$mine = array_values(array_filter($windows, fn($w) => $w['qid'] === $qid));
		usort($mine, fn($a, $b) => $a['open'] <=> $b['open']);
		foreach($mine as $w) {
			if($nowMs >= $w['open'] && $nowMs < $w['close'] && empty($w['done']))
				return ['state' => 'available', 'occKey' => $w['key'], 'opensAt' => null];
		}
		$checkins = array_values(array_filter($schedule['checkins'], fn($c) => (int) $c['questionnaireId'] === $qid));
		if(!$checkins)
			return ['state' => 'locked', 'occKey' => null, 'opensAt' => null];
		$next = null;
		foreach($mine as $w) {
			if($w['open'] > $nowMs && empty($w['done'])) {
				$next = $w['open'];
				break;
			}
		}
		$once = true;
		$before = true;
		$after = true;
		foreach($checkins as $c) {
			$once = $once && $c['limit'] === self::LIMIT_ONCE;
			$before = $before && ($dayIndex < (int) $c['dayFrom'] || ((int) $c['dateFrom'] > 0 && $nowMs < (int) $c['dateFrom']));
			$after = $after && (((int) $c['dayUntil'] >= 0 && $dayIndex > (int) $c['dayUntil']) || ((int) $c['dateUntil'] > 0 && $nowMs > (int) $c['dateUntil']));
		}
		if($before)
			return ['state' => 'upcoming', 'occKey' => null, 'opensAt' => $next];
		if($next !== null)
			return ['state' => 'locked', 'occKey' => null, 'opensAt' => $next];
		if($once && array_filter($mine, fn($w) => !empty($w['done'])))
			return ['state' => 'completed', 'occKey' => null, 'opensAt' => null];
		if($after)
			return ['state' => 'ended', 'occKey' => null, 'opensAt' => null];
		return ['state' => 'locked', 'occKey' => null, 'opensAt' => null];
	}

	/**
	 * Can the schedule end for a participant? Every check-in has a last day or date, or is answered once in the study.
	 * A schedule that runs on forever never ends (ended() is not worth computing).
	 */
	public static function canEnd(array $schedule): bool {
		if(!$schedule['checkins'])
			return false;
		foreach($schedule['checkins'] as $c) {
			if((int) $c['dayUntil'] < 0 && (int) $c['dateUntil'] <= 0 && $c['limit'] !== self::LIMIT_ONCE)
				return false;
		}
		return true;
	}

	/**
	 * Has the participant's study ended: every scheduled questionnaire `ended` or `completed` (as the app's list then
	 * says "You have completed the study"), so nothing is open and nothing more will be? `just`: and the participant
	 * had a check-in window yesterday or today; `long`: it ended before that; null: it has not ended.
	 * @param array $days days() from yesterday to today
	 */
	public static function ended(array $schedule, array $days, int $nowMs): ?string {
		$windows = self::windowsOf($days);
		$today = end($days);
		$dayIndex = $today === false ? 0 : (int) $today['index'];
		foreach(array_unique(array_map(fn($c) => (int) $c['questionnaireId'], $schedule['checkins'])) as $qid) {
			$state = self::availability($schedule, $qid, $windows, $nowMs, $dayIndex)['state'];
			if($state !== 'ended' && $state !== 'completed')
				return null;
		}
		return $windows ? self::ENDED_JUST : self::ENDED_LONG;
	}

	// ── one participant ─────────────────────────────────────────────────────────────────────────────

	/**
	 * The participant's inputs, read from disk: wearable events (only when the schedule reads them), the answers the
	 * schedule reads, and their completions.
	 * @return array{events: array, variables: array, completions: array}
	 */
	public static function inputs(array $study, int $studyId, string $userId, int $nowMs): array {
		return [
			'events' => self::usesSensor($study) ? WearablesEventStore::readSince($studyId, $userId, $nowMs - self::EVENT_LOOKBACK_MS) : [],
			'variables' => self::variableNames($study) ? ParticipantVariableStore::read($studyId, $userId) : [],
			'completions' => ParticipantVariableStore::completions($studyId, $userId),
		];
	}

	/**
	 * The participant's windows from $fromDay to $toDay (days relative to today, e.g. -1 … 0), as absolute times
	 * (UTC ms), with completions applied. Each window gets its key (`YYYY-MM-DD:<check-in>`) and its study day
	 * [dayFrom, dayTo). A day whose wake-up is not known yet is planned as if it came at the fallback when
	 * $assumeWake is set (prompts planned ahead, the preview).
	 * @param array $inputs inputs()
	 * @param int   $joinedMs enrolment (day 0 is its local day)
	 * @return array<int, array{date:string, index:int, wake:?int, wakeBy:?int, bed:int, windows:array}>
	 */
	public static function days(array $study, int $studyId, string $userId, array $inputs, int $joinedMs, int $nowMs, int $tzOffsetMin, int $fromDay = -1, int $toDay = 0, bool $assumeWake = false): array {
		$schedule = self::config($study);
		if($schedule === null)
			return [];
		$offsetMs = $tzOffsetMin * self::MINUTE;
		$todayLocal = intdiv($nowMs - $offsetMs, self::ONE_DAY) * self::ONE_DAY;
		$joinedLocal = intdiv($joinedMs - $offsetMs, self::ONE_DAY) * self::ONE_DAY;
		$variables = $inputs['variables'] ?? [];
		$completions = $inputs['completions'] ?? [];
		$wakeByOf = fn(int $dayLocal) => self::wakeFallbackTod($schedule, $variables, self::isWeekend($dayLocal));

		$wakeEvents = [];
		$bedEvents = [];
		foreach(($inputs['events'] ?? []) as $e) {
			$provider = (string) ($e['provider'] ?? '');
			$kind = (string) ($e['kind'] ?? '');
			if(!empty($schedule['wakeSensor']) && self::anchorEventMatches($schedule, 'wake', $provider, $kind))
				$wakeEvents[] = (int) $e['t'];
			if(!empty($schedule['bedSensor']) && self::anchorEventMatches($schedule, 'bed', $provider, $kind))
				$bedEvents[] = (int) $e['t'];
		}
		$observed = $wakeEvents;
		$wakeCheckin = self::wakeCheckinIndex($schedule);
		if($wakeCheckin >= 0) { // answering the wake-up check-in marks wake-up
			$wakeQid = (int) $schedule['checkins'][$wakeCheckin]['questionnaireId'];
			foreach($completions as $c) {
				if((int) $c['qid'] === $wakeQid)
					$observed[] = (int) $c['t'];
			}
		}
		// Times relative to a day's local midnight, within [from, to).
		$relative = function(array $times, int $dayLocal, int $from, int $to) use ($offsetMs): array {
			$out = [];
			foreach($times as $t) {
				$rel = $t - $offsetMs - $dayLocal;
				if($rel >= $from && $rel < $to)
					$out[] = $rel;
			}
			return $out;
		};
		$wakeOf = function(int $dayLocal) use ($schedule, $relative, $observed, $wakeByOf, $nowMs, $offsetMs, $assumeWake): ?int {
			$wake = self::resolveWakeTod($schedule, $relative($observed, $dayLocal, 0, self::ONE_DAY), $wakeByOf($dayLocal), $nowMs - $offsetMs - $dayLocal);
			return $wake ?? ($assumeWake ? $wakeByOf($dayLocal) : null);
		};

		$out = [];
		for($d = $fromDay; $d <= $toDay; $d++) {
			$dayLocal = $todayLocal + $d * self::ONE_DAY;
			$date = gmdate('Y-m-d', intdiv($dayLocal, 1000));
			$base = $dayLocal + $offsetMs;
			$nowTod = $assumeWake ? 2 * self::ONE_DAY : $nowMs - $offsetMs - $dayLocal;
			$eventPrompts = [];
			foreach($schedule['checkins'] as $i => $c) {
				if($c['prompt'] !== self::PROMPT_EVENT)
					continue;
				$matching = [];
				foreach(($inputs['events'] ?? []) as $e) {
					if(self::checkinEventMatches($c, (string) ($e['provider'] ?? ''), (string) ($e['kind'] ?? '')))
						$matching[] = (int) $e['t'];
				}
				$eventPrompts[$i] = self::resolveEventTod($c, $relative($matching, $dayLocal, 0, self::ONE_DAY), self::eventFallbackTod($c, $variables), $nowTod, "$studyId:$userId:$date:$i");
			}
			$wake = $wakeOf($dayLocal);
			$wakeBy = $wakeByOf($dayLocal);
			$bed = self::resolveBedTod($schedule, $variables, self::isWeekend($dayLocal + self::ONE_DAY));
			$day = [
				'index' => intdiv($dayLocal - $joinedLocal, self::ONE_DAY),
				'weekday' => (int) gmdate('w', intdiv($dayLocal, 1000)),
				'dayOfMonth' => (int) gmdate('j', intdiv($dayLocal, 1000)),
				'base' => $base,
				'wake' => $wake, 'wakeBy' => $wakeBy, 'bed' => $bed,
				'wakeSeen' => self::firstObservedWake($schedule, $relative($wakeEvents, $dayLocal, 0, self::ONE_DAY)),
				'bedEvents' => $relative($bedEvents, $dayLocal, 0, 2 * self::ONE_DAY),
				'wakeNext' => $wakeOf($dayLocal + self::ONE_DAY) ?? $wakeByOf($dayLocal + self::ONE_DAY), // not known yet: its fallback
				'eventPrompts' => $eventPrompts,
			];
			$windows = [];
			foreach(self::windowsForDay($schedule, $day, "$studyId:$userId:$date") as $w) {
				// The study day the window opens in (a window opening at 02:00 with days from 04:00 is the evening before's).
				$dayFrom = $base + (int) $schedule['dayStart'] + intdiv($w['open'] - (int) $schedule['dayStart'] + self::ONE_DAY, self::ONE_DAY) * self::ONE_DAY - self::ONE_DAY;
				$windows[] = [
					'checkin' => $w['checkin'], 'qid' => $w['qid'], 'key' => "$date:{$w['checkin']}",
					'start' => $base + $w['start'], 'end' => $base + $w['end'], 'open' => $base + $w['open'],
					'prompt' => $base + $w['prompt'], 'close' => $base + $w['close'], 'notify' => $w['notify'], 'early' => $w['early'],
					'base' => $base, 'dayFrom' => $dayFrom, 'dayTo' => $dayFrom + self::ONE_DAY,
				];
			}
			$out[] = [
				'date' => $date,
				'index' => $day['index'],
				'wake' => $wake === null ? null : $base + $wake,
				'wakeBy' => $wakeBy === null ? null : $base + $wakeBy,
				'bed' => $base + $bed + (($wake !== null ? $bed <= $wake : $bed < self::ONE_DAY / 2) ? self::ONE_DAY : 0),
				'windows' => $windows,
			];
		}
		for($k = 0; $k + 1 < count($out); $k++)
			$out[$k]['windows'] = self::capAcrossDays($schedule, $out[$k]['windows'], $out[$k + 1]['windows']);
		foreach($out as $k => $day)
			$out[$k]['windows'] = self::applyCompletions($schedule, $day['windows'], $completions);
		return $out;
	}

	/** All windows of days() in one list. */
	public static function windowsOf(array $days): array {
		$out = [];
		foreach($days as $day) {
			foreach($day['windows'] as $w)
				$out[] = $w;
		}
		return $out;
	}

	/** Title and body of a check-in's notification for a window (deadline from its close). */
	public static function notification(array $study, array $schedule, array $w): array {
		$c = $schedule['checkins'][$w['checkin']];
		$q = null;
		foreach(($study['questionnaires'] ?? []) as $candidate) {
			if((int) ($candidate['internalId'] ?? -1) === $w['qid'])
				$q = $candidate;
		}
		$title = trim((string) $c['title']);
		if($title === '')
			$title = NotificationSpec::title($q ?? [], $study);
		$body = trim((string) $c['text']);
		if($body === '')
			$body = NotificationSpec::defaultBody(NotificationText::langFor($study));
		if(!empty($c['includeDeadline'])) {
			// The close relative to the window's day: midnight shows as 23:59, a close after midnight as that time.
			$closeTod = $w['close'] - $w['base'];
			$body = NotificationSpec::withDeadline($body, $closeTod > self::ONE_DAY ? $closeTod - self::ONE_DAY : $closeTod, NotificationText::langFor($study));
		}
		$delay = max(0, (int) $c['reminderDelayMin']) * self::MINUTE;
		return [$title, $body, $delay > 0 ? max(0, (int) $c['reminderCount']) : 0, $delay];
	}

	/** The prompt and reminder occurrences of windows, in the shape PushSender, NativePending and ChannelDispatcher consume. */
	private static function occurrence(string $type, array $w, string $title, string $body, int $timestamp): array {
		return [
			'type' => $type, 'qid' => $w['qid'], 'checkin' => $w['checkin'], 'title' => $title, 'body' => $body,
			'timestamp' => $timestamp, 'windowStart' => $w['open'], 'deadline' => $w['close'], 'occKey' => $w['key'],
			'dayFrom' => $w['dayFrom'], 'dayTo' => $w['dayTo'],
		];
	}

	/**
	 * Due prompts and their reminders. Keeps an idempotence ledger rather than a cursor, since a wake-up or event
	 * reported late can move a prompt into the past: each prompt and reminder is sent at most once, never more than
	 * MAX_AGE_MS late, never after its window closed and never for a window that is done. Reminders stop once the
	 * participant has completed the questionnaire since the window opened.
	 * @param array $ledger per-participant state, mutated: ['init' => ms, 'seen' => key => {t, sent}]
	 */
	public static function due(array $study, int $studyId, string $userId, array $inputs, int $joinedMs, int $tzOffsetMin, int $nowMs, array &$ledger): array {
		$schedule = self::config($study);
		if($schedule === null)
			return [];
		$ledger['seen'] = (isset($ledger['seen']) && is_array($ledger['seen'])) ? $ledger['seen'] : [];
		// First run for this participant: prompts due earlier are not back-filled, except those that follow a wearable
		// event or wake-up (an event that just arrived still prompts, as long as it is not stale).
		if(!isset($ledger['init']))
			$ledger['init'] = $nowMs;

		$out = [];
		foreach(self::windowsOf(self::days($study, $studyId, $userId, $inputs, $joinedMs, $nowMs, $tzOffsetMin)) as $w) {
			if(!$w['notify'] || !empty($w['done']) || $w['prompt'] > $nowMs)
				continue;
			[$title, $body, $reminderCount, $reminderDelayMs] = self::notification($study, $schedule, $w);
			$key = $w['key'];
			if(!isset($ledger['seen'][$key])) {
				$observed = in_array($schedule['checkins'][$w['checkin']]['prompt'], [self::PROMPT_EVENT, self::PROMPT_WAKE], true);
				$stale = $nowMs - $w['prompt'] > self::MAX_AGE_MS || $nowMs >= $w['close'] || (!$observed && $w['prompt'] < $ledger['init']);
				$ledger['seen'][$key] = ['t' => $w['prompt'], 'sent' => !$stale];
				if(!$stale)
					$out[] = self::occurrence('availability', $w, $title, $body, $w['prompt']);
			}
			if(empty($ledger['seen'][$key]['sent']))
				continue;
			for($k = 1; $k <= $reminderCount; $k++) {
				$rt = $w['prompt'] + $k * $reminderDelayMs;
				$rKey = "$key:r$k";
				if($rt > $nowMs || $rt >= $w['close'] || isset($ledger['seen'][$rKey]))
					continue;
				$fresh = $nowMs - $rt <= self::MAX_AGE_MS && empty($w['answered']);
				$ledger['seen'][$rKey] = ['t' => $rt, 'sent' => $fresh];
				if($fresh)
					$out[] = self::occurrence('reminder', $w, $title, $body, $rt);
			}
		}

		$cutoff = $nowMs - self::LEDGER_KEEP_DAYS * self::ONE_DAY;
		foreach($ledger['seen'] as $key => $entry) {
			if(!is_array($entry) || (int) ($entry['t'] ?? 0) < $cutoff)
				unset($ledger['seen'][$key]);
		}
		return $out;
	}

	/**
	 * Prompts and reminders from $fromMs to $toMs for windows planned ahead (a day whose wake-up or event is not known
	 * yet counts as its fallback), skipping windows that are done: the participant's "next notification", and the
	 * researcher preview.
	 */
	public static function upcoming(array $study, int $studyId, string $userId, array $inputs, int $joinedMs, int $tzOffsetMin, int $nowMs, int $fromMs, int $toMs): array {
		$schedule = self::config($study);
		if($schedule === null)
			return [];
		$offsetMs = $tzOffsetMin * self::MINUTE;
		$toDay = intdiv($toMs - $offsetMs, self::ONE_DAY) - intdiv($nowMs - $offsetMs, self::ONE_DAY) + 1;
		$fromDay = intdiv($fromMs - $offsetMs, self::ONE_DAY) - intdiv($nowMs - $offsetMs, self::ONE_DAY) - 1;
		$out = [];
		foreach(self::windowsOf(self::days($study, $studyId, $userId, $inputs, $joinedMs, $nowMs, $tzOffsetMin, $fromDay, $toDay, true)) as $w) {
			if(!$w['notify'] || !empty($w['done']))
				continue;
			[$title, $body, $reminderCount, $reminderDelayMs] = self::notification($study, $schedule, $w);
			if($w['prompt'] >= $fromMs && $w['prompt'] < $toMs)
				$out[] = self::occurrence('availability', $w, $title, $body, $w['prompt']);
			for($k = 1; $k <= $reminderCount; $k++) {
				$rt = $w['prompt'] + $k * $reminderDelayMs;
				if($rt >= $fromMs && $rt < $toMs && $rt < $w['close'])
					$out[] = self::occurrence('reminder', $w, $title, $body, $rt);
			}
		}
		usort($out, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
		return $out;
	}

	/** Is the local day starting at $dayLocal (a local midnight, offset 0) a Saturday or Sunday? */
	private static function isWeekend(int $dayLocal): bool {
		return in_array((int) gmdate('w', intdiv($dayLocal, 1000)), [0, 6], true);
	}

	/** @param string $anchor 'wake' or 'bed' */
	private static function anchorEventMatches(array $schedule, string $anchor, string $provider, string $kind): bool {
		$wanted = (string) $schedule[$anchor . 'Provider'];
		return $kind === (string) $schedule[$anchor . 'Event'] && ($wanted === 'any' || $wanted === $provider);
	}

	private static function checkinEventMatches(array $checkin, string $provider, string $kind): bool {
		$wanted = (string) $checkin['eventProvider'];
		return $kind === (string) $checkin['eventKind'] && ($wanted === 'any' || $wanted === $provider);
	}
}
