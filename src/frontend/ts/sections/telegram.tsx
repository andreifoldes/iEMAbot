import { SectionContent } from "../site/SectionContent";
import m, { Vnode } from "mithril";
import qrcode from "qrcode-generator"
import { Lang } from "../singletons/Lang";
import { BindObservable } from "../components/BindObservable";
import { Study } from "../data/study/Study";
import { DashRow } from "../components/DashRow";
import { DashElement } from "../components/DashElement";
import { TitleRow } from "../components/TitleRow";
import { BtnCopy } from "../components/Buttons";
import { SectionData } from "../site/SectionData";
import { Requests } from "../singletons/Requests";
import { FILE_ADMIN } from "../constants/urls";
import { checkTelegramCompatibility, TelegramItemReport } from "../helpers/TelegramCompatibility";

/** Response of GetTelegramStudyInfo (backend/admin/features/writePermission/GetTelegramStudyInfo.php). */
interface TelegramStudyInfo {
	configured: boolean
	username: string
	enabled: boolean
	accessKey: string
	inviteLink: string
	boundParticipants: number
}

function qrImage(url: string, size: number): Vnode<any, any> {
	const qr = qrcode(0, "L")
	qr.addData(url)
	qr.make()
	return <img alt="QrCode" src={qr.createDataURL(size)} />
}

function linkView(url: string): Vnode<any, any> {
	return <div class="horizontal vAlignCenter" style="justify-content:center">
		<a href={url} target="_blank" style="word-break:break-all">{url}</a>
		{BtnCopy(() => navigator.clipboard.writeText(url))}
	</div>
}

/**
 * Study-level Telegram panel: switch the study on for the server's bot, choose whether items that need
 * a full web view open in a mini app, share invite links (general and per participant), and see which
 * questionnaire items do not appear natively in the chat.
 */
export class Content extends SectionContent {
	private info: TelegramStudyInfo
	private pid = ""
	private pidLink = ""

	public static preLoad(sectionData: SectionData): Promise<any>[] {
		return [
			Requests.loadJson(`${FILE_ADMIN}?type=GetTelegramStudyInfo&study_id=${sectionData.getStaticInt("id") ?? 0}`),
			sectionData.getStudyPromise()
		]
	}

	constructor(sectionData: SectionData, info: TelegramStudyInfo) {
		super(sectionData)
		this.info = info
	}

	public title(): string {
		return Lang.get("telegram")
	}

	private async loadInfo(study: Study, accessKey: string): Promise<void> {
		try {
			this.info = await this.sectionData.loader.loadJson(
				`${FILE_ADMIN}?type=GetTelegramStudyInfo&study_id=${study.id.get()}`,
				"post",
				`access_key=${encodeURIComponent(accessKey)}`
			)
			this.pidLink = ""
		}
		catch (e) {
			//error is already shown by the loader
		}
		m.redraw()
	}

	private async createParticipantInvite(study: Study): Promise<void> {
		const pid = this.pid.trim()
		if (!pid)
			return
		try {
			const r = await this.sectionData.loader.loadJson(
				`${FILE_ADMIN}?type=CreateTelegramInvite&study_id=${study.id.get()}`,
				"post",
				`access_key=${encodeURIComponent(this.info.accessKey)}&pid=${encodeURIComponent(pid)}`
			)
			this.pidLink = r.link
		}
		catch (e) {
			//error is already shown by the loader
		}
		m.redraw()
	}

	private settingsView(study: Study): Vnode<any, any> {
		const telegram = study.channels.telegram
		return <div>
			<label class="noTitle noDesc">
				<input type="checkbox" {...BindObservable(telegram.enabled)} />
				<span>{Lang.get("enable_telegram")}</span>
				<small>{Lang.get("enable_telegram_info")}</small>
			</label>
			<label class="noTitle noDesc">
				<input type="checkbox" {...BindObservable(telegram.allowHandoff)} disabled={!telegram.enabled.get()} />
				<span>{Lang.get("telegram_allow_handoff")}</span>
				<small>{Lang.get("telegram_allow_handoff_info")}</small>
			</label>
		</div>
	}

	private accessKeySelector(study: Study): Vnode<any, any> | null {
		const keys = study.accessKeys.get().map(key => key.get())
		if (!keys.length && !this.info.accessKey)
			return null
		if (this.info.accessKey && keys.indexOf(this.info.accessKey) == -1)
			keys.unshift(this.info.accessKey)
		return <label>
			<small>{Lang.get("accessKey")}</small>
			<select class="small" onchange={(e: InputEvent) => this.loadInfo(study, (e.target as HTMLSelectElement).value)}>
				{keys.map(key => <option value={key} selected={key == this.info.accessKey}>{key}</option>)}
			</select>
		</label>
	}

