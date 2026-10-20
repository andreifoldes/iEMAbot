import { SectionContent } from "../site/SectionContent";
import m, { Vnode } from "mithril";
import { DashRow } from "../components/DashRow";
import { DashElement } from "../components/DashElement";
import { Lang } from "../singletons/Lang";
import { ObservableLangChooser } from "../components/ObservableLangChooser";
import { BindObservable, BooleanTransformer, ConstrainedNumberTransformer, TimeTransformer, Transformer } from "../components/BindObservable";
import { ActionTrigger } from "../data/study/ActionTrigger";
import { Schedule } from "../data/study/Schedule";
import { CUE_WEARABLE, EventTrigger, LEGACY_PROVIDER_FITBIT, FALLBACK_MODE_FIXED, FALLBACK_MODE_VARIABLE, SHOW_GOOGLE_HEALTH, WEARABLE_EVENTS, WEARABLE_PROVIDERS, WEARABLE_PROVIDER_ANY, WEARABLE_PROVIDER_GOOGLE_HEALTH } from "../data/study/EventTrigger";
import { TitleRow } from "../components/TitleRow";
import { PrimitiveType } from "../observable/types/PrimitiveType";
import { ACTION_INVITATION } from "../constants/actions";
import { NotCompatibleIcon } from "../components/NotCompatibleIcon";
import { SignalTime } from "../data/study/SignalTime";
import { BaseObservable } from "../observable/BaseObservable";
import { Action } from "../data/study/Action";
import { Study } from "../data/study/Study";
import { BtnAdd, BtnCopy, BtnTrash } from "../components/Buttons";
import warnSvg from "../../imgs/icons/warn.svg?raw";
import { SectionData } from "../site/SectionData";

class SpecificQuestionnaireTransformer implements Transformer {
	private readonly eventTrigger: EventTrigger
	constructor(eventTrigger: EventTrigger) {
		this.eventTrigger = eventTrigger
	}
	public toAttribute(value: PrimitiveType): PrimitiveType {
		return value
	}
	public toValue(value: string): PrimitiveType {
		if (!value)
			this.eventTrigger.specificQuestionnaireInternalId.set(-1)
		return value
	}
}

class CombinedValueTransformer implements Transformer {
	private readonly index: number
	private readonly obs: BaseObservable<number>

	constructor(index: number, obs: BaseObservable<number>) {
		this.index = 1 << index
		this.obs = obs
	}
	public toAttribute(value: PrimitiveType): PrimitiveType {
		return ((value as number) & this.index) == this.index
	}
	public toValue(value: string): PrimitiveType {
		if (value)
			return this.obs.get() | this.index
		else
			return this.obs.get() - this.index
	}
}

export class Content extends SectionContent {
	private editEventAsTitle: boolean = false

	public static preLoad(sectionData: SectionData): Promise<any>[] {
		return [sectionData.getStudyPromise()]
	}
	public title(): string {
		return this.editEventAsTitle ? Lang.get("edit_event") : Lang.get("edit_schedule")
	}

	private removeSignalTime(schedule: Schedule, index: number): void {
		schedule.signalTimes.remove(index)
	}
	private copySignalTime(schedule: Schedule, signalTime: SignalTime, index: number): void {
		schedule.signalTimes.addCopy(signalTime, index)
	}
	private addSignalTime(schedule: Schedule): void {
		schedule.signalTimes.push({})
	}

	private hasSchedule(actionTrigger: ActionTrigger): boolean {
		return actionTrigger.schedules.get().length != 0
	}

	private isSignalTimeValid(signalTime: SignalTime): boolean {
		if (!signalTime.random.get() || signalTime.frequency.get() <= 1) {
			return true
		}
		// mirrors from Schaduler.scheduleSignalTime() in shared code of smartphone application
		const msBetween = signalTime.minutesBetween.get() * 60000
		const end = signalTime.endTimeOfDay.get()
		var start = signalTime.startTimeOfDay.get()
		if (start > end) {
			start += 86400000 //1000*60*60*24
		}
		const period = Math.abs(end - start) // simplification
		const block = period / signalTime.frequency.get()
		return block >= msBetween
	}

	private isSignalTimeAcrossMidnight(signalTime: SignalTime): boolean {
		return signalTime.random.get() && signalTime.startTimeOfDay.get() > signalTime.endTimeOfDay.get()
	}

