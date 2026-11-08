import { DataStructure } from "../DataStructure";

/**
 * The study's one schedule (study JSON: schedule), edited in sections/schedule.tsx and run on every channel by
 * backend/schedule/Schedule.php and its port web-pwa/src/lib/schedule.ts. Keep the constants and defaults in sync
 * with both. Questionnaires carry no scheduling settings: one without a check-in is never open and never prompted.
 *
 * Types of one end of a check-in's range (startType / endType):
 *  - fixed: a clock time (with *NextDay: on the next day);
 *  - wake: the participant's wake-up plus an offset (with *NextDay: the next day's wake-up);
 *  - bed: going to bed (the end of this waking day) plus an offset;
 *  - usualWake: the participant's usual wake-up (the wake-up fallback, never the observed one) plus an offset;
 *  - split: point k of the waking day (wake-up to the planned bedtime) divided into `segments` equal parts.
 */
export const BOUNDARY_FIXED = "fixed"
export const BOUNDARY_WAKE = "wake"
export const BOUNDARY_BED = "bed"
export const BOUNDARY_SPLIT = "split"
export const BOUNDARY_USUAL_WAKE = "usualWake"
export const PROMPT_START = "start"
export const PROMPT_RANDOM = "random"
/** At the observed wake-up (wearable event), else the usual wake-up plus promptGraceMin. */
export const PROMPT_WAKE = "wake"
/** At a wearable event (eventProvider / eventKind), else at its fallback. */
export const PROMPT_EVENT = "event"
export const PROMPT_NONE = "none"
/** When a check-in can be answered: from its prompt for the response window, throughout its range, or until the day ends. */
export const AVAILABLE_PROMPT = "prompt"
export const AVAILABLE_RANGE = "range"
export const AVAILABLE_DAY = "day"
/** How often it can be completed: once per window, per study day, once in the study, or as often as wanted. */
export const LIMIT_WINDOW = "window"
export const LIMIT_DAY = "day"
export const LIMIT_ONCE = "once"
export const LIMIT_NONE = "none"
/** An event prompt's fallback: a clock time, the participant's answer to a time item plus an offset, or none. */
export const FALLBACK_FIXED = "fixed"
export const FALLBACK_VARIABLE = "variable"
export const FALLBACK_NONE = "none"
/** Overlapping check-ins: prompt the next one when the previous window closes, or close that window early. */
export const OVERLAP_DEFER = "defer"
export const OVERLAP_TRUNCATE = "truncate"

/** Open all day, every day, as often as the participant likes. What a study's first questionnaire starts with in the dashboard. */
export const ALWAYS_OPEN_CHECKIN = {
	parallel: true,
	startType: BOUNDARY_FIXED,
	startTime: 0,
	endType: BOUNDARY_FIXED,
	endTime: 0,
	endNextDay: true,
	prompt: PROMPT_NONE,
	available: AVAILABLE_RANGE,
	limit: LIMIT_NONE,
}

/**
 * One check-in: a questionnaire, the days it runs on, its daily time range, when it is prompted, when it can be
 * answered, how often it can be completed, and its notification.
 */
