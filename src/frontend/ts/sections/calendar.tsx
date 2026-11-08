import { SectionContent } from "../site/SectionContent";
import m, { Component, Vnode, VnodeDOM } from "mithril";
import { Calendar, EventInput } from "fullcalendar";
import dayGridPlugin from "fullcalendar/daygrid";
import timeGridPlugin from "fullcalendar/timegrid";
import listPlugin from "fullcalendar/list";
import classicThemePlugin from "fullcalendar/themes/classic";
import deLocale from "fullcalendar/locales/de";
import ukLocale from "fullcalendar/locales/uk";
import "fullcalendar/skeleton.css";
import "fullcalendar/themes/classic/theme.css";
import "fullcalendar/themes/classic/palette.css";
import { Lang, LangKey } from "../singletons/Lang";
import { Study } from "../data/study/Study";
import { DashRow } from "../components/DashRow";
import { DashElement } from "../components/DashElement";
import { TitleRow } from "../components/TitleRow";
import { TabBar } from "../components/TabBar";
import { BindObservable, TimeTransformer } from "../components/BindObservable";
import { BtnReload } from "../components/Buttons";
import { LoadingSpinner } from "../components/LoadingSpinner";
import { ObservablePrimitive } from "../observable/ObservablePrimitive";
import { ObserverId } from "../observable/BaseObservable";
import { getChartColor } from "../helpers/ChartJsBox";
import { FALLBACK_VARIABLE, PROMPT_EVENT } from "../data/study/StudySchedule";
import { SectionData } from "../site/SectionData";
import { FILE_ADMIN } from "../constants/urls";

const DAY = 86400000
const HOUR = 3600000
const MINUTE = 60000

type WindowState = "completed" | "covered" | "missed" | "open" | "upcoming" | "withdrawn"

/** A window as ScheduleCalendar.php returns it; times are participant-local wall-clock ms (UTC fields = local clock). */
interface CalendarWindow {
	checkin: number
	qid: number
	limit: string
	open: number
	close: number
	prompt: number | null
	reminders: number[]
	state?: WindowState
	completedAt?: number | null
	/** One entry per notification (the prompt, then its reminders) with what the push log holds for it. Participant view only. */
	pushes?: NotificationRecord[]
}

/** A planned notification (participant-local ms) and when it was sent, failed, delivered and opened; null = not logged. */
interface NotificationRecord {
	at: number
	sent: number | null
	failed: number | null
	received: number | null
	clicked: number | null
}

type PushStatus = "opened" | "received" | "sent" | "failed" | "none" | "planned"

/** What a window block shows for one notification. */
interface NotificationLine {
	text: string
	color: string
	tooltip: string
}

interface CalendarDay {
	date: string
	index: number
	wake: number | null
	bed: number
	windows: CalendarWindow[]
}

interface Summary {
	expected: number
	completed: number
	missed: number
	perQuestionnaire: Record<string, { expected: number, completed: number }>
}

interface MonitoredParticipant {
	userId: string
	joined: number | null
	tz: { offsetMin: number, name: string, source: string } | null
	withdrawnAt: number | null
	lastCompletion: number | null
	day: number | null
	summary: Summary
	days?: CalendarDay[]
}

const STATE_COLORS: Record<WindowState, string> = {
	completed: "#2e7d32",
	covered: "#81c784",
	missed: "#c62828",
	open: "#1565c0",
	upcoming: "#9e9e9e",
	withdrawn: "#cfcfcf",
}
const STATE_LABELS: Record<WindowState, LangKey> = {
	completed: "calendar_state_completed",
	covered: "calendar_state_covered",
	missed: "calendar_state_missed",
	open: "calendar_state_open",
	upcoming: "calendar_state_upcoming",
	withdrawn: "calendar_state_withdrawn",
}
const STATES: WindowState[] = ["completed", "covered", "missed", "open", "upcoming", "withdrawn"]

const PUSH_COLORS: Record<PushStatus, string> = {
	opened: "#00695c",
	received: "#00838f",
	sent: "#6a1b9a",
	failed: "#ad1457",
	none: "#757575",
	planned: "#b0bec5",
}
const PUSH_LABELS: Record<PushStatus, LangKey> = {
	opened: "calendar_push_opened",
	received: "calendar_push_received",
	sent: "calendar_push_sent",
	failed: "calendar_push_failed",
	none: "calendar_push_none",
	planned: "calendar_push_planned",
}
const PUSH_STATUSES: PushStatus[] = ["opened", "received", "sent", "failed", "none", "planned"]