	private getActionView(study: Study, action: Action): (Vnode<any, any> | false)[] {
		return [
			DashElement("stretched", {
				content:
					<div>
						<label class="line">
							<small>{Lang.get("action")}</small>
							<select class="big" {...BindObservable(action.type)}>
								<option value="1">{Lang.get("action_invitation")}</option>
								<option value="2">{Lang.get("action_msg")}</option>
								<option value="3">{Lang.get("action_notification")}</option>
							</select>
						</label>
					</div>
			}),
			DashElement(null, {
				content:
					<div>
						<label class="line">
							<small>{Lang.get("timeout_after_last_reminder")}</small>
							<input min="0" type="number" {...BindObservable(action.timeout, new ConstrainedNumberTransformer(0, undefined))} />
							<span class="spacingRight">{Lang.get("minutes")}</span>
							{NotCompatibleIcon("iOS", "Web")}
							<small>{Lang.get("info_timeout_notifications")}</small>
						</label>
					</div>
			}),
			action.type.get() == ACTION_INVITATION && DashElement("vertical",
				{
					content:
						<div>
							<label class="line">
								<small>{Lang.get("number_of_reminders")}</small>
								<input min="0" type="number" {...BindObservable(action.reminder_count, new ConstrainedNumberTransformer(0, undefined))} />
							</label>
						</div>
				},

				action.reminder_count.get() != 0 && {
					content:
						<div>
							<label class="line">
								<small>{Lang.get("time_delay")}</small>
								<input min="0" type="number" {...BindObservable(action.reminder_delay_minu, new ConstrainedNumberTransformer(0, undefined))} />
								<span>{Lang.get("minutes")}</span>
							</label>
						</div>
				}),
			DashElement("stretched", {
				content:
					<div>
						<label class="line">
							<small>{Lang.get("message")}</small>
							<textarea {...BindObservable(action.msgText)}></textarea>
							{ObservableLangChooser(study)}
						</label>
					</div>
			})
		]
	}

	private getEventView(actionTrigger: ActionTrigger): Vnode<any, any> {
		const event = actionTrigger.eventTriggers.get()[0]
		const action = actionTrigger.actions.get()[0]
		const study = this.getStudyOrThrow()
		this.editEventAsTitle = true
		const isSensor = event.cueCode.get() == CUE_WEARABLE

		return <div>
			{DashRow(
				DashElement(null,
					{
						content:
							<div>
								<label>
									<small>{Lang.get("event")}</small>

									<select {...BindObservable(event.cueCode)}>
										<option>actions_executed</option>
										<option>invitation</option>
										<option>invitation_missed</option>
										<option>joined</option>
										<option>quit</option>
										<option>questionnaire</option>
										<option>rejoined</option>
										<option>reminder</option>
										<option>schedule_changed</option>
										<option>statistic_viewed</option>
										<option>study_message</option>
										<option>study_updated</option>
										<option value={CUE_WEARABLE}>{Lang.get("cue_wearable_event")}</option>
									</select>
								</label>
							</div>
					}
				),
				DashElement(null,
					{
						content:
							<div class="center horizontal hAlignCenter vAlignCenter">
								<span>{Lang.getWithColon("delay")}</span>
								<label class="spacingLeft spacingRight center">
									<small>{Lang.get("random")}</small>
									<input type="checkbox" {...BindObservable(event.randomDelay)} />
								</label>
								{event.randomDelay.get() &&
									<label>
										<small>{Lang.get("from")}</small>
										<input min="0" type="number" {...BindObservable(event.delayMinimumSec, new ConstrainedNumberTransformer(0, undefined))} />
										<small>{Lang.get("seconds")}</small>
									</label>
								}
								<label>
									{event.randomDelay.get() &&
										<small>{Lang.get("to")}</small>
									}
									<input min={event.delayMinimumSec.get()} type="number" {...BindObservable(event.delaySec, new ConstrainedNumberTransformer(event.delayMinimumSec.get(), undefined))} />
									<small>{Lang.get("seconds")}</small>
								</label>
							</div>
					}),
				!isSensor && DashElement("stretched", {
					content:
						<div class="vertical">
							<label class="noTitle noDesc">
								<input type="checkbox" disabled={event.specificQuestionnaireEnabled.get()} {...BindObservable(event.skipThisQuestionnaire)} />
								<span style={`color:${event.specificQuestionnaireEnabled.get() ? 'lightgray' : 'inherit'}`}>{Lang.get("desc_skip_this_questionnaire")}</span>
							</label>
							<label class="noDesc">
								<input type="checkbox" {...BindObservable(event.specificQuestionnaireEnabled, new SpecificQuestionnaireTransformer(event))} />
								<span>{Lang.get("desc_specific_questionnaire")}</span>
							</label>
							{event.specificQuestionnaireEnabled.get() &&
								<label class="noDesc">
									<small>{Lang.get("questionnaire")}</small>
									<select {...BindObservable(event.specificQuestionnaireInternalId)}>
										{study.questionnaires.get().map((questionnaire) =>
											<option value={questionnaire.internalId.get()}>{questionnaire.getTitle()}</option>)
										}
									</select>
								</label>
							}
						</div>
				}),
			)}

			{isSensor && this.getSensorView(study, event)}

			{TitleRow(Lang.getWithColon("action"))}
			{DashRow(...this.getActionView(study, action))}
		</div>
	}