export class ScheduleCheckin extends DataStructure {
	public questionnaireId		= this.primitive<number>("questionnaireId", -1)
	/** Days counted from the enrolment day (day 0): from dayFrom to dayUntil (-1 = no end), every everyDays days. */
	public dayFrom				= this.primitive<number>("dayFrom", 0)
	public dayUntil				= this.primitive<number>("dayUntil", -1)
	public everyDays			= this.primitive<number>("everyDays", 1)
	/** Bitmask, Sunday = bit 0; 0 = every day. */
	public weekdays				= this.primitive<number>("weekdays", 0)
	public dayOfMonth			= this.primitive<number>("dayOfMonth", 0)
	/** Absolute dates (ms): no window opens before / after; 0 = none. */
	public dateFrom				= this.primitive<number>("dateFrom", 0)
	public dateUntil			= this.primitive<number>("dateUntil", 0)
	public startType			= this.primitive<string>("startType", BOUNDARY_SPLIT)
	public startTime			= this.primitive<number>("startTime", 32400000)	// 09:00
	public startOffsetMin		= this.primitive<number>("startOffsetMin", 0)
	public startSegment			= this.primitive<number>("startSegment", 0)
	/** BOUNDARY_FIXED: the clock time on the next day; BOUNDARY_WAKE: the next day's wake-up. */
	public startNextDay			= this.primitive<boolean>("startNextDay", false)
	public endType				= this.primitive<string>("endType", BOUNDARY_SPLIT)
	public endTime				= this.primitive<number>("endTime", 39600000)		// 11:00
	public endOffsetMin			= this.primitive<number>("endOffsetMin", 0)
	public endSegment			= this.primitive<number>("endSegment", 1)
	public endNextDay			= this.primitive<boolean>("endNextDay", false)
	/**
	 * PROMPT_START: at the start of the range; PROMPT_RANDOM: at a random minute inside it (per participant and day);
	 * PROMPT_WAKE: at the observed wake-up, else promptGraceMin after the usual wake-up; PROMPT_EVENT: at a wearable
	 * event, else at its fallback; PROMPT_NONE: never.
	 */
	public prompt				= this.primitive<string>("prompt", PROMPT_START)
	public promptGraceMin		= this.primitive<number>("promptGraceMin", 0)
	public eventProvider		= this.primitive<string>("eventProvider", "any")
	public eventKind			= this.primitive<string>("eventKind", "bed_out")
	/** The part of the day an event counts in (-1 = the whole range). */
	public eventFrom			= this.primitive<number>("eventFrom", -1)
	public eventUntil			= this.primitive<number>("eventUntil", -1)
	/** Minutes after the event; with a larger eventDelayMaxMin a stable random delay in between. */
	public eventDelayMin		= this.primitive<number>("eventDelayMin", 0)
	public eventDelayMaxMin		= this.primitive<number>("eventDelayMaxMin", 0)
	public fallback				= this.primitive<string>("fallback", FALLBACK_FIXED)
	public fallbackTime			= this.primitive<number>("fallbackTime", 43200000)	// 12:00
	public fallbackVariable		= this.primitive<string>("fallbackVariable", "")
	public fallbackOffsetMin	= this.primitive<number>("fallbackOffsetMin", 0)
	/** FALLBACK_VARIABLE: fallbackTime while the participant has not answered the time item. */
	public fallbackDefault		= this.primitive<boolean>("fallbackDefault", true)
	/** AVAILABLE_PROMPT: from its prompt for the response window; AVAILABLE_RANGE: throughout its range; AVAILABLE_DAY: until the day ends. */
	public available			= this.primitive<string>("available", AVAILABLE_PROMPT)
	/** Its own response window instead of the schedule's (e.g. a longer one for the evening check-in). */
	public responseOverride		= this.primitive<boolean>("responseOverride", false)
	public responseMinutes		= this.primitive<number>("responseMinutes", 60)
	public limit				= this.primitive<string>("limit", LIMIT_WINDOW)
	/** After completing its questionnaire, it opens again no earlier than this. */
	public minGapMinutes		= this.primitive<number>("minGapMinutes", 0)
	/** Open alongside the other check-ins: no part in one-at-a-time and the cool-down. */
	public parallel				= this.primitive<boolean>("parallel", false)
	/** The participant app starts it by itself when the participant reaches the study while it is open. */
	public autoStart			= this.primitive<boolean>("autoStart", false)
	// Notification: title (empty = the questionnaire's), text (empty = the default), reminders, deadline.
	public title				= this.primitive<string>("title", "")
	public text					= this.primitive<string>("text", "")
	public reminderCount		= this.primitive<number>("reminderCount", 0)
	public reminderDelayMin		= this.primitive<number>("reminderDelayMin", 5)
	public includeDeadline		= this.primitive<boolean>("includeDeadline", false)
}

/**
 * The study's schedule: study-wide rules, the wake-up and bedtime anchors and the check-ins. Check-ins that are not
 * parallel are open one at a time (`overlap`: the next one waits for the previous window to close, or that window
 * closes early), and with a cool-down a check-in opens no earlier than `cooldownMinutes` after another was completed.
 */
