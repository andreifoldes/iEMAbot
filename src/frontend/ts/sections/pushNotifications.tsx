import { SectionContent } from "../site/SectionContent";
import m, { Vnode } from "mithril";
import { Lang } from "../singletons/Lang";
import { BindObservable } from "../components/BindObservable";
import { Study } from "../data/study/Study";
import { DashRow } from "../components/DashRow";
import { DashElement } from "../components/DashElement";
import { TitleRow } from "../components/TitleRow";
import { SectionData } from "../site/SectionData";
import { Requests } from "../singletons/Requests";
import { FILE_ADMIN } from "../constants/urls";

interface PushStats {
	vapidConfigured: boolean
	subscriptions: number
	clients: { installed: number, browser: number, total: number, devices: Record<string, number> }
	events: {
		totals: { sent: number, failed: number, received: number, clicked: number }
		series: { day: number, sent: number, received: number, clicked: number }[]
		participants: { u: string, sent: number, received: number, clicked: number }[]
	}
	sender: { lastRunMs: number, studies: number, queued: number } | null
	nowMs: number
}

/** Percentage helper, guarding divide-by-zero. */
function pct(part: number, whole: number): string {
	return whole > 0 ? `${Math.round((part / whole) * 100)}%` : "—"
}

/**
 * Dedicated study-level admin panel for web push: enable reminders and monitor the full
 * delivery funnel — sent (accepted by the push service) → arrived (the device's service
 * worker received it) → opened (the participant tapped it) — with a per-day series,
 * per-participant breakdown, and an installed-app-vs-browser + device split. Plus a test push.
 */
export class Content extends SectionContent {
	private stats: PushStats | null
	private pushTestResult: string | null = null
	private participantTestResults: Record<string, string> = {}

	public static preLoad(sectionData: SectionData): Promise<any>[] {
		return [
			Requests.loadJson(`${FILE_ADMIN}?type=GetPushStats&study_id=${sectionData.getStaticInt("id") ?? 0}`).catch(() => null),
			sectionData.getStudyPromise()
		]
	}

	constructor(sectionData: SectionData, stats: PushStats | null) {
		super(sectionData)
		this.stats = stats
	}

	public title(): string {
		return Lang.get("push_notifications")
	}

	private async reload(study: Study): Promise<void> {
		this.stats = await this.sectionData.loader.loadJson(`${FILE_ADMIN}?type=GetPushStats&study_id=${study.id.get()}`)
		m.redraw()
	}

	private async sendTestPush(study: Study): Promise<void> {
		const r = await this.sectionData.loader.loadJson(`${FILE_ADMIN}?type=SendTestPush&study_id=${study.id.get()}`, "post")
		this.pushTestResult = `${r.succeeded ?? 0} / ${r.queued ?? 0} ${Lang.get("web_push_delivered")}`
		await this.reload(study)
	}

	private async sendTestPushToParticipant(study: Study, userId: string): Promise<void> {
		this.participantTestResults[userId] = "…"
		m.redraw()
		try {
			const r = await this.sectionData.loader.loadJson(
				`${FILE_ADMIN}?type=SendTestPushToParticipant&study_id=${study.id.get()}`,
				"post",
				`user_id=${encodeURIComponent(userId)}`
			)
			this.participantTestResults[userId] = r.succeeded > 0 ? "✅" : (r.error ?? "❌")
		} catch(_) {
			this.participantTestResults[userId] = "❌"
		}
		m.redraw()
		setTimeout(() => { delete this.participantTestResults[userId]; m.redraw() }, 4000)
	}

	/**
	 * Sender liveness. Delivery counts can look "stuck" for many reasons, but a heartbeat
	 * that hasn't advanced in minutes means the per-minute cron itself is dead — a distinct,
	 * higher-priority failure that the funnel alone can't reveal. Fresh (<3 min) shows the
	 * last-run age; stale or never-run shows a warning.
	 */
	private senderView(s: PushStats): Vnode<any, any> {
		const hb = s.sender
		if(!hb)
			return <div class="center"><small class="highlight">⚠ {Lang.get("web_push_sender_never")}</small></div>
		const ageSec = Math.max(0, Math.round((s.nowMs - hb.lastRunMs) / 1000))
		const ago = ageSec < 90 ? `${ageSec}s` : `${Math.round(ageSec / 60)} min`
		return <div class="center">
			{ageSec > 180
				? <small class="highlight">⚠ {Lang.get("web_push_sender_stale", ago)}</small>
				: <small>{Lang.getWithColon("web_push_sender")} {Lang.get("web_push_sender_ago", ago)}</small>}
		</div>
	}

	private funnelView(s: PushStats): Vnode<any, any> {
		const t = s.events.totals
		return <div class="center">
			<table class="boxStyle"><tbody>
				<tr>
					<td class="verticalPadding"><b>{Lang.get("web_push_sent")}</b><br />{t.sent}</td>
					<td class="verticalPadding">→<br /><small>{pct(t.received, t.sent)}</small></td>
					<td class="verticalPadding"><b>{Lang.get("web_push_arrived")}</b><br />{t.received}</td>
					<td class="verticalPadding">→<br /><small>{pct(t.clicked, t.received)}</small></td>
					<td class="verticalPadding"><b>{Lang.get("web_push_opened")}</b><br />{t.clicked}</td>
				</tr>
			</tbody></table>
			{t.failed > 0 && <small>{Lang.getWithColon("web_push_failed")} {t.failed}</small>}
		</div>
	}