/** How far a notification got. A later step wins (a tap implies delivery); nothing logged is only "none" once it was due. */
function pushStatus(n: NotificationRecord, nowLocal: number): PushStatus {
	if(n.clicked != null)
		return "opened"
	if(n.received != null)
		return "received"
	if(n.sent != null)
		return "sent"
	if(n.failed != null)
		return "failed"
	return n.at > nowLocal ? "planned" : "none"
}

function pad(n: number): string {
	return String(n).padStart(2, "0")
}
/** "HH:MM" of a time of day in ms. */
function clock(tod: number): string {
	const mins = Math.floor((((tod % DAY) + DAY) % DAY) / MINUTE)
	return `${pad(Math.floor(mins / 60))}:${pad(mins % 60)}`
}
/** "HH:MM:SS" of a time of day in ms. */
function clockSeconds(tod: number): string {
	return `${clock(tod)}:${pad(Math.floor((((tod % DAY) + DAY) % DAY) / 1000) % 60)}`
}
/** A participant-local timestamp as "YYYY-MM-DD HH:MM". */
function localDateTime(t: number): string {
	return `${new Date(t).toISOString().slice(0, 10)} ${clock(t)}`
}
function percent(part: number, whole: number): string {
	return whole > 0 ? `${Math.round(part / whole * 100)}%` : "—"
}
function zoneLabel(tz: MonitoredParticipant["tz"]): string {
	if(!tz)
		return Lang.get("calendar_tz_unknown")
	const local = -tz.offsetMin
	const offset = `UTC${local < 0 ? "−" : "+"}${pad(Math.floor(Math.abs(local) / 60))}:${pad(Math.abs(local) % 60)}`
	return tz.name ? `${tz.name} (${offset})` : offset
}

interface CalendarViewAttrs {
	events: EventInput[]
	initialDate: number
}

function notificationLines(props: Record<string, any> | undefined): NotificationLine[] {
	const lines = props?.notifications
	return Array.isArray(lines) ? lines : []
}

/** FullCalendar in participant-local time (the times are local wall-clock ms, so the calendar runs in UTC). */
class CalendarView implements Component<CalendarViewAttrs, any> {
	private calendar?: Calendar
	private events?: EventInput[]
	private initialDate = 0

	public oncreate(vNode: VnodeDOM<CalendarViewAttrs, any>): void {
		this.events = vNode.attrs.events
		this.initialDate = vNode.attrs.initialDate
		this.calendar = new Calendar(vNode.dom as HTMLElement, {
			plugins: [classicThemePlugin, dayGridPlugin, timeGridPlugin, listPlugin],
			locales: [deLocale, ukLocale],
			locale: Lang.code,
			timeZone: "UTC",
			initialView: "timeGridWeek",
			initialDate: vNode.attrs.initialDate,
			headerToolbar: { left: "title", right: "prev,next today timeGridWeek,timeGridDay,dayGridMonth,listWeek" },
			contentHeight: "auto",
			allDaySlot: false,
			slotEventOverlap: false,
			editable: false,
			dayMaxEvents: false,
			nowIndicator: false,
			eventTimeFormat: { hour: "2-digit", minute: "2-digit", hour12: false },
			slotHeaderFormat: { hour: "2-digit", minute: "2-digit", hour12: false },
			events: this.events,
			eventContent: (info) => {
				const lines = notificationLines(info.event.extendedProps)
				if(!lines.length)
					return true // the default rendering
				const box = document.createElement("div")
				box.style.cssText = "height:100%;overflow:hidden;font-size:11px;line-height:1.35" // a short window clips its lines: the tooltip has them all
				const head = document.createElement("div")
				head.style.fontWeight = "600"
				head.textContent = `${info.timeText} ${info.event.title}`.trim()
				box.appendChild(head)
				for(const line of lines) {
					const row = document.createElement("div")
					row.title = line.tooltip
					row.style.cssText = "display:flex;align-items:center;gap:4px;white-space:nowrap"
					const dot = document.createElement("span")
					dot.style.cssText = `flex:none;width:11px;height:11px;box-sizing:border-box;border-radius:50%;background:${line.color};border:2px solid #fff`
					const text = document.createElement("span")
					text.textContent = `🔔 ${line.text}`
					row.append(dot, text)
					box.appendChild(row)
				}
				return { domNodes: [box] }
			},
			eventDidMount: (info) => { // a short window hides its lines: they are all in the tooltip
				const lines = notificationLines(info.event.extendedProps)
				if(lines.length)
					info.el.title = [info.event.title, ...lines.map((l) => `🔔 ${l.text} (${l.tooltip})`)].join("\n")
			},
		})
		this.calendar.render()
	}

