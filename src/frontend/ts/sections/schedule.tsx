import { SectionContent } from "../site/SectionContent";
import m, { Vnode } from "mithril";
import { Lang } from "../singletons/Lang";
import { BindObservable, ConstrainedNumberTransformer, DateTransformer, TimeTransformer } from "../components/BindObservable";
import { DashRow } from "../components/DashRow";
import { DashElement } from "../components/DashElement";
import { TitleRow } from "../components/TitleRow";
import { BtnAdd, BtnTrash } from "../components/Buttons";
import { Study } from "../data/study/Study";
import {
	AVAILABLE_DAY, AVAILABLE_PROMPT, AVAILABLE_RANGE, BOUNDARY_BED, BOUNDARY_FIXED, BOUNDARY_SPLIT, BOUNDARY_USUAL_WAKE, BOUNDARY_WAKE,
	FALLBACK_FIXED, FALLBACK_NONE, FALLBACK_VARIABLE, LIMIT_DAY, LIMIT_NONE, LIMIT_ONCE, LIMIT_WINDOW, OVERLAP_DEFER, OVERLAP_TRUNCATE,
	PROMPT_EVENT, PROMPT_NONE, PROMPT_RANDOM, PROMPT_START, PROMPT_WAKE, ScheduleCheckin, StudySchedule
} from "../data/study/StudySchedule";
import { WEARABLE_EVENTS, WEARABLE_PROVIDERS, WEARABLE_PROVIDER_ANY } from "../data/study/EventTrigger";
import { SectionData } from "../site/SectionData";
import warnSvg from "../../imgs/icons/warn.svg?raw";

const DAY = 86400000
const MINUTE = 60000
const WEEKDAY_KEYS = ["weekday_sun", "weekday_mon", "weekday_tue", "weekday_wed", "weekday_thu", "weekday_fri", "weekday_sat"] as const

function warning(text: string): Vnode<any, any> {
	return <div><small class="highlight"><span class="inlineIcon">{m.trust(warnSvg)}</span>{text}</small></div>
}

function row(...children: (Vnode<any, any> | false | undefined)[]): Vnode<any, any> {
	return <div class="horizontal hAlignStart vAlignCenter" style="flex-wrap:wrap;gap:16px">{children}</div>
}

function minutesInput(obs: ScheduleCheckin["responseMinutes"], label: string, min: number = 0, max?: number): Vnode<any, any> {
	return <label>
		<small>{label}</small>
		<input type="number" min={min} max={max} style="width:5.5em" {...BindObservable(obs, new ConstrainedNumberTransformer(min, max))} />
		<span>{Lang.get("minutes")}</span>
	</label>
}

/**
 * The study's one schedule: which check-ins open when and when participants are prompted, on every channel. Each
 * check-in is a questionnaire with the days it runs on, a daily range whose ends are a clock time, wake-up, the usual
 * wake-up or bedtime plus an offset, or a split point of the waking day (so fixed EMA windows, windows that follow each
 * participant's day, and hybrids share one model), when it is prompted (start, random, at wake-up, at a wearable event,
 * or not at all), when it can be answered, how often, and its notification. Questionnaires without a check-in are never
 * open. Run by backend/schedule/Schedule.php (web push, Telegram, the Android app's server nudges) and its port
 * web-pwa/src/lib/schedule.ts (the web and Android app).
 */
export class Content extends SectionContent {
	public static preLoad(sectionData: SectionData): Promise<any>[] {
		return [sectionData.getStudyPromise()]
	}

	public title(): string {
		return Lang.get("schedule")
	}

	private segments(schedule: StudySchedule): number {
		return schedule.segments.get() > 0 ? schedule.segments.get() : schedule.checkins.get().length
	}

	private addCheckin(schedule: StudySchedule): void {
		const k = schedule.checkins.get().length
		schedule.checkins.push({ startSegment: k, endSegment: k + 1 })
	}