	private isEventSupportedBy(wearableEvent: string, provider: string): boolean {
		const entry = WEARABLE_EVENTS[wearableEvent]
		if (!entry)
			return false
		if (provider == WEARABLE_PROVIDER_ANY)
			return entry.withings != null || entry.googlehealth
		const supported = (entry as Record<string, unknown>)[provider]
		return supported != null && supported !== false
	}

	/**
	 * Names of the study's clock-time items, the candidates for a participant-variable fallback.
	 * Time items asking "how long ..." are left out: the PWA renders them as a duration picker
	 * (web-pwa/src/lib/esmiraAdapter.ts), whose answers are minutes, not a time of day.
	 */
	private getTimeItemNames(study: Study): string[] {
		const names = new Set<string>()
		study.questionnaires.get().forEach((questionnaire) =>
			questionnaire.pages.get().forEach((page) =>
				page.inputs.get().forEach((input) => {
					const isDuration = /\bhow long\b/.test(input.text.get().replace(/<[^>]+>/g, "").toLowerCase())
					if (input.responseType.get() == "time" && !isDuration && input.name.get() != "")
						names.add(input.name.get())
				})))
		return Array.from(names).sort()
	}

	/**
	 * Fallback time: fixed, or the participant's own answer to a time item (e.g. habitual wake-up
	 * time) plus an offset, with the fixed time as the second fallback while that is unanswered.
	 */
	private getFallbackView(study: Study, event: EventTrigger): Vnode<any, any> {
		const isVariable = event.fallbackMode.get() == FALLBACK_MODE_VARIABLE
		const variable = event.fallbackVariable.get().trim()
		const timeItems = this.getTimeItemNames(study)

		return <div class="vertical" style="gap:8px">
			<div class="horizontal hAlignStart vAlignCenter" style="flex-wrap:wrap;gap:16px">
				<label>
					<small>{Lang.get("sensor_fallback_mode")}</small>
					<select style="min-width:15em" {...BindObservable(event.fallbackMode)}>
						<option value={FALLBACK_MODE_FIXED}>{Lang.get("sensor_fallback_mode_fixed")}</option>
						<option value={FALLBACK_MODE_VARIABLE}>{Lang.get("sensor_fallback_mode_variable")}</option>
					</select>
				</label>
				{!isVariable &&
					<label>
						<small>{Lang.get("sensor_fallback_time")}</small>
						<input type="time" {...BindObservable(event.fallbackTimeOfDay, TimeTransformer)} />
					</label>
				}
				{isVariable &&
					<label>
						<small>{Lang.get("sensor_fallback_variable")}</small>
						<input type="text" list="fallbackVariableItems" placeholder="habitual_wake_time" {...BindObservable(event.fallbackVariable)} />
						<datalist id="fallbackVariableItems">
							{timeItems.map((name) => <option value={name} />)}
						</datalist>
					</label>
				}
				{isVariable &&
					<label>
						<small>{Lang.get("sensor_fallback_offset")}</small>
						<input type="number" min="-1439" max="1439" style="width:5.5em" {...BindObservable(event.fallbackOffsetMin, new ConstrainedNumberTransformer(-1439, 1439))} />
						<span>{Lang.get("minutes")}</span>
					</label>
				}
			</div>
			{isVariable &&
				<div class="horizontal hAlignStart vAlignCenter" style="flex-wrap:wrap;gap:16px">
					<label class="noTitle noDesc">
						<input type="checkbox" {...BindObservable(event.fallbackDefaultEnabled)} />
						<span>{Lang.get("sensor_fallback_default")}</span>
					</label>
					{event.fallbackDefaultEnabled.get() &&
						<label>
							<small>{Lang.get("time")}</small>
							<input type="time" {...BindObservable(event.fallbackTimeOfDay, TimeTransformer)} />
						</label>
					}
				</div>
			}
			{isVariable && variable == "" &&
				<small class="center"><div class="inlineIcon">{m.trust(warnSvg)}</div>{Lang.get("sensor_fallback_variable_missing")}</small>
			}
			{isVariable && variable != "" && timeItems.indexOf(variable) == -1 &&
				<small class="center"><div class="inlineIcon">{m.trust(warnSvg)}</div>{Lang.get("sensor_fallback_variable_unknown", variable)}</small>
			}
			<small>{Lang.get(isVariable ? "sensor_fallback_variable_info" : "sensor_fallback_info")}</small>
		</div>
	}

