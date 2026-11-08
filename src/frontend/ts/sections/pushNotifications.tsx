import { SectionContent } from "../site/SectionContent";
import m, { Vnode } from "mithril";
import { Lang } from "../singletons/Lang";
import { BindObservable } from "../components/BindObservable";
import { ObservableLangChooser } from "../components/ObservableLangChooser";
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
		totals: FunnelCounts
		/** The same funnel per delivery channel (rows logged before channels existed count as web). */
		channels?: Record<"web" | "android", FunnelCounts>
		/** Onboarding: did the welcome notification show up? (participant's answer) */
		welcome?: { confirmed: number, missed: number }
		series: { day: number, sent: number, received: number, clicked: number }[]
		participants: { u: string, channel?: "web" | "android", scheduled?: number, sent: number, received: number, clicked: number, welcome?: "confirmed" | "missed" | null, off?: boolean }[]
	}
	/** The first days after enrolment as participants get them, on every channel (NotificationPreview.php). */
	preview?: PreviewNotification[]
	/** Icon, badge and accent every channel uses (NotificationSpec.php). */
	look?: { icon: string, badge: string, accent: string }
	sender: { lastRunMs: number, studies: number, queued: number } | null
	/** Participants who left the study (sign-out / quit event, or withdrawn here); nothing is sent to them. */
	withdrawn: { userId: string, at: number, source: string }[]
	/** Participants who switched notifications off in the app; they stay in the study. */
	notificationsOff?: { userId: string, at: number, channel: "web" | "android" }[]
	nowMs: number
}

interface FunnelCounts { scheduled?: number, sent: number, failed: number, received: number, clicked: number }

interface PreviewNotification {
	day: number
	timeOfDay: number
	title: string
	body: string
	items: { qid: number, type: "availability" | "reminder", title: string, body: string }[]
}

function formatTimeOfDay(ms: number): string {
	const mins = Math.floor(ms / 60000)
	return `${String(Math.floor(mins / 60)).padStart(2, "0")}:${String(mins % 60).padStart(2, "0")}`
}

/** Percentage helper, guarding divide-by-zero. */
function pct(part: number, whole: number): string {
	return whole > 0 ? `${Math.round((part / whole) * 100)}%` : "—"
}

/**
 * Dedicated study-level admin panel for notifications on every channel (web push for the browser PWA, the
 * Android app): enable reminders, preview exactly what participants will get (the same on each channel), and
 * monitor the delivery funnel per channel — sent → arrived (shown on the device) → opened (tapped) — with a
 * per-day series, per-participant breakdown, and an installed-app-vs-browser + device split. Plus a test push.
 */
export class Content extends SectionContent {
	private stats: PushStats | null
	private pushTestResult: string | null = null
	private participantTestResults: Record<string, string> = {}
	private withdrawUserId = ""
	private withdrawResult: string | null = null
	private eraseUserId = ""
	private eraseConfirmId = ""
	private eraseWithdraw = true
	private eraseResult: string | null = null

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

	private isWithdrawn(userId: string): boolean {
		return !!this.stats?.withdrawn?.some(w => w.userId === userId)
	}

	/**
	 * Withdraw one participant: the server deletes their push subscription, refuses a new one, stops
	 * the native app's nudges and the chat dispatcher for them, and the app resets itself on next open.
	 * Covers participants who uninstalled without signing out (the server cannot see an uninstall).
	 */
	private async withdrawParticipant(study: Study, userId: string): Promise<void> {
		userId = userId.trim()
		if(!userId || !confirm(Lang.get("withdraw_participant_confirm", userId)))
			return
		this.withdrawResult = "…"
		m.redraw()
		try {
			await this.sectionData.loader.loadJson(
				`${FILE_ADMIN}?type=WithdrawParticipant&study_id=${study.id.get()}`,
				"post",
				`user_id=${encodeURIComponent(userId)}`
			)
			this.withdrawResult = `✅ ${userId}`
			this.withdrawUserId = ""
		} catch(e) {
			this.withdrawResult = `❌ ${userId}`
		}
		await this.reload(study)
	}