	/** Every check-in that is not parallel gets one equal part of the waking day, prompted when it starts (dynamic). */
	private applySplitPreset(schedule: StudySchedule): void {
		schedule.segments.set(0)
		schedule.checkins.get().filter((c) => !c.parallel.get()).forEach((c, k) => {
			c.startType.set(BOUNDARY_SPLIT)
			c.startSegment.set(k)
			c.endType.set(BOUNDARY_SPLIT)
			c.endSegment.set(k + 1)
			c.prompt.set(PROMPT_START)
		})
	}

	/** Equal fixed windows between the default wake-up and bedtime, prompted at a random time (classic EMA). */
	private applyFixedPreset(schedule: StudySchedule): void {
		const checkins = schedule.checkins.get().filter((c) => !c.parallel.get())
		const from = Math.max(0, schedule.wakeDefault.get())
		let to = Math.max(0, schedule.bedDefault.get())
		if (to <= from)
			to += DAY
		const part = Math.floor((to - from) / Math.max(1, checkins.length) / (15 * MINUTE)) * 15 * MINUTE
		checkins.forEach((c, k) => {
			c.startType.set(BOUNDARY_FIXED)
			c.startTime.set((from + k * part) % DAY)
			c.endType.set(BOUNDARY_FIXED)
			c.endTime.set((from + (k + 1) * part) % DAY)
			c.prompt.set(PROMPT_RANDOM)
		})
	}

	private boundaryView(schedule: StudySchedule, checkin: ScheduleCheckin, side: "start" | "end"): Vnode<any, any> {
		const type = side == "start" ? checkin.startType : checkin.endType
		const time = side == "start" ? checkin.startTime : checkin.endTime
		const offset = side == "start" ? checkin.startOffsetMin : checkin.endOffsetMin
		const segment = side == "start" ? checkin.startSegment : checkin.endSegment
		const nextDay = side == "start" ? checkin.startNextDay : checkin.endNextDay
		const segments = this.segments(schedule)
		return <div class="horizontal hAlignStart vAlignCenter" style="flex-wrap:wrap;gap:8px">
			<label>
				<small>{Lang.get(side == "start" ? "daily_plan_from" : "daily_plan_until")}</small>
				<select {...BindObservable(type)}>
					<option value={BOUNDARY_SPLIT}>{Lang.get("daily_plan_boundary_split")}</option>
					<option value={BOUNDARY_FIXED}>{Lang.get("daily_plan_boundary_fixed")}</option>
					<option value={BOUNDARY_WAKE}>{Lang.get("daily_plan_boundary_wake")}</option>
					<option value={BOUNDARY_USUAL_WAKE}>{Lang.get("daily_plan_boundary_usual_wake")}</option>
					<option value={BOUNDARY_BED}>{Lang.get("daily_plan_boundary_bed")}</option>
				</select>
			</label>
			{type.get() == BOUNDARY_FIXED &&
				<label><small>{Lang.get("time")}</small><input type="time" {...BindObservable(time, TimeTransformer)} /></label>}
			{(type.get() == BOUNDARY_WAKE || type.get() == BOUNDARY_USUAL_WAKE || type.get() == BOUNDARY_BED) &&
				<label>
					<small>{Lang.get("daily_plan_offset")}</small>
					<input type="number" min="-1439" max="1439" style="width:5.5em" {...BindObservable(offset, new ConstrainedNumberTransformer(-1439, 1439))} />
					<span>{Lang.get("minutes")}</span>
				</label>}
			{type.get() == BOUNDARY_SPLIT &&
				<label>
					<small>{Lang.get("daily_plan_split_point", segments)}</small>
					<input type="number" min="0" max={segments} style="width:4.5em" {...BindObservable(segment, new ConstrainedNumberTransformer(0, segments))} />
				</label>}
			{(type.get() == BOUNDARY_FIXED || type.get() == BOUNDARY_WAKE) &&
				<label class="noTitle noDesc" title={Lang.get(type.get() == BOUNDARY_WAKE ? "daily_plan_next_day_wake_info" : "daily_plan_next_day_time_info")}>
					<input type="checkbox" {...BindObservable(nextDay)} />
					<span>{Lang.get("daily_plan_next_day")}</span>
				</label>}
		</div>
	}