	/**
	 * "If this sensor event, then this prompt - else fall back to a fixed time."
	 * Sensor events arrive as provider webhooks (Withings notify / Google Health);
	 * the fallback turns the trigger into a time-contingent one when the sensor stays silent.
	 */
	private getSensorView(study: Study, event: EventTrigger): (Vnode<any, any> | false)[] {
		const provider = event.wearableProvider.get()
		// Hidden unless enabled, but a study that already uses it (e.g. imported source) must keep rendering.
		const offeredProviders = SHOW_GOOGLE_HEALTH || provider == WEARABLE_PROVIDER_GOOGLE_HEALTH
			? [...WEARABLE_PROVIDERS, WEARABLE_PROVIDER_GOOGLE_HEALTH]
			: WEARABLE_PROVIDERS
		const supported = this.isEventSupportedBy(event.wearableEvent.get(), provider)
		const providerOffered = provider == WEARABLE_PROVIDER_ANY
			? study.wearablesProviders.get().length == 0 || offeredProviders.some((p) => study.wearablesProviders.indexOf(p) != -1)
			: study.wearablesProviders.get().length == 0 || study.wearablesProviders.indexOf(provider) != -1
		const windowEnabled = event.wearableWindowEnabled.get()

		return [
			TitleRow(Lang.getWithColon("sensor_if")),
			DashRow(
				DashElement(null, {
					content:
						<div>
							<label>
								<small>{Lang.get("wearables_provider")}</small>
								<select {...BindObservable(event.wearableProvider)}>
									<option value={WEARABLE_PROVIDER_ANY}>{Lang.get("sensor_provider_any")}</option>
									{provider == LEGACY_PROVIDER_FITBIT && <option value={LEGACY_PROVIDER_FITBIT}>{Lang.get("fitbit")}</option>}
									{offeredProviders.map((p) => <option value={p}>{Lang.getDynamic(p == "googlehealth" ? "googlehealth_experimental" : p)}</option>)}
								</select>
							</label>
						</div>
				}),
				DashElement(null, {
					content:
						<div>
							<label>
								<small>{Lang.get("sensor_event")}</small>
								<select {...BindObservable(event.wearableEvent)}>
									{Object.keys(WEARABLE_EVENTS).map((key) =>
										<option value={key}>{Lang.getDynamic(`sensor_event_${key}`)}</option>)}
								</select>
							</label>
						</div>
				}),
				DashElement(null, {
					content:
						<div>
							<label>
								<small>{Lang.get("sensor_max_per_day")}</small>
								<input type="number" min="1" style="width:4.5em" {...BindObservable(event.wearableMaxPerDay, new ConstrainedNumberTransformer(1, undefined))} />
							</label>
						</div>
				}),
				DashElement("stretched", {
					content:
						<div class="vertical">
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(event.wearableWindowEnabled)} />
								<span>{Lang.get("sensor_window_only")}</span>
							</label>
							{windowEnabled &&
								<div class="horizontal hAlignStart" style="flex-wrap:wrap;gap:16px">
									<label>
										<small>{Lang.get("startTime")}</small>
										<input type="time" {...BindObservable(event.wearableWindowStart, TimeTransformer)} />
									</label>
									<label>
										<small>{Lang.get("endTime")}</small>
										<input type="time" {...BindObservable(event.wearableWindowEnd, TimeTransformer)} />
									</label>
								</div>
							}
						</div>
				})
			),
			(!supported || !study.wearablesEnabled.get() || !providerOffered || provider != "withings") && DashRow(
				DashElement("stretched", {
					content:
						<div>
							{!supported && <small class="center"><div class="inlineIcon">{m.trust(warnSvg)}</div>{Lang.get("sensor_event_unsupported")}</small>}
							{!study.wearablesEnabled.get() && <small class="center"><div class="inlineIcon">{m.trust(warnSvg)}</div>{Lang.get("sensor_wearables_disabled")}</small>}
							{study.wearablesEnabled.get() && !providerOffered && <small class="center"><div class="inlineIcon">{m.trust(warnSvg)}</div>{Lang.get("sensor_provider_not_offered")}</small>}
							{provider == LEGACY_PROVIDER_FITBIT && <small class="center"><div class="inlineIcon">{m.trust(warnSvg)}</div>{Lang.get("sensor_fitbit_removed")}</small>}
							{provider == WEARABLE_PROVIDER_GOOGLE_HEALTH && <small class="center"><div class="inlineIcon">{m.trust(warnSvg)}</div>{Lang.get("sensor_googlehealth_note")}</small>}
						</div>
				})
			),

			TitleRow(Lang.getWithColon("sensor_else")),
			DashRow(
				DashElement("stretched", {
					content:
						<div class="vertical">
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(event.fallbackEnabled)} />
								<span>{Lang.get("sensor_fallback_enable")}</span>
							</label>
							{event.fallbackEnabled.get() && this.getFallbackView(study, event)}
						</div>
				})
			)
		]
	}

	/**
	 * Signal time card: vertical layout with all fields visible and clearly labeled.
	 * Random mode shows: window start/end, count per day, min gap, randomFixed mode.
	 * Fixed mode shows: just the time.
	 */
	private getSignalTimeView(schedule: Schedule, signalTime: SignalTime, index: number): Vnode<any, any> {
		const isRandom = signalTime.random.get()

		return <div class="line spacingBottom" style="border-left:3px solid #ddd;padding-left:12px;margin-left:4px">
			<div class="horizontal hAlignStart vAlignCenter" style="gap:8px">
				<label class="noDesc noTitle">
					<input type="checkbox" {...BindObservable(signalTime.random)} />
					<b>{isRandom ? Lang.get("random") : Lang.get("time")}</b>
				</label>
				<span style="flex:1" />
				{BtnCopy(this.copySignalTime.bind(this, schedule, signalTime, index))}
				{BtnTrash(this.removeSignalTime.bind(this, schedule, index))}
			</div>

			<div class="horizontal hAlignStart" style="flex-wrap:wrap;gap:16px;margin-top:8px">
				<label>
					<small>{isRandom ? Lang.get("startTime") : Lang.get("time")}</small>
					<input type="time" {...BindObservable(signalTime.startTimeOfDay, TimeTransformer)} />
				</label>

				{isRandom && <label>
					<small>{Lang.get("endTime")}</small>
					<input type="time" {...BindObservable(signalTime.endTimeOfDay, TimeTransformer)} />
				</label>}

				{isRandom && <label>
					<small>{Lang.get("frequency")}</small>
					<input type="number" min="1" style="width:4.5em" {...BindObservable(signalTime.frequency)} />
					<span>&times;</span>
				</label>}

				{isRandom && <label>
					<small>{Lang.get("minutes_between")}</small>
					<input type="number" min="0" style="width:5em" {...BindObservable(signalTime.minutesBetween)} />
					<span>{Lang.get("minutes")}</span>
				</label>}

				{isRandom && signalTime.frequency.get() > 1 && <label>
					<small>{Lang.get("random_fixed")}</small>
					<select {...BindObservable(signalTime.randomFixed, BooleanTransformer, "value")}>
						<option value="1">{Lang.get("random_fixed_true")}</option>
						<option value="0">{Lang.get("random_fixed_false")}</option>
					</select>
				</label>}
			</div>

			{this.isSignalTimeAcrossMidnight(signalTime) &&
				<div style="margin-top:4px"><small>{Lang.get("signal_time_across_midnight")}</small></div>
			}
			{!this.isSignalTimeValid(signalTime) &&
				<div style="margin-top:4px">
					<small><div class="inlineIcon">{m.trust(warnSvg)}</div>{Lang.get("signal_time_faulty", signalTime.frequency.get(), signalTime.minutesBetween.get())}</small>
				</div>
			}
		</div>
	}

	/**
	 * Note: Triggers are implemented so that each could hold MULTIPLE schedules, cues and actions.
	 * But since I suspect that this will not be used often, and it is easier to grasp for configuration, I removed that functionality in the admin panel
	 */
	private getScheduleView(actionTrigger: ActionTrigger): Vnode<any, any> {
		const study = this.getStudyOrThrow()
		const questionnaire = this.getQuestionnaireOrThrow()
		const schedule = actionTrigger.schedules.get()[0]
		const action = actionTrigger.actions.get()[0]
		const delayDays = (schedule.startDayOne.get() ? 1 : 0) + (schedule.skipFirstInLoop.get() ? schedule.dailyRepeatRate.get() : 0)

		return <div>
			{/* ── Times of Day ──────────────────────────────────────── */}
			{TitleRow(
				<div class="horizontal">
					<span class="fillFlexSpace selfAlignCenter">{Lang.getWithColon("times_of_day")}</span>
					<label class="noTitle noDesc">
						<input type="checkbox" {...BindObservable(schedule.userEditable)} />
						<span>{Lang.get("userEditable_desc")}</span>
					</label>
				</div>
			)}
			{DashRow(DashElement("stretched", {
				content:
					<div>
						<div id="signalTimes">
							{schedule.signalTimes.get().map((signalTime, index) =>
								this.getSignalTimeView(schedule, signalTime, index)
							)}
						</div>
						<div class="spacingTop">
							{BtnAdd(this.addSignalTime.bind(this, schedule), Lang.get("add_signalTime"))}
						</div>
					</div>
			}))}

			{/* ── Repeat Pattern ────────────────────────────────────── */}
			{TitleRow(Lang.get("repeat_every"))}
			{DashRow(
				DashElement("stretched", {
					content:
						<div>
							<div class="horizontal hAlignStart vAlignCenter" style="flex-wrap:wrap;gap:12px">
								<label>
									<small>{Lang.get("repeat_every")}</small>
									<input type="number" min="1" style="width:4.5em" {...BindObservable(schedule.dailyRepeatRate, new ConstrainedNumberTransformer(1, undefined))} />
									<span>{Lang.get("days")}</span>
								</label>
								<label class="noDesc">
									<input type="checkbox" {...BindObservable(schedule.startDayOne)} />
									<span>{Lang.get("schedule_start_day_one")}</span>
								</label>
								<label class="noDesc">
									<input type="checkbox" {...BindObservable(schedule.skipFirstInLoop)} />
									<span>{Lang.get("wait_x_days_until_first", schedule.dailyRepeatRate.get())}</span>
								</label>
							</div>
							{!study.legacyScheduling.get() && <div style="margin-top:6px">
								<small>{
									delayDays == 0 ? Lang.get("schedule_info_start_same_day") :
									delayDays == 1 ? Lang.get("schedule_info_start_day_one") :
									Lang.get("schedule_info_start_day_x", delayDays)
								}</small>
							</div>}
						</div>
				}),
				DashElement(null, {
					content:
						<div class="center">
							<label class="vertical">
								<small>{Lang.get("dayOfMonth")}</small>
								<select {...BindObservable(schedule.dayOfMonth)}>
									<option value="0">{Lang.get("all")}</option>
									{Array.from({ length: 31 }).map((_, i) => <option>{i + 1}</option>)}
								</select>
							</label>
						</div>
				}),
				DashElement(null, {
					content:
						<div>
							<div class="fakeLabel">
								<small>{Lang.get("weekdays_desc")}</small>
								<table><tbody>
									<tr>
										<td class="horizontalPadding"><label htmlFor="weekday_all">{Lang.get("all")}</label></td>
										<td class="horizontalPadding"><label htmlFor="weekday_sun">{Lang.get("weekday_sun")}</label></td>
										<td class="horizontalPadding"><label htmlFor="weekdays_mo">{Lang.get("weekday_mon")}</label></td>
										<td class="horizontalPadding"><label htmlFor="weekdays_di">{Lang.get("weekday_tue")}</label></td>
										<td class="horizontalPadding"><label htmlFor="weekdays_mi">{Lang.get("weekday_wed")}</label></td>
										<td class="horizontalPadding"><label htmlFor="weekdays_do">{Lang.get("weekday_thu")}</label></td>
										<td class="horizontalPadding"><label htmlFor="weekdays_fr">{Lang.get("weekday_fri")}</label></td>
										<td class="horizontalPadding"><label htmlFor="weekdays_sa">{Lang.get("weekday_sat")}</label></td>
									</tr>
									<tr>
										<td class="center">
											<input type="checkbox" id="weekday_all" disabled="disabled" checked={schedule.weekdays.get() == 0} />
										</td>
										<td class="center"><input type="checkbox" id="weekday_sun" {...BindObservable(schedule.weekdays, new CombinedValueTransformer(0, schedule.weekdays), "checked")} /></td>
										<td class="center"><input type="checkbox" id="weekdays_mo" {...BindObservable(schedule.weekdays, new CombinedValueTransformer(1, schedule.weekdays), "checked")} /></td>
										<td class="center"><input type="checkbox" id="weekdays_di" {...BindObservable(schedule.weekdays, new CombinedValueTransformer(2, schedule.weekdays), "checked")} /></td>
										<td class="center"><input type="checkbox" id="weekdays_mi" {...BindObservable(schedule.weekdays, new CombinedValueTransformer(3, schedule.weekdays), "checked")} /></td>
										<td class="center"><input type="checkbox" id="weekdays_do" {...BindObservable(schedule.weekdays, new CombinedValueTransformer(4, schedule.weekdays), "checked")} /></td>
										<td class="center"><input type="checkbox" id="weekdays_fr" {...BindObservable(schedule.weekdays, new CombinedValueTransformer(5, schedule.weekdays), "checked")} /></td>
										<td class="center"><input type="checkbox" id="weekdays_sa" {...BindObservable(schedule.weekdays, new CombinedValueTransformer(6, schedule.weekdays), "checked")} /></td>
									</tr>
								</tbody></table>
							</div>
						</div>
				})
			)}

			{/* ── Study Period ──────────────────────────────────────── */}
			{TitleRow(Lang.get("activation_after"))}
			{DashRow(
				DashElement(null, {
					content:
						<div>
							<label>
								<small>{Lang.get("activation_after")}</small>
								<input type="number" min="0" style="width:5em" {...BindObservable(questionnaire.durationStartingAfterDays, new ConstrainedNumberTransformer(0, undefined))} />
								<span>{Lang.get("days")}</span>
								<small>{Lang.get("after_joining_study")}</small>
							</label>
						</div>
				}),
				DashElement(null, {
					content:
						<div>
							<label>
								<small>{Lang.get("expiration_after")}</small>
								<input type="number" min="0" style="width:5em" {...BindObservable(questionnaire.durationPeriodDays, new ConstrainedNumberTransformer(0, undefined))} />
								<span>{Lang.get("days")}</span>
								<small>{Lang.get("after_joining_study")}</small>
							</label>
						</div>
				}),
				DashElement(null, {
					content:
						<div>
							<label>
								<small>{Lang.get("questionnaires_can_only_be_completed_per_notification")}</small>
								<input type="number" min="0" style="width:5em"
									{...BindObservable(questionnaire.completableMinutesAfterNotification, new ConstrainedNumberTransformer(0, undefined))}
									oninput={(e: Event) => {
										const v = Math.max(0, parseInt((e.target as HTMLInputElement).value) || 0)
										questionnaire.completableMinutesAfterNotification.set(v)
										questionnaire.completableOncePerNotification.set(v > 0)
									}}
								/>
								<span>{Lang.get("minutes")}</span>
								<small>{Lang.get("info_zero_disables_timeout")}</small>
							</label>
						</div>
				})
			)}

			{/* ── Action / Notification ─────────────────────────────── */}
			{TitleRow(Lang.getWithColon("action"))}
			{DashRow(...this.getActionView(study, action))}
		</div>
	}


	public getView(): Vnode<any, any> {
		const triggerI = this.getStaticInt("triggerI")
		if (triggerI == null)
			throw new Error("Trigger does not exist")

		const questionnaire = this.getQuestionnaireOrThrow()
		const actionTrigger = questionnaire.actionTriggers.get()[triggerI]
		const isSchedule = this.hasSchedule(actionTrigger)
		return <div>
			{isSchedule && this.getScheduleView(actionTrigger)}
			{!isSchedule && this.getEventView(actionTrigger)}
		</div>
	}
}