	public onupdate(vNode: VnodeDOM<CalendarViewAttrs, any>): void {
		if(vNode.attrs.initialDate !== this.initialDate) { // another participant, or another join date
			this.initialDate = vNode.attrs.initialDate
			this.calendar?.gotoDate(this.initialDate)
		}
		if(vNode.attrs.events === this.events)
			return
		this.events = vNode.attrs.events
		this.calendar?.removeAllEvents()
		this.calendar?.addEventSource(this.events)
	}

	public onremove(): void {
		this.calendar?.destroy()
	}

	public view(): Vnode<any, any> {
		return <div class="calendarView"></div>
	}
}

/**
 * The study's calendar, from the same schedule engine that opens check-ins and sends notifications on every channel
 * (backend/schedule/ScheduleCalendar.php):
 * - Plan: the windows of a sample participant who joins on a chosen day with chosen answers to the questions the
 *   schedule reads (usual wake-up, bedtime, …), for the schedule as it is being edited.
 * - Participants: every participant's compliance so far (expected check-ins against completed ones) and, per
 *   participant, their windows day by day in their own time: completed, covered (done elsewhere the same day or
 *   earlier), missed, open or upcoming.
 */
export class Content extends SectionContent {
	private readonly tab = new ObservablePrimitive<number>(0, null, "calendarTab")
	private readonly joinDate = new ObservablePrimitive<string>(new Date().toISOString().slice(0, 10), null, "calendarJoinDate")
	private readonly planDays = new ObservablePrimitive<number>(14, null, "calendarPlanDays")
	private readonly variables: Record<string, ObservablePrimitive<number>> = {}
	private readonly colors: Record<number, string> = {}
	private plan: CalendarDay[] | null = null
	private planEvents: EventInput[] = []
	private planError: string | null = null
	private planTimer?: number
	private studyObserver?: ObserverId
	private overview: MonitoredParticipant[] | null = null
	private overviewLoading = false
	private participant: MonitoredParticipant | null = null
	private participantEvents: EventInput[] = []
	private participantLoading = false

	public static preLoad(sectionData: SectionData): Promise<any>[] {
		return [sectionData.getStudyPromise()]
	}

	constructor(sectionData: SectionData) {
		super(sectionData)
		const study = this.getStudyOrThrow()
		study.questionnaires.get().forEach((q, index) => this.colors[q.internalId.get()] = getChartColor(index))
		this.studyObserver = study.addObserver(() => this.schedulePlan())
		this.joinDate.addObserver(() => this.schedulePlan())
		this.planDays.addObserver(() => this.schedulePlan())
		this.tab.addObserver(() => {
			if(this.tab.get() == 1 && this.overview === null)
				this.loadOverview()
		})
		this.loadPlan()
	}

	public title(): string {
		return Lang.get("calendar")
	}

	public destroy(): void {
		this.studyObserver?.removeObserver()
		window.clearTimeout(this.planTimer)
		super.destroy()
	}

	private questionnaireTitle(study: Study, qid: number): string {
		return study.questionnaires.get().find((q) => q.internalId.get() == qid)?.getTitle() ?? `#${qid}`
	}

	// ── plan ────────────────────────────────────────────────────────────────────────────────────────

	/** The questions the schedule reads, each with a sample answer (the schedule's default as a start). */
	private planVariables(study: Study): { name: string, label: string, obs: ObservablePrimitive<number> }[] {
		const schedule = study.schedule
		const entries: { name: string, label: string, fallback: number }[] = [
			{ name: schedule.wakeVariable.get(), label: Lang.get("calendar_sample_wake"), fallback: schedule.wakeDefault.get() },
			{ name: schedule.wakeVariableWeekend.get(), label: Lang.get("calendar_sample_wake_weekend"), fallback: schedule.wakeDefault.get() },
			{ name: schedule.bedVariable.get(), label: Lang.get("calendar_sample_bed"), fallback: schedule.bedDefault.get() },
			{ name: schedule.bedVariableWeekend.get(), label: Lang.get("calendar_sample_bed_weekend"), fallback: schedule.bedDefault.get() },
			...schedule.checkins.get()
				.filter((c) => c.prompt.get() == PROMPT_EVENT && c.fallback.get() == FALLBACK_VARIABLE)
				.map((c) => ({ name: c.fallbackVariable.get(), label: Lang.get("calendar_sample_event", this.questionnaireTitle(study, c.questionnaireId.get())), fallback: 12 * HOUR })),
		]
		const out: { name: string, label: string, obs: ObservablePrimitive<number> }[] = []
		for(const entry of entries) {
			const name = entry.name.trim()
			if(!name || out.some((o) => o.name == name))
				continue
			if(!this.variables[name]) {
				this.variables[name] = new ObservablePrimitive<number>(entry.fallback, null, `calendarVar_${name}`)
				this.variables[name].addObserver(() => this.schedulePlan())
			}
			out.push({ name, label: entry.label, obs: this.variables[name] })
		}
		return out
	}