	private seriesView(s: PushStats): Vnode<any, any> {
		const max = Math.max(1, ...s.events.series.map(d => d.sent))
		return <table style="width:100%"><tbody>
			{s.events.series.map(d => {
				const date = new Date(d.day).toLocaleDateString([], { weekday: "short", day: "numeric", month: "short" })
				const bar = (n: number, color: string) =>
					<div style={`display:inline-block;height:10px;width:${Math.round((n / max) * 120)}px;background:${color};vertical-align:middle`}></div>
				return <tr>
					<td style="white-space:nowrap"><small>{date}</small></td>
					<td>{bar(d.sent, "#bbb")}{bar(d.received, "#5b9bd5")}{bar(d.clicked, "#4caf50")}
						<small>&nbsp;{d.sent} / {d.received} / {d.clicked}</small></td>
				</tr>
			})}
		</tbody></table>
	}

	private participantsView(s: PushStats, study: Study): Vnode<any, any> {
		const rows = s.events.participants.slice(0, 100)
		return <table class="boxStyle" style="width:100%"><tbody>
			<tr>
				<th>{Lang.get("participant")}</th>
				<th>{Lang.get("web_push_sent")}</th>
				<th>{Lang.get("web_push_arrived")}</th>
				<th>{Lang.get("web_push_opened")}</th>
				<th></th>
			</tr>
			{rows.map(p => {
				const result = this.participantTestResults[p.u]
				return <tr>
					<td style="font-family:monospace;word-break:break-all">{p.u}</td>
					<td class="center">{p.sent}</td>
					<td class="center">{p.received}</td>
					<td class="center">{p.clicked}</td>
					<td class="center" style="white-space:nowrap">
						{result
							? <small>{result}</small>
							: <button
								type="button"
								style="padding:2px 8px;font-size:0.8em"
								onclick={() => this.sendTestPushToParticipant(study, p.u)}
							>{Lang.get("send_test_notification")}</button>}
					</td>
				</tr>
			})}
			{s.events.participants.length > rows.length &&
				<tr><td colspan="5"><small>{Lang.get("web_push_more_participants", s.events.participants.length - rows.length)}</small></td></tr>}
		</tbody></table>
	}

	public getView(): Vnode<any, any> {
		const study = this.getStudyOrThrow()
		const s = this.stats
		const dev = s?.clients.devices ?? { mobile: 0, tablet: 0, desktop: 0, unknown: 0 }
		return <div>
			{DashRow(
				DashElement("stretched", {
					content:
						<div class="vAlignCenter">
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(study.webPushEnabled)} />
								<span>{Lang.get('enable_web_push')}</span>
								<small>{Lang.get('enable_web_push_info')}</small>
							</label>
						</div>
				})
			)}

			{study.webPushEnabled.get() && s && <div>
				{DashRow(
					DashElement(null, {
						content: <div class="center">
							<div class="dashTitle">{Lang.get("web_push_subscriptions")}</div>
							<span style="font-size:1.6em">{s.subscriptions}</span>
							{!s.vapidConfigured && <div><small class="highlight">{Lang.get("web_push_no_vapid")}</small></div>}
						</div>
					}),
					DashElement(null, {
						content: <div class="center">
							<div class="dashTitle">{Lang.get("web_push_install")}</div>
							<div>{Lang.getWithColon("web_push_installed")} <b>{s.clients.installed}</b></div>
							<div>{Lang.getWithColon("web_push_in_browser")} <b>{s.clients.browser}</b></div>
							<small>{Lang.get("mobile")}: {dev.mobile} · {Lang.get("tablet")}: {dev.tablet} · {Lang.get("desktop")}: {dev.desktop}</small>
						</div>
					})
				)}

				{DashRow(DashElement("stretched", { content: this.senderView(s) }))}

				{TitleRow(Lang.get("web_push_funnel"))}
				{DashRow(DashElement("stretched", { content: this.funnelView(s) }))}

				{TitleRow(Lang.getWithColon("web_push_last_14_days"))}
				{DashRow(DashElement("stretched", { content: this.seriesView(s) }))}

				{TitleRow(Lang.getWithColon("web_push_per_participant"))}
				{DashRow(DashElement("stretched", { content: this.participantsView(s, study) }))}

				{DashRow(DashElement("stretched", {
					content: <div class="center verticalPadding">
						<button type="button" onclick={() => this.sendTestPush(study)}>{Lang.get("send_test_notification")}</button>
						{this.pushTestResult && <small>&nbsp;{this.pushTestResult}</small>}
						&nbsp;
						<button type="button" onclick={() => this.reload(study)}>{Lang.get("reload")}</button>
					</div>
				}))}
			</div>}
		</div>
	}
}