	/** Which days a check-in runs on: from / until (days after enrolment), every N days, weekdays, dates. */
	private daysView(checkin: ScheduleCheckin): Vnode<any, any> {
		const weekdays = checkin.weekdays.get()
		const toggleWeekday = (bit: number) => {
			const all = weekdays == 0 ? 0b1111111 : weekdays
			const next = all ^ (1 << bit)
			checkin.weekdays.set(next == 0b1111111 ? 0 : next)
		}
		return <div class="vertical" style="gap:4px">
			{row(
				<label>
					<small>{Lang.get("schedule_day_from")}</small>
					<input type="number" min="0" style="width:4.5em" {...BindObservable(checkin.dayFrom, new ConstrainedNumberTransformer(0, undefined))} />
				</label>,
				<label>
					<small>{Lang.get("schedule_day_until")}</small>
					<input type="number" min="-1" style="width:4.5em" {...BindObservable(checkin.dayUntil, new ConstrainedNumberTransformer(-1, undefined))} />
				</label>,
				<label>
					<small>{Lang.get("schedule_every_days")}</small>
					<input type="number" min="1" style="width:4em" {...BindObservable(checkin.everyDays, new ConstrainedNumberTransformer(1, undefined))} />
					<span>{Lang.get("days")}</span>
				</label>,
				<div class="horizontal vAlignCenter" style="gap:6px">
					{WEEKDAY_KEYS.map((key, bit) =>
						<label class="noTitle noDesc">
							<input type="checkbox" checked={weekdays == 0 || (weekdays & (1 << bit)) != 0} onchange={() => toggleWeekday(bit)} />
							<span>{Lang.get(key)}</span>
						</label>)}
				</div>,
				<label><small>{Lang.get("start_date")}</small><input type="date" {...BindObservable(checkin.dateFrom, DateTransformer)} /></label>,
				<label><small>{Lang.get("end_date")}</small><input type="date" {...BindObservable(checkin.dateUntil, DateTransformer)} /></label>
			)}
			<small>{Lang.get("schedule_days_info")}</small>
		</div>
	}

	/** An event prompt: which wearable event, in which part of the day, after what delay, and the fallback. */
	private eventView(study: Study, checkin: ScheduleCheckin, timeItems: string[]): Vnode<any, any> {
		const fallback = checkin.fallback.get()
		return <div class="vertical" style="gap:4px">
			{row(
				<label>
					<small>{Lang.get("wearables_provider")}</small>
					<select {...BindObservable(checkin.eventProvider)}>
						<option value={WEARABLE_PROVIDER_ANY}>{Lang.get("sensor_provider_any")}</option>
						{WEARABLE_PROVIDERS.map((p) => <option value={p}>{Lang.getDynamic(p)}</option>)}
					</select>
				</label>,
				<label>
					<small>{Lang.get("sensor_event")}</small>
					<select {...BindObservable(checkin.eventKind)}>
						{Object.keys(WEARABLE_EVENTS).map((key) => <option value={key}>{Lang.getDynamic(`sensor_event_${key}`)}</option>)}
					</select>
				</label>,
				<label><small>{Lang.get("schedule_event_from")}</small><input type="time" {...BindObservable(checkin.eventFrom, TimeTransformer)} /></label>,
				<label><small>{Lang.get("schedule_event_until")}</small><input type="time" {...BindObservable(checkin.eventUntil, TimeTransformer)} /></label>,
				minutesInput(checkin.eventDelayMin, Lang.get("schedule_event_delay")),
				minutesInput(checkin.eventDelayMaxMin, Lang.get("schedule_event_delay_max"))
			)}
			{row(
				<label>
					<small>{Lang.get("schedule_event_fallback")}</small>
					<select {...BindObservable(checkin.fallback)}>
						<option value={FALLBACK_FIXED}>{Lang.get("schedule_event_fallback_fixed")}</option>
						<option value={FALLBACK_VARIABLE}>{Lang.get("schedule_event_fallback_variable")}</option>
						<option value={FALLBACK_NONE}>{Lang.get("schedule_event_fallback_none")}</option>
					</select>
				</label>,
				fallback == FALLBACK_VARIABLE && this.variableInput(checkin.fallbackVariable, "habitual_wake_time", `scheduleFallbackItems${checkin.questionnaireId.get()}`, timeItems),
				fallback == FALLBACK_VARIABLE && minutesInput(checkin.fallbackOffsetMin, Lang.get("daily_plan_offset"), -1439, 1439),
				fallback == FALLBACK_VARIABLE &&
					<label class="noTitle noDesc">
						<input type="checkbox" {...BindObservable(checkin.fallbackDefault)} />
						<span>{Lang.get("schedule_event_fallback_default")}</span>
					</label>,
				(fallback == FALLBACK_FIXED || (fallback == FALLBACK_VARIABLE && checkin.fallbackDefault.get())) &&
					<label><small>{Lang.get("time")}</small><input type="time" {...BindObservable(checkin.fallbackTime, TimeTransformer)} /></label>
			)}
			<small>{Lang.get("schedule_event_info")}</small>
			{!study.wearablesEnabled.get() && warning(Lang.get("sensor_wearables_disabled"))}
		</div>
	}