	private schedulePlan(): void {
		window.clearTimeout(this.planTimer)
		this.planTimer = window.setTimeout(() => this.loadPlan(), 400)
	}

	private async loadPlan(): Promise<void> {
		const study = this.getStudyOrThrow()
		const variables: Record<string, string> = {}
		for(const v of this.planVariables(study)) {
			if(v.obs.get() >= 0)
				variables[v.name] = clock(v.obs.get())
		}
		const joined = Date.parse(`${this.joinDate.get()}T00:00:00Z`)
		try {
			const result = await this.sectionData.loader.loadJson(
				`${FILE_ADMIN}?type=GetSchedulePlan&study_id=${study.id.get()}`,
				"post",
				JSON.stringify({
					study: { id: study.id.get(), schedule: study.schedule.createJson(), questionnaires: study.questionnaires.get().map((q) => ({ internalId: q.internalId.get() })) },
					joined: isNaN(joined) ? Date.UTC(new Date().getFullYear(), new Date().getMonth(), new Date().getDate()) : joined,
					days: this.planDays.get(),
					variables: variables,
				})
			)
			this.plan = result.days as CalendarDay[]
			this.planEvents = this.events(study, this.plan, false)
			this.planError = null
		}
		catch(e: any) {
			this.planError = e?.message ?? String(e)
		}
		m.redraw()
	}

	// ── participants ────────────────────────────────────────────────────────────────────────────────

	private async loadOverview(): Promise<void> {
		this.overviewLoading = true
		m.redraw()
		try {
			const result = await this.sectionData.loader.loadJson(`${FILE_ADMIN}?type=GetScheduleMonitor&study_id=${this.getStudyOrThrow().id.get()}`)
			this.overview = result.participants as MonitoredParticipant[]
		}
		finally {
			this.overviewLoading = false
			m.redraw()
		}
	}

	private async loadParticipant(userId: string): Promise<void> {
		this.participantLoading = true
		m.redraw()
		try {
			const study = this.getStudyOrThrow()
			const result = await this.sectionData.loader.loadJson(
				`${FILE_ADMIN}?type=GetScheduleMonitor&study_id=${study.id.get()}`,
				"post",
				`user_id=${encodeURIComponent(userId)}`
			)
			this.participant = result as MonitoredParticipant
			const nowLocal = Date.now() - (this.participant.tz?.offsetMin ?? 0) * MINUTE // the calendar runs on the participant's wall clock
			this.participantEvents = this.events(study, this.participant.days ?? [], true, nowLocal)
		}
		finally {
			this.participantLoading = false
			m.redraw()
		}
	}

	// ── events ──────────────────────────────────────────────────────────────────────────────────────

	/** Calendar events: each window from when it opens to when it closes, and the night between bedtime and wake-up shaded. */
	private events(study: Study, days: CalendarDay[], monitor: boolean, nowLocal = Date.now()): EventInput[] {
		const events: EventInput[] = []
		days.forEach((day, k) => {
			const nextWake = days[k + 1]?.wake
			if(nextWake != null && nextWake > day.bed)
				events.push({ start: day.bed, end: nextWake, display: "background", color: "#90a4ae" })
			for(const w of day.windows) {
				const title = this.questionnaireTitle(study, w.qid)
				const details: string[] = []
				if(w.prompt != null && !w.pushes) // with the push log, each notification is drawn on its own below
					details.push(`🔔 ${clock(w.prompt)}${w.reminders.length ? ` (+${w.reminders.length})` : ""}`)
				if(monitor && w.state)
					details.push(Lang.get(STATE_LABELS[w.state]) + (w.completedAt ? ` ${clock(w.completedAt)}` : ""))
				events.push({
					title: details.length ? `${title} · ${details.join(" · ")}` : title,
					start: w.open,
					end: Math.max(w.close, w.open + 15 * MINUTE),
					color: monitor && w.state ? STATE_COLORS[w.state] : this.colors[w.qid],
					extendedProps: w.pushes ? { notifications: w.pushes.map((n) => this.notificationLine(n, nowLocal)) } : {},
				})
			}
		})
		return events
	}