	/**
	 * Remove everything one participant left in the study (answers in every copy, media, messages, push and chat state, wearable
	 * data, database rows). For a participant who asked the research team to delete their data. The id is typed twice and the
	 * browser asks again; the server checks both.
	 */
	private async eraseParticipant(study: Study): Promise<void> {
		const userId = this.eraseUserId.trim()
		const confirmId = this.eraseConfirmId.trim()
		if(!userId)
			return
		if(userId !== confirmId) {
			this.eraseResult = `❌ ${Lang.get("erase_participant_mismatch")}`
			return
		}
		if(!confirm(Lang.get("erase_participant_confirm", userId)))
			return
		this.eraseResult = "…"
		m.redraw()
		try {
			const r = await this.sectionData.loader.loadJson(
				`${FILE_ADMIN}?type=DeleteParticipantData&study_id=${study.id.get()}`,
				"post",
				`user_id=${encodeURIComponent(userId)}&confirm_user_id=${encodeURIComponent(confirmId)}${this.eraseWithdraw ? "&withdraw=1" : ""}`
			)
			const total = Object.values(r.removed ?? {}).reduce((sum: number, n: any) => sum + Number(n), 0)
			if(r.complete) {
				this.eraseResult = `✅ ${Lang.get("erase_participant_done", total)}`
				this.eraseUserId = ""
				this.eraseConfirmId = ""
			}
			else
				this.eraseResult = `❌ ${Lang.get("erase_participant_partial", (r.failed ?? []).join(", "))}`
		} catch(e) {
			this.eraseResult = `❌ ${Lang.get("erase_participant_failed")}`
		}
		await this.reload(study)
	}

	private eraseView(study: Study): Vnode<any, any> {
		return <div class="verticalPadding">
			<div class="center"><b>{Lang.get("erase_participant")}</b></div>
			<div class="center"><small>{Lang.get("erase_participant_info")}</small></div>
			<div class="center verticalPadding">
				<input
					type="text"
					placeholder={Lang.get("withdraw_participant_id")}
					value={this.eraseUserId}
					oninput={(e: InputEvent) => { this.eraseUserId = (e.target as HTMLInputElement).value }}
				/>
				&nbsp;
				<input
					type="text"
					placeholder={Lang.get("erase_participant_id_again")}
					value={this.eraseConfirmId}
					oninput={(e: InputEvent) => { this.eraseConfirmId = (e.target as HTMLInputElement).value }}
					onkeydown={(e: KeyboardEvent) => { if(e.key === "Enter") this.eraseParticipant(study) }}
				/>
			</div>
			<div class="center">
				<label>
					<input
						type="checkbox"
						checked={this.eraseWithdraw}
						onchange={(e: Event) => { this.eraseWithdraw = (e.target as HTMLInputElement).checked }}
					/>
					&nbsp;{Lang.get("erase_participant_also_withdraw")}
				</label>
				&nbsp;
				<button type="button" onclick={() => this.eraseParticipant(study)}>{Lang.get("erase_participant_button")}</button>
				{this.eraseResult && <small>&nbsp;{this.eraseResult}</small>}
			</div>
		</div>
	}