	private notificationView(checkin: ScheduleCheckin): Vnode<any, any> {
		return row(
			<label>
				<small>{Lang.get("notification_title")}</small>
				<input type="text" placeholder={Lang.get("schedule_title_placeholder")} {...BindObservable(checkin.title)} />
			</label>,
			<label>
				<small>{Lang.get("schedule_notification_text")}</small>
				<input type="text" placeholder={Lang.get("schedule_text_placeholder")} {...BindObservable(checkin.text)} />
			</label>,
			<label>
				<small>{Lang.get("schedule_reminders")}</small>
				<input type="number" min="0" max="20" style="width:4em" {...BindObservable(checkin.reminderCount, new ConstrainedNumberTransformer(0, 20))} />
			</label>,
			checkin.reminderCount.get() > 0 && minutesInput(checkin.reminderDelayMin, Lang.get("schedule_reminder_delay"), 1),
			<label class="noTitle noDesc">
				<input type="checkbox" {...BindObservable(checkin.includeDeadline)} />
				<span>{Lang.get("notification_include_deadline")}</span>
			</label>
		)
	}

	private checkinView(study: Study, schedule: StudySchedule, checkin: ScheduleCheckin, index: number, timeItems: string[]): Vnode<any, any> {
		const qid = checkin.questionnaireId.get()
		const known = study.questionnaires.get().some((q) => q.internalId.get() == qid)
		const segments = this.segments(schedule)
		const badSegment = (checkin.startType.get() == BOUNDARY_SPLIT && checkin.startSegment.get() > segments)
			|| (checkin.endType.get() == BOUNDARY_SPLIT && checkin.endSegment.get() > segments)
		const prompt = checkin.prompt.get()
		const available = checkin.available.get()

		return DashElement("stretched", {
			content:
				<div class="vertical" style="gap:8px">
					{row(
						<span class="dashTitle">{Lang.get("daily_plan_checkin_x", index + 1)}</span>,
						<label>
							<small>{Lang.get("questionnaire")}</small>
							<select {...BindObservable(checkin.questionnaireId)}>
								<option value="-1">{Lang.get("please_select")}</option>
								{study.questionnaires.get().map((q) => <option value={q.internalId.get()}>{q.getTitle()}</option>)}
							</select>
						</label>,
						<label class="noTitle noDesc" title={Lang.get("schedule_parallel_info")}>
							<input type="checkbox" {...BindObservable(checkin.parallel)} />
							<span>{Lang.get("schedule_parallel")}</span>
						</label>,
						BtnTrash(() => schedule.checkins.remove(index), Lang.get("delete"))
					)}
					{this.daysView(checkin)}
					{row(this.boundaryView(schedule, checkin, "start"), this.boundaryView(schedule, checkin, "end"))}
					{row(
						<label>
							<small>{Lang.get("daily_plan_prompt")}</small>
							<select {...BindObservable(checkin.prompt)}>
								<option value={PROMPT_START}>{Lang.get("daily_plan_prompt_start")}</option>
								<option value={PROMPT_RANDOM}>{Lang.get("daily_plan_prompt_random")}</option>
								<option value={PROMPT_WAKE}>{Lang.get("daily_plan_prompt_wake")}</option>
								<option value={PROMPT_EVENT}>{Lang.get("schedule_prompt_event")}</option>
								<option value={PROMPT_NONE}>{Lang.get("daily_plan_prompt_none")}</option>
							</select>
						</label>,
						prompt == PROMPT_WAKE && minutesInput(checkin.promptGraceMin, Lang.get("daily_plan_prompt_grace"), 0, 1439),
						<label>
							<small>{Lang.get("daily_plan_available")}</small>
							<select {...BindObservable(checkin.available)}>
								<option value={AVAILABLE_PROMPT}>{Lang.get("daily_plan_available_prompt")}</option>
								<option value={AVAILABLE_RANGE}>{Lang.get("daily_plan_available_range")}</option>
								<option value={AVAILABLE_DAY}>{Lang.get("schedule_available_day")}</option>
							</select>
						</label>,
						available == AVAILABLE_PROMPT &&
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(checkin.responseOverride)} />
								<span>{Lang.get("daily_plan_own_response_window")}</span>
							</label>,
						available == AVAILABLE_PROMPT && checkin.responseOverride.get() && minutesInput(checkin.responseMinutes, Lang.get("daily_plan_response_window"))
					)}
					{prompt == PROMPT_WAKE && <small>{Lang.get(schedule.wakeSensor.get() ? "daily_plan_prompt_wake_info" : "daily_plan_prompt_wake_no_sensor")}</small>}
					{prompt == PROMPT_EVENT && this.eventView(study, checkin, timeItems)}
					{row(
						<label>
							<small>{Lang.get("schedule_limit")}</small>
							<select {...BindObservable(checkin.limit)}>
								<option value={LIMIT_WINDOW}>{Lang.get("schedule_limit_window")}</option>
								<option value={LIMIT_DAY}>{Lang.get("schedule_limit_day")}</option>
								<option value={LIMIT_ONCE}>{Lang.get("schedule_limit_once")}</option>
								<option value={LIMIT_NONE}>{Lang.get("schedule_limit_none")}</option>
							</select>
						</label>,
						minutesInput(checkin.minGapMinutes, Lang.get("schedule_min_gap")),
						<label class="noTitle noDesc" title={Lang.get("schedule_auto_start_info")}>
							<input type="checkbox" {...BindObservable(checkin.autoStart)} />
							<span>{Lang.get("schedule_auto_start")}</span>
						</label>
					)}
					{prompt != PROMPT_NONE && this.notificationView(checkin)}
					{qid == -1 && warning(Lang.get("daily_plan_no_questionnaire"))}
					{qid != -1 && !known && warning(Lang.get("schedule_unknown_questionnaire"))}
					{badSegment && warning(Lang.get("daily_plan_segment_out_of_range", segments))}
				</div>
		})
	}

	private variableInput(obs: StudySchedule["wakeVariable"], placeholder: string, listId: string, timeItems: string[], label: string = Lang.get("sensor_fallback_variable")): Vnode<any, any> {
		const value = obs.get().trim()
		return <label>
			<small>{label}</small>
			<input type="text" list={listId} placeholder={placeholder} {...BindObservable(obs)} />
			<datalist id={listId}>{timeItems.map((name) => <option value={name} />)}</datalist>
			{value != "" && timeItems.indexOf(value) == -1 && warning(Lang.get("sensor_fallback_variable_unknown", value))}
		</label>
	}

	/**
	 * Wake-up or going to bed: observed (a wearable event in a daily window; for wake-up also the answer to the first
	 * check-in), else tier 1 the participant's habitual time + offset (weekend times optional), else tier 2 a fixed time.
	 */
	private anchorView(study: Study, schedule: StudySchedule, anchor: "wake" | "bed", timeItems: string[]): Vnode<any, any>[] {
		const isWake = anchor == "wake"
		const sensor = isWake ? schedule.wakeSensor : schedule.bedSensor
		const provider = isWake ? schedule.wakeProvider : schedule.bedProvider
		const event = isWake ? schedule.wakeEvent : schedule.bedEvent
		const windowStart = isWake ? schedule.wakeWindowStart : schedule.bedWindowStart
		const windowEnd = isWake ? schedule.wakeWindowEnd : schedule.bedWindowEnd
		const variable = isWake ? schedule.wakeVariable : schedule.bedVariable
		const offset = isWake ? schedule.wakeOffsetMin : schedule.bedOffsetMin
		const fixed = isWake ? schedule.wakeDefault : schedule.bedDefault
		const observed = sensor.get() || (isWake && schedule.wakeFromCheckin.get())

		return [
			TitleRow(Lang.getWithColon(isWake ? "daily_plan_wake_up" : "daily_plan_bedtime")),
			DashRow(
				DashElement("stretched", {
					content:
						<div class="vertical" style="gap:8px">
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(sensor)} />
								<span>{Lang.get(isWake ? "daily_plan_wake_sensor" : "daily_plan_bed_sensor")}</span>
							</label>
							{sensor.get() && row(
								<label>
									<small>{Lang.get("wearables_provider")}</small>
									<select {...BindObservable(provider)}>
										<option value={WEARABLE_PROVIDER_ANY}>{Lang.get("sensor_provider_any")}</option>
										{WEARABLE_PROVIDERS.map((p) => <option value={p}>{Lang.getDynamic(p)}</option>)}
									</select>
								</label>,
								<label>
									<small>{Lang.get("sensor_event")}</small>
									<select {...BindObservable(event)}>
										{Object.keys(WEARABLE_EVENTS).map((key) => <option value={key}>{Lang.getDynamic(`sensor_event_${key}`)}</option>)}
									</select>
								</label>
							)}
							{sensor.get() && !study.wearablesEnabled.get() && warning(Lang.get("sensor_wearables_disabled"))}
							{isWake &&
								<label class="noTitle noDesc">
									<input type="checkbox" {...BindObservable(schedule.wakeFromCheckin)} />
									<span>{Lang.get("daily_plan_wake_from_checkin")}</span>
									<small>{Lang.get("daily_plan_wake_from_checkin_info")}</small>
								</label>}
							{isWake && schedule.wakeFromCheckin.get() && !this.hasWakeCheckin(schedule) && warning(Lang.get("daily_plan_no_wake_slot"))}
							{observed && row(
								<span>{Lang.get("daily_plan_observed_window")}</span>,
								<label><small>{Lang.get("startTime")}</small><input type="time" {...BindObservable(windowStart, TimeTransformer)} /></label>,
								<label><small>{Lang.get("endTime")}</small><input type="time" {...BindObservable(windowEnd, TimeTransformer)} /></label>
							)}
							{row(
								<span>{Lang.get(observed ? "daily_plan_tier1_fallback" : "daily_plan_tier1")}</span>,
								this.variableInput(variable, isWake ? "habitual_wake_time" : "habitual_bedtime", isWake ? "scheduleWakeItems" : "scheduleBedItems", timeItems),
								isWake
									? this.variableInput(schedule.wakeVariableWeekend, "habitual_wake_time_weekend", "scheduleWakeWeekendItems", timeItems, Lang.get("daily_plan_weekend_variable"))
									: this.variableInput(schedule.bedVariableWeekend, "habitual_bedtime_weekend", "scheduleBedWeekendItems", timeItems, Lang.get("daily_plan_weekend_night_variable")),
								minutesInput(offset, Lang.get("daily_plan_offset"), -1439, 1439)
							)}
							{row(
								<span>{Lang.get("daily_plan_tier2")}</span>,
								<label><small>{Lang.get("time")}</small><input type="time" {...BindObservable(fixed, TimeTransformer)} /></label>
							)}
							<small>{Lang.get(isWake ? "daily_plan_wake_info" : "daily_plan_bedtime_info")}</small>
						</div>
				})
			),
		]
	}

	/** Mirrors Schedule::wakeCheckinIndex: a check-in that starts right at wake-up. */
	private hasWakeCheckin(schedule: StudySchedule): boolean {
		return schedule.checkins.get().some((c) =>
			(c.startType.get() == BOUNDARY_WAKE && c.startOffsetMin.get() == 0)
			|| (c.startType.get() == BOUNDARY_SPLIT && c.startSegment.get() == 0))
	}

	private usesWakeOrBed(schedule: StudySchedule): boolean {
		return schedule.checkins.get().some((c) => c.prompt.get() == PROMPT_WAKE || [c.startType.get(), c.endType.get()].some((t) => t != BOUNDARY_FIXED))
	}

	public getView(): Vnode<any, any> {
		const study = this.getStudyOrThrow()
		const schedule = study.schedule
		const checkins = schedule.checkins.get()
		const timeItems = study.getTimeItemNames()
		const scheduled = new Set(checkins.map((c) => c.questionnaireId.get()))
		const unscheduled = study.questionnaires.get().filter((q) => !scheduled.has(q.internalId.get())).map((q) => q.getTitle())
		const prompts = checkins.some((c) => c.prompt.get() != PROMPT_NONE)

		return <div>
			<div class="center spacingBottom"><small>{Lang.get("schedule_info")}</small></div>
			{TitleRow(Lang.getWithColon("schedule_rules"))}
			{DashRow(
				DashElement("stretched", {
					content:
						<div class="vertical" style="gap:8px">
							{row(
								<label>
									<small>{Lang.get("day_start_time")}</small>
									<input type="time" {...BindObservable(schedule.dayStart, TimeTransformer)} />
								</label>,
								minutesInput(schedule.responseMinutes, Lang.get("daily_plan_response_window")),
								<label>
									<small>{Lang.get("daily_plan_overlap")}</small>
									<select {...BindObservable(schedule.overlap)}>
										<option value={OVERLAP_DEFER}>{Lang.get("daily_plan_overlap_defer")}</option>
										<option value={OVERLAP_TRUNCATE}>{Lang.get("daily_plan_overlap_truncate")}</option>
									</select>
								</label>,
								minutesInput(schedule.cooldownMinutes, Lang.get("daily_plan_cooldown"), 0, 1439),
								<label>
									<small>{Lang.get("daily_plan_segments")}</small>
									<input type="number" min="0" style="width:4.5em" {...BindObservable(schedule.segments, new ConstrainedNumberTransformer(0, undefined))} />
								</label>,
								<button type="button" disabled={!checkins.length} onclick={() => this.applySplitPreset(schedule)}>{Lang.get("daily_plan_preset_split")}</button>,
								<button type="button" disabled={!checkins.length} onclick={() => this.applyFixedPreset(schedule)}>{Lang.get("daily_plan_preset_fixed")}</button>
							)}
							<small>{Lang.get("day_start_time_info")}</small>
							<small>{Lang.get("daily_plan_checkins_info")}</small>
							{prompts && !study.webPushEnabled.get() && <small>{Lang.get("daily_plan_push_off")}</small>}
						</div>
				})
			)}

			{TitleRow(Lang.getWithColon("daily_plan_checkins"))}
			{unscheduled.length != 0 && <div class="center">{warning(Lang.get("schedule_unscheduled", unscheduled.join(", ")))}</div>}
			{DashRow(...checkins.map((c, i) => this.checkinView(study, schedule, c, i, timeItems)))}
			<div class="center spacingBottom">{BtnAdd(() => this.addCheckin(schedule), Lang.get("daily_plan_add_checkin"))}</div>
			{checkins.length == 0 && <div class="center">{warning(Lang.get("daily_plan_no_checkins"))}</div>}

			{this.usesWakeOrBed(schedule) && this.anchorView(study, schedule, "wake", timeItems)}
			{this.usesWakeOrBed(schedule) && this.anchorView(study, schedule, "bed", timeItems)}
			<div class="center spacingTop"><small>{Lang.get("daily_plan_preview_hint")}</small></div>
		</div>
	}
}