	/** One planned notification as a line in its window: due time and how far it got, coloured, with the whole chain on hover. */
	private notificationLine(n: NotificationRecord, nowLocal: number): NotificationLine {
		const status = pushStatus(n, nowLocal)
		const reached = status === "opened" ? n.clicked : status === "received" ? n.received : status === "sent" ? n.sent : status === "failed" ? n.failed : null
		const step = (label: LangKey, t: number | null) => t == null ? null : `${Lang.get(label)} ${clockSeconds(t)}`
		return {
			text: `${clock(n.at)} ${Lang.get(PUSH_LABELS[status])}${reached != null ? ` ${clock(reached)}` : ""}`,
			color: PUSH_COLORS[status],
			tooltip: [`${Lang.get("calendar_push_due")} ${clockSeconds(n.at)}`, step("calendar_push_sent", n.sent), step("calendar_push_failed", n.failed),
				step("calendar_push_received", n.received), step("calendar_push_opened", n.clicked)].filter((x) => x != null).join(" · "),
		}
	}

	// ── views ───────────────────────────────────────────────────────────────────────────────────────

	private planView(study: Study): Vnode<any, any> {
		const variables = this.planVariables(study)
		const schedule = study.schedule
		const initialDate = Date.parse(`${this.joinDate.get()}T00:00:00Z`) || Date.now()
		return <div>
			{DashRow(
				DashElement("stretched", {
					content: <div>
						<div class="horizontal hAlignStart vAlignCenter" style="flex-wrap:wrap;gap:16px">
							<label>
								<small>{Lang.get("calendar_join_date")}</small>
								<input type="date" value={this.joinDate.get()} onchange={(e: InputEvent) => this.joinDate.set((e.target as HTMLInputElement).value)} />
							</label>
							<label>
								<small>{Lang.get("calendar_days")}</small>
								<input type="number" min="1" max="120" style="width:5em" value={this.planDays.get()}
									onchange={(e: InputEvent) => this.planDays.set(Math.max(1, Math.min(120, parseInt((e.target as HTMLInputElement).value) || 14)))} />
							</label>
							{variables.map((v) =>
								<label>
									<small>{v.label}</small>
									<input type="time" {...BindObservable(v.obs, TimeTransformer)} />
									<small class="spacingLeft">{v.name}</small>
								</label>
							)}
						</div>
						<p class="smallText">
							{variables.length
								? Lang.get("calendar_plan_info")
								: Lang.get("calendar_plan_info_defaults", clock(schedule.wakeDefault.get()), clock(schedule.bedDefault.get()))}
						</p>
						{this.planError && <div class="highlight">{this.planError}</div>}
					</div>
				})
			)}
			{this.legend(study, false)}
			{this.plan && !this.plan.some((d) => d.windows.length) && <div class="center spacingTop">{Lang.get("calendar_no_windows")}</div>}
			{m(CalendarView, { events: this.planEvents, initialDate: initialDate })}
		</div>
	}

	private legend(study: Study, monitor: boolean): Vnode<any, any> {
		const swatch = (color: string, label: string) =>
			<span class="horizontal vAlignCenter" style="gap:4px"><span style={`display:inline-block;width:12px;height:12px;border-radius:2px;background:${color}`}></span>{label}</span>
		const qids = Array.from(new Set(study.schedule.checkins.get().map((c) => c.questionnaireId.get())))
		return <div class="horizontal hAlignStart smallText spacingTop spacingBottom" style="flex-wrap:wrap;gap:12px">
			{monitor
				? STATES.map((s) => swatch(STATE_COLORS[s], Lang.get(STATE_LABELS[s])))
				: qids.map((qid) => swatch(this.colors[qid] ?? "#888", this.questionnaireTitle(study, qid)))}
			{swatch("#90a4ae66", Lang.get("calendar_night"))}
			{monitor && <span class="horizontal vAlignCenter" style="gap:12px;flex-wrap:wrap">
				<strong>🔔 {Lang.get("calendar_push_title")}</strong>
				{PUSH_STATUSES.map((s) => swatch(PUSH_COLORS[s], Lang.get(PUSH_LABELS[s])))}
			</span>}
		</div>
	}