	private withdrawnView(s: PushStats, study: Study): Vnode<any, any> {
		const list = s.withdrawn ?? []
		return <div>
			<div class="center"><small>{Lang.get("withdraw_participant_info")}</small></div>
			<div class="center verticalPadding">
				<input
					type="text"
					placeholder={Lang.get("withdraw_participant_id")}
					value={this.withdrawUserId}
					oninput={(e: InputEvent) => { this.withdrawUserId = (e.target as HTMLInputElement).value }}
					onkeydown={(e: KeyboardEvent) => { if(e.key === "Enter") this.withdrawParticipant(study, this.withdrawUserId) }}
				/>
				&nbsp;
				<button type="button" onclick={() => this.withdrawParticipant(study, this.withdrawUserId)}>{Lang.get("withdraw_participant")}</button>
				{this.withdrawResult && <small>&nbsp;{this.withdrawResult}</small>}
			</div>
			{this.eraseView(study)}
			{list.length === 0
				? <div class="center"><small>{Lang.get("withdrawn_none")}</small></div>
				: <table class="boxStyle" style="width:100%"><tbody>
					<tr><th>{Lang.get("participant")}</th><th>{Lang.get("withdrawn")}</th><th></th></tr>
					{list.map(w => <tr>
						<td style="font-family:monospace;word-break:break-all">{w.userId}</td>
						<td class="center"><small>{w.at ? new Date(w.at).toLocaleString() : "—"}</small></td>
						<td class="center"><small>{w.source === "admin" ? Lang.get("withdrawn_source_admin") : w.source === "quit" ? Lang.get("withdrawn_source_quit") : ""}</small></td>
					</tr>)}
				</tbody></table>}
		</div>
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

	/**
	 * One funnel row per channel. Web push counts what the push service accepted; the Android app counts what
	 * it handed to the phone's alarm service plus the server nudges it fetched. "Arrived" and "Opened" mean the
	 * same on both: shown on the device, tapped by the participant.
	 */
	private funnelView(s: PushStats): Vnode<any, any> {
		const t = s.events.totals
		const channels = s.events.channels ?? { web: t, android: { sent: 0, failed: 0, received: 0, clicked: 0 } }
		const row = (label: string, c: FunnelCounts) => {
			const out = c.sent + (c.scheduled ?? 0)
			return <tr>
				<td class="verticalPadding"><b>{label}</b></td>
				<td class="verticalPadding center">{out}</td>
				<td class="verticalPadding center"><small>{pct(c.received, out)}</small></td>
				<td class="verticalPadding center">{c.received}</td>
				<td class="verticalPadding center"><small>{pct(c.clicked, c.received)}</small></td>
				<td class="verticalPadding center">{c.clicked}</td>
			</tr>
		}
		return <div class="center">
			<table class="boxStyle"><tbody>
				<tr>
					<th></th>
					<th>{Lang.get("web_push_sent")}</th><th>→</th>
					<th>{Lang.get("web_push_arrived")}</th><th>→</th>
					<th>{Lang.get("web_push_opened")}</th>
				</tr>
				{row(Lang.get("notification_channel_web"), channels.web)}
				{row(Lang.get("notification_channel_android"), channels.android)}
			</tbody></table>
			<small>{Lang.get("notification_channel_android_info")}</small>
			{t.failed > 0 && <div><small>{Lang.getWithColon("web_push_failed")} {t.failed}</small></div>}
			{s.events.welcome && (s.events.welcome.confirmed + s.events.welcome.missed) > 0 && <div class="verticalPadding">
				<small>{Lang.getWithColon("welcome_notification")} {Lang.get("welcome_notification_counts", s.events.welcome.confirmed, s.events.welcome.missed)}</small>
				{s.events.welcome.missed > 0 && <div><small class="highlight">⚠ {Lang.get("welcome_notification_missed_info")}</small></div>}
			</div>}
			{(s.notificationsOff?.length ?? 0) > 0 && <div><small>{Lang.get("notifications_off_count", s.notificationsOff!.length)}</small></div>}
		</div>
	}

	/**
	 * What participants will be sent, computed on the server by the code that sends it. The content is written
	 * once (questionnaire title, each trigger's notification text) and is the same on every channel.
	 */
	private previewView(s: PushStats): Vnode<any, any> {
		const list = s.preview ?? []
		const look = s.look
		if(list.length === 0)
			return <div class="center"><small>{Lang.get("notification_preview_none")}</small></div>
		return <div>
			<div class="center"><small>{Lang.get("notification_preview_info")}</small></div>
			<table class="boxStyle" style="width:100%"><tbody>
				<tr><th>{Lang.get("notification_preview_day")}</th><th>{Lang.get("notification_preview_time")}</th><th>{Lang.get("notification_preview_notification")}</th></tr>
				{list.map(n => <tr>
					<td class="center">{n.day + 1}</td>
					<td class="center" style="white-space:nowrap">
						{formatTimeOfDay(n.timeOfDay)}
						{n.items.some(i => i.type === "reminder") && <div><small>{Lang.get("notification_preview_reminder")}</small></div>}
					</td>
					<td>
						<div style="display:flex;gap:8px;align-items:flex-start">
							{look && <img src={look.icon} alt="" width="32" height="32" style={`border-radius:50%;outline:2px solid ${look.accent}`} />}
							<div><b>{n.title}</b><br />{n.body}</div>
						</div>
					</td>
				</tr>)}
			</tbody></table>
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
				<th>{Lang.get("notification_channel")}</th>
				<th>{Lang.get("web_push_sent")}</th>
				<th>{Lang.get("web_push_arrived")}</th>
				<th>{Lang.get("web_push_opened")}</th>
				<th>{Lang.get("welcome_notification")}</th>
				<th></th>
			</tr>
			{rows.map(p => {
				const result = this.participantTestResults[p.u]
				const withdrawn = this.isWithdrawn(p.u)
				return <tr>
					<td style="font-family:monospace;word-break:break-all">{p.u}</td>
					<td class="center"><small>{p.channel === "android" ? Lang.get("notification_channel_android") : Lang.get("notification_channel_web")}</small></td>
					<td class="center">{p.sent + (p.scheduled ?? 0)}</td>
					<td class="center">{p.received}</td>
					<td class="center">{p.clicked}</td>
					<td class="center"><small>
						{p.welcome === "confirmed" ? `✓ ${Lang.get("welcome_confirmed")}` : p.welcome === "missed" ? <span class="highlight">⚠ {Lang.get("welcome_missed")}</span> : "—"}
						{p.off && <div>{Lang.get("notifications_off_by_participant")}</div>}
					</small></td>
					<td class="center" style="white-space:nowrap">
						{withdrawn
							? <small>{Lang.get("withdrawn")}</small>
							: result
								? <small>{result}</small>
								: <span>
									{p.channel !== "android" && !p.off && <button
										type="button"
										style="padding:2px 8px;font-size:0.8em"
										onclick={() => this.sendTestPushToParticipant(study, p.u)}
									>{Lang.get("send_test_notification")}</button>}
									&nbsp;
									<button
										type="button"
										style="padding:2px 8px;font-size:0.8em"
										onclick={() => this.withdrawParticipant(study, p.u)}
									>{Lang.get("withdraw_participant")}</button>
								</span>}
					</td>
				</tr>
			})}
			{s.events.participants.length > rows.length &&
				<tr><td colspan="7"><small>{Lang.get("web_push_more_participants", s.events.participants.length - rows.length)}</small></td></tr>}
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
				}),
				DashElement("stretched", {
					content:
						<div class="vAlignCenter">
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(study.androidNotificationsEnabled)} />
								<span>{Lang.get('enable_android_notifications')}</span>
								<small>{Lang.get('enable_android_notifications_info')}</small>
							</label>
						</div>
				})
			)}

			{study.webPushEnabled.get() && DashRow(
				DashElement("stretched", {
					content:
						<div>
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(study.studyEndNotificationEnabled)} />
								<span>{Lang.get('study_end_notification')}</span>
								<small>{Lang.get('study_end_notification_info')}</small>
							</label>
							{study.studyEndNotificationEnabled.get() &&
								<label class="line spacingTop">
									<small>{Lang.getWithColon('study_end_notification_text')} ({Lang.get('can_be_left_empty')})</small>
									<input type="text" placeholder={Lang.get('default_study_end_notification')} {...BindObservable(study.studyEndNotification)} />
									{ObservableLangChooser(study)}
								</label>}
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
			</div>}

			{s && <div>
				{TitleRow(Lang.getWithColon("notification_preview"))}
				{DashRow(DashElement("stretched", { content: this.previewView(s) }))}

				{TitleRow(Lang.get("web_push_funnel"))}
				{DashRow(DashElement("stretched", { content: this.funnelView(s) }))}

				{TitleRow(Lang.getWithColon("web_push_last_14_days"))}
				{DashRow(DashElement("stretched", { content: this.seriesView(s) }))}

				{TitleRow(Lang.getWithColon("web_push_per_participant"))}
				{DashRow(DashElement("stretched", { content: this.participantsView(s, study) }))}

				{DashRow(DashElement("stretched", {
					content: <div class="center verticalPadding">
						{study.webPushEnabled.get() && <button type="button" onclick={() => this.sendTestPush(study)}>{Lang.get("send_test_notification")}</button>}
						{this.pushTestResult && <small>&nbsp;{this.pushTestResult}</small>}
						&nbsp;
						<button type="button" onclick={() => this.reload(study)}>{Lang.get("reload")}</button>
					</div>
				}))}
			</div>}

			{s && <div>
				{TitleRow(Lang.getWithColon("withdrawn_participants"))}
				{DashRow(DashElement("stretched", { content: this.withdrawnView(s, study) }))}
			</div>}
		</div>
	}
}