export class StudySchedule extends DataStructure {
	/** When a study day starts, ms after midnight (e.g. 04:00): "until the end of the day" and "once per day" use it. */
	public dayStart				= this.primitive<number>("dayStart", 0)
	/** Parts the waking day is split into for `split` boundaries; 0 = one per check-in. */
	public segments				= this.primitive<number>("segments", 0)
	/** Minutes a check-in stays open after its prompt; 0 = until the end of its range. */
	public responseMinutes		= this.primitive<number>("responseMinutes", 60)
	public overlap				= this.primitive<string>("overlap", OVERLAP_DEFER)
	/** Minutes after completing a check-in before another one opens; 0 = off. */
	public cooldownMinutes		= this.primitive<number>("cooldownMinutes", 0)

	// Wake-up: the earliest observed wake-up in the window, else the fallback once it has passed.
	public wakeSensor			= this.primitive<boolean>("wakeSensor", false)
	public wakeProvider			= this.primitive<string>("wakeProvider", "any")
	public wakeEvent			= this.primitive<string>("wakeEvent", "bed_out")
	/** Answering the first check-in marks wake-up; it can then be answered from the start of the window. */
	public wakeFromCheckin		= this.primitive<boolean>("wakeFromCheckin", false)
	public wakeWindowStart		= this.primitive<number>("wakeWindowStart", 18000000)	// 05:00
	public wakeWindowEnd		= this.primitive<number>("wakeWindowEnd", 43200000)	// 12:00
	/** Fallback: the participant's answer to this time item (e.g. habitual_wake_time) + offset, else wakeDefault. */
	public wakeVariable			= this.primitive<string>("wakeVariable", "")
	/** On Saturdays and Sundays this time item first (e.g. habitual_wake_time_weekend); empty = wakeVariable. */
	public wakeVariableWeekend	= this.primitive<string>("wakeVariableWeekend", "")
	public wakeOffsetMin		= this.primitive<number>("wakeOffsetMin", 0)
	public wakeDefault			= this.primitive<number>("wakeDefault", 28800000)		// 08:00

	// Going to bed: the first in-bed event of the night (bedSensor) before the fallback; fallback tier 1 the
	// participant's answer to bedVariable + offset, tier 2 bedDefault. Split points use the fallback (planned bedtime).
	public bedSensor			= this.primitive<boolean>("bedSensor", false)
	public bedProvider			= this.primitive<string>("bedProvider", "any")
	public bedEvent				= this.primitive<string>("bedEvent", "bed_in")
	public bedWindowStart		= this.primitive<number>("bedWindowStart", 72000000)	// 20:00
	public bedWindowEnd			= this.primitive<number>("bedWindowEnd", 14400000)		// 04:00 (next day)
	public bedVariable			= this.primitive<string>("bedVariable", "")
	/** On Friday and Saturday nights this time item first (e.g. habitual_bedtime_weekend); empty = bedVariable. */
	public bedVariableWeekend	= this.primitive<string>("bedVariableWeekend", "")
	public bedOffsetMin			= this.primitive<number>("bedOffsetMin", 0)
	public bedDefault			= this.primitive<number>("bedDefault", 82800000)		// 23:00

	public checkins				= this.objectArray("checkins", ScheduleCheckin)

	/**
	 * The study's only check-in, if it is ALWAYS_OPEN_CHECKIN. A planned check-in added next to it would not limit anything, because
	 * that questionnaire could still be answered at any time. A researcher may also want this on purpose, so ask before replacing it.
	 */
	public getOnlyAlwaysOpen(): ScheduleCheckin | null {
		const checkins = this.checkins.get()
		if(checkins.length != 1)
			return null
		const checkin = checkins[0]
		return checkin.parallel.get() == ALWAYS_OPEN_CHECKIN.parallel
			&& checkin.startType.get() == ALWAYS_OPEN_CHECKIN.startType
			&& checkin.startTime.get() == ALWAYS_OPEN_CHECKIN.startTime
			&& checkin.endType.get() == ALWAYS_OPEN_CHECKIN.endType
			&& checkin.endTime.get() == ALWAYS_OPEN_CHECKIN.endTime
			&& checkin.endNextDay.get() == ALWAYS_OPEN_CHECKIN.endNextDay
			&& checkin.prompt.get() == ALWAYS_OPEN_CHECKIN.prompt
			&& checkin.available.get() == ALWAYS_OPEN_CHECKIN.available
			&& checkin.limit.get() == ALWAYS_OPEN_CHECKIN.limit
			? checkin
			: null
	}
}