	private monitorView(study: Study): Vnode<any, any> {
		const list = this.overview ?? []
		const total = list.reduce((acc, p) => ({ expected: acc.expected + p.summary.expected, completed: acc.completed + p.summary.completed }), { expected: 0, completed: 0 })
		return <div>
			{TitleRow(Lang.getWithColon("calendar_participants"))}
			<div class="horizontal hAlignStart vAlignCenter" style="gap:16px">
				<span>{Lang.get("calendar_overall", list.length, percent(total.completed, total.expected), total.completed, total.expected)}</span>
				{BtnReload(() => this.loadOverview())}
				{LoadingSpinner(!this.overviewLoading)}
			</div>
			<p class="smallText">{Lang.get("calendar_monitor_info")}</p>
			{list.length == 0 && !this.overviewLoading
				? <div class="center spacingTop">{Lang.get("calendar_no_participants")}</div>
				: <div style="overflow-x:auto">
					<table class="stripes">
						<thead>
							<tr>
								<th>{Lang.get("calendar_participant")}</th>
								<th>{Lang.get("calendar_enrolled")}</th>
								<th>{Lang.get("calendar_study_day")}</th>
								<th>{Lang.get("calendar_compliance")}</th>
								<th>{Lang.get("calendar_state_missed")}</th>
								<th>{Lang.get("calendar_last_completion")}</th>
								<th>{Lang.get("calendar_timezone")}</th>
							</tr>
						</thead>
						<tbody>
							{list.map((p) =>
								<tr class={this.participant?.userId == p.userId ? "highlight" : ""}>
									<td>
										<a onclick={() => this.loadParticipant(p.userId)}>{p.userId}</a>
										{p.withdrawnAt != null && <small class="spacingLeft">({Lang.get("calendar_withdrawn")})</small>}
									</td>
									<td>{p.joined != null ? localDateTime(p.joined) : "—"}</td>
									<td>{p.day ?? "—"}</td>
									<td>{percent(p.summary.completed, p.summary.expected)} <small>({p.summary.completed}/{p.summary.expected})</small></td>
									<td>{p.summary.missed}</td>
									<td>{p.lastCompletion != null ? localDateTime(p.lastCompletion) : "—"}</td>
									<td><small>{zoneLabel(p.tz)}</small></td>
								</tr>
							)}
						</tbody>
					</table>
				</div>
			}
			{this.participantLoading && <div class="center">{LoadingSpinner(false)}</div>}
			{this.participant && this.participantView(study, this.participant)}
		</div>
	}

	private participantView(study: Study, p: MonitoredParticipant): Vnode<any, any> {
		const today = p.joined != null && p.day != null ? Date.parse(`${new Date(p.joined).toISOString().slice(0, 10)}T00:00:00Z`) + p.day * DAY : Date.now()
		return <div>
			{TitleRow(`${p.userId} — ${zoneLabel(p.tz)}`)}
			<div class="horizontal hAlignStart smallText" style="flex-wrap:wrap;gap:16px">
				{Object.entries(p.summary.perQuestionnaire).map(([qid, s]) =>
					<span>{this.questionnaireTitle(study, parseInt(qid))}: {percent(s.completed, s.expected)} ({s.completed}/{s.expected})</span>
				)}
			</div>
			{!p.tz && <p class="smallText highlight">{Lang.get("calendar_tz_unknown_info")}</p>}
			{this.legend(study, true)}
			<p class="smallText">{Lang.get("calendar_push_info")}</p>
			{m(CalendarView, { events: this.participantEvents, initialDate: today })}
		</div>
	}

	public getView(): Vnode<any, any> {
		const study = this.getStudyOrThrow()
		if(!study.schedule.checkins.get().length)
			return <div class="center spacingTop">{Lang.get("schedule_no_checkins_short")}</div>
		return <div>
			<p class="smallText">{Lang.get("calendar_description")}</p>
			{TabBar(this.tab, [
				{ title: Lang.get("calendar_tab_plan"), view: () => this.planView(study) },
				this.hasPermission("read", study.id.get()) && { title: Lang.get("calendar_tab_participants"), view: () => this.monitorView(study) },
			])}
		</div>
	}
}