	private inviteView(study: Study): Vnode<any, any> {
		const info = this.info
		if (!info.inviteLink)
			return <div class="center highlight">{Lang.get("telegram_not_set_up_on_server")}</div>
		return <div class="center">
			{this.accessKeySelector(study)}
			{!info.accessKey && <div><small>({Lang.get("telegram_open_access")})</small></div>}
			{study.accessKeys.isDifferent() && <div><small class="highlight">{Lang.get("telegram_invite_unsaved_key")}</small></div>}
			{linkView(info.inviteLink)}
			<div class="spacingTop">{qrImage(info.inviteLink, 5)}</div>
		</div>
	}

	private participantInviteView(study: Study): Vnode<any, any> {
		return <div class="center">
			<label>
				<small>{Lang.get("telegram_participant_id")}</small>
				<input type="text" value={this.pid} oninput={(e: InputEvent) => { this.pid = (e.target as HTMLInputElement).value }} />
			</label>
			<input type="button" value={Lang.get("telegram_create_link")} disabled={!this.pid.trim()} onclick={() => this.createParticipantInvite(study)} />
			<div><small>{Lang.get("telegram_participant_invite_info")}</small></div>
			{this.pidLink && <div class="spacingTop">
				{linkView(this.pidLink)}
				<div class="spacingTop">{qrImage(this.pidLink, 4)}</div>
			</div>}
		</div>
	}

	/** What happens to a non-native item, depending on whether the mini app handoff is allowed. */
	private itemNote(item: TelegramItemReport, allowHandoff: boolean): string[] {
		const notes: string[] = []
		if (item.support == "unavailable")
			notes.push(Lang.get("telegram_support_unavailable"))
		else if (item.support == "miniApp") {
			if (allowHandoff)
				notes.push(Lang.get("telegram_support_mini_app"))
			else if (item.cognitive)
				notes.push(Lang.get("telegram_cognitive_skipped"))
			else if (item.responseType == "va_scale")
				notes.push(Lang.get("telegram_va_scale_buttons"))
			else if (item.responseType == "record_keystrokes")
				notes.push(Lang.get("telegram_keystrokes_text"))
		}
		if (item.unsupportedRelevance)
			notes.push(Lang.get("telegram_relevance_ignored"))
		if (item.pageRelevanceIgnored)
			notes.push(Lang.get("telegram_page_relevance_ignored"))
		return notes
	}

	private compatibilityView(study: Study): Vnode<any, any> {
		const report = checkTelegramCompatibility(study)
		const allowHandoff = study.channels.telegram.allowHandoff.get()
		return <div>
			<div class="center spacingBottom">
				{report.items.length
					? Lang.get("telegram_compatibility_summary", report.native, report.total)
					: Lang.get("telegram_compatibility_all_native")}
			</div>
			{report.items.length != 0 &&
				<table class="boxStyle" style="width:100%"><tbody>
					<tr>
						<th>{Lang.get("questionnaire")}</th>
						<th>{Lang.get("variable_name")}</th>
						<th>{Lang.get("type")}</th>
						<th></th>
					</tr>
					{report.items.map(item =>
						<tr>
							<td>{item.questionnaire}</td>
							<td style="font-family:monospace;word-break:break-all">{item.name}</td>
							<td><small>{item.responseType}</small></td>
							<td class={item.support == "unavailable" || (!allowHandoff && item.cognitive) ? "highlight" : ""}>
								{this.itemNote(item, allowHandoff).map(note => <div><small>⚠ {note}</small></div>)}
							</td>
						</tr>
					)}
				</tbody></table>
			}
		</div>
	}

	public getView(): Vnode<any, any> {
		const study = this.getStudyOrThrow()
		const enabled = study.channels.telegram.enabled.get()
		return <div>
			{DashRow(DashElement("stretched", { content: this.settingsView(study) }))}
			{!this.info.configured &&
				<div class="center highlight spacingTop">{Lang.get("telegram_not_set_up_on_server")}</div>}

			{enabled && this.info.configured && <div>
				{DashRow(
					DashElement(null, {
						content: <div class="center">
							<div class="dashTitle">{Lang.get("telegram_bot")}</div>
							<a href={`https://t.me/${this.info.username}`} target="_blank"><b>@{this.info.username}</b></a>
						</div>
					}),
					DashElement(null, {
						content: <div class="center">
							<div class="dashTitle">{Lang.get("telegram_connected_participants")}</div>
							<span style="font-size:1.6em">{this.info.boundParticipants}</span>
						</div>
					})
				)}
				{TitleRow(Lang.getWithColon("telegram_invite_link"))}
				{DashRow(DashElement("stretched", { content: this.inviteView(study) }))}
				{TitleRow(Lang.getWithColon("telegram_participant_invite"))}
				{DashRow(DashElement("stretched", { content: this.participantInviteView(study) }))}
			</div>}

			{TitleRow(Lang.getWithColon("telegram_compatibility"))}
			{DashRow(DashElement("stretched", { content: this.compatibilityView(study) }))}
		</div>
	}
}
