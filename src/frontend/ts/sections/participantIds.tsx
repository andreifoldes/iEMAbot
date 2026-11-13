import { SectionContent } from "../site/SectionContent";
import m, { Vnode } from "mithril";
import { Lang } from "../singletons/Lang";
import { BindObservable } from "../components/BindObservable";
import { TitleRow } from "../components/TitleRow";
import { DashRow } from "../components/DashRow";
import { DashElement } from "../components/DashElement";
import { Study } from "../data/study/Study";
import { SectionData } from "../site/SectionData";
import { Requests } from "../singletons/Requests";
import { FILE_ADMIN } from "../constants/urls";
import { createParticipantUrl } from "../constants/methods";
import { generateIds, inviteLinksCsv, invalidIds, parseIdList, PID_MAX_COUNT } from "../helpers/participantIds";

interface ListedId {
	id: string
	joined: boolean
}
/** What the server answers to GetAllowedPids. */
interface AllowedPidsInfo {
	ids: ListedId[]
	unlistedJoinedCount: number
	unlistedJoined: string[]
}

/** More rows than this are not drawn: the list can hold thousands of ids, and the box above it is the place to edit them. */
const TABLE_ROWS_MAX = 300

/**
 * Who may join the study: anyone with an access key (each participant gets a random id), or only the participant ids the
 * researcher lists. Whether a link's id is used or the participant always types it is set here too. The two settings are part
 * of the study and are saved with it; the list is kept apart from the study (participants download the study) and has its own
 * save button. The server refuses ids that are not on the list (see backend/participants/ParticipantAllowList.php).
 */
export class Content extends SectionContent {
	/** The list as the server holds it. */
	private saved: ListedId[] = []
	private unlistedJoinedCount = 0
	private unlistedJoined: string[] = []
	/** False for a study the server does not know yet: its list can only be saved after the study is. */
	private available: boolean
	/** The list as typed in the box, one id per line. */
	private draft: string = ""
	private generateCount: number = 20
	private linksKeyIndex: number = 0

	public static preLoad(sectionData: SectionData): Promise<any>[] {
		return [
			Requests.loadJson(`${FILE_ADMIN}?type=GetAllowedPids&study_id=${sectionData.getStaticInt("id") ?? 0}`).catch(() => null),
			sectionData.getStudyPromise()
		]
	}

	constructor(sectionData: SectionData, info: AllowedPidsInfo | null) {
		super(sectionData)
		this.available = info !== null
		if(info)
			this.apply(info)
	}

	public title(): string {
		return Lang.get("participant_ids")
	}

	private apply(info: AllowedPidsInfo): void {
		this.saved = info.ids
		this.unlistedJoinedCount = info.unlistedJoinedCount
		this.unlistedJoined = info.unlistedJoined
		this.draft = info.ids.map((entry) => entry.id).join("\n")
	}

	private draftIds(): string[] {
		return parseIdList(this.draft)
	}

	private hasUnsavedList(): boolean {
		const draft = this.draftIds()
		return draft.length != this.saved.length || draft.some((id, index) => id !== this.saved[index].id)
	}

	private async saveList(study: Study): Promise<void> {
		const ids = this.draftIds()
		const invalid = invalidIds(ids)
		if(invalid.length) {
			this.sectionData.loader.error(Lang.get("pid_list_invalid", invalid.slice(0, 5).join(", ")))
			return
		}
		if(ids.length > PID_MAX_COUNT) {
			this.sectionData.loader.error(Lang.get("pid_list_too_many", String(PID_MAX_COUNT)))
			return
		}
		const id = study.id.get()
		await this.sectionData.loader.loadJson(`${FILE_ADMIN}?type=SetAllowedPids&study_id=${id}`, "post", JSON.stringify({ ids }))
		this.apply(await this.sectionData.loader.loadJson(`${FILE_ADMIN}?type=GetAllowedPids&study_id=${id}`))
		this.sectionData.loader.info(Lang.get("info_successful"))
	}

	private addToDraft(ids: string[]): void {
		this.draft = parseIdList(this.draft + "\n" + ids.join("\n")).join("\n")
	}

	private makeRandomIds(): void {
		const count = Math.max(1, Math.min(this.generateCount || 0, PID_MAX_COUNT - this.draftIds().length))
		this.addToDraft(generateIds(count, this.draftIds()))
	}

	private readFile(e: Event): void {
		const input = e.target as HTMLInputElement
		const file = input.files?.[0]
		if(!file)
			return
		const reader = new FileReader()
		reader.onload = () => {
			this.addToDraft(parseIdList(String(reader.result ?? "")))
			input.value = ""
			m.redraw()
		}
		reader.readAsText(file)
	}

	/** The list as a file of personal links (the access key, the study and the id in each), to send out. */
	private downloadLinks(study: Study): void {
		const keys = study.accessKeys.get()
		const key = keys.length ? keys[Math.min(this.linksKeyIndex, keys.length - 1)].get() : ""
		const csv = inviteLinksCsv(
			this.saved.map((entry) => entry.id),
			(id) => `${createParticipantUrl(key, study.id.get())}&pid=${encodeURIComponent(id)}`
		)
		const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }))
		const link = document.createElement("a")
		link.href = url
		link.download = `participant-links-${study.id.get()}.csv`
		link.click()
		URL.revokeObjectURL(url)
	}

	private getModeView(study: Study): Vnode<any, any> {
		const listMode = study.pidMode.get() === "list"
		return <div class="vAlignCenter">
			<label class="noTitle noDesc">
				<input type="radio" name="pidMode" checked={!listMode} onchange={() => study.pidMode.set("open")} />
				<span>{Lang.get("pid_mode_open")}</span>
				<small>{Lang.get("pid_mode_open_info")}</small>
			</label>
			<label class="noTitle noDesc">
				<input type="radio" name="pidMode" checked={listMode} onchange={() => study.pidMode.set("list")} />
				<span>{Lang.get("pid_mode_list")}</span>
				<small>{Lang.get("pid_mode_list_info")}</small>
			</label>
		</div>
	}

	/** One participant ID on several devices at once, or on one only. Works with both ways of joining. */
	private getDevicesView(study: Study): Vnode<any, any> {
		const single = study.pidDevices.get() === "single"
		return <div class="vAlignCenter">
			<label class="noTitle noDesc">
				<input type="radio" name="pidDevices" checked={!single} onchange={() => study.pidDevices.set("multi")} />
				<span>{Lang.get("pid_devices_multi")}</span>
				<small>{Lang.get("pid_devices_multi_info")}</small>
			</label>
			<label class="noTitle noDesc">
				<input type="radio" name="pidDevices" checked={single} onchange={() => study.pidDevices.set("single")} />
				<span>{Lang.get("pid_devices_single")}</span>
				<small>{Lang.get("pid_devices_single_info")}</small>
			</label>
		</div>
	}

	private getListView(study: Study): Vnode<any, any> {
		if(!this.available)
			return <div class="center spacingBottom"><small class="highlight">{Lang.get("pid_save_study_first")}</small></div>

		const draftIds = this.draftIds()
		const invalid = invalidIds(draftIds)
		const joinedCount = this.saved.filter((entry) => entry.joined).length
		const keys = study.accessKeys.get()
		return <div>
			{TitleRow(Lang.getWithColon("pid_list_title"))}
			<div class="center spacingBottom"><small>{Lang.get("pid_list_info")}</small></div>
			{DashRow(
				DashElement("stretched", {
					content:
						<div>
							<textarea
								class="fullWidth"
								rows={10}
								spellcheck={false}
								aria-label={Lang.get("pid_list_input")}
								placeholder={Lang.get("pid_list_input")}
								value={this.draft}
								oninput={(e: Event) => { this.draft = (e.target as HTMLTextAreaElement).value }}
							/>
							<div class="spacingTop">
								<small>{Lang.get("pid_list_count", String(draftIds.length))}</small>
								{this.hasUnsavedList() && <small class="highlight"> · {Lang.get("pid_list_unsaved")}</small>}
							</div>
							{invalid.length != 0 && <div><small class="highlight">{Lang.get("pid_list_invalid", invalid.slice(0, 5).join(", "))}</small></div>}
							{draftIds.length == 0 && <div><small class="highlight">{Lang.get("pid_list_empty_warning")}</small></div>}
							<div class="spacingTop">
								<label class="noTitle noDesc">
									<input type="number" min="1" max="1000" value={this.generateCount}
										aria-label={Lang.get("pid_list_generate_count")}
										oninput={(e: Event) => { this.generateCount = parseInt((e.target as HTMLInputElement).value) || 0 }} />
									<button type="button" onclick={() => this.makeRandomIds()}>{Lang.get("pid_list_generate")}</button>
								</label>
								<label class="noTitle noDesc">
									<span>{Lang.get("pid_list_upload")}</span>
									<input type="file" accept=".txt,.csv,text/plain,text/csv" onchange={(e: Event) => this.readFile(e)} />
								</label>
							</div>
							<div class="spacingTop">
								<button type="button" disabled={!this.hasUnsavedList() || invalid.length != 0} onclick={() => this.saveList(study)}>
									{Lang.get("pid_list_save")}
								</button>
							</div>
							<div class="spacingTop"><small>{Lang.get("pid_ids_secret_note")}</small></div>
						</div>
				})
			)}
			{this.saved.length != 0 && <div>
				{TitleRow(Lang.getWithColon("pid_status_title"))}
				<div class="center spacingBottom"><small>{Lang.get("pid_joined_summary", String(joinedCount), String(this.saved.length))}</small></div>
				{DashRow(
					DashElement("stretched", {
						content:
							<div>
								<table class="studyInfoTable"><tbody>
									{this.saved.slice(0, TABLE_ROWS_MAX).map((entry) => <tr>
										<td>{entry.id}</td>
										<td>{Lang.get(entry.joined ? "pid_status_joined" : "pid_status_not_yet")}</td>
									</tr>)}
								</tbody></table>
								{this.saved.length > TABLE_ROWS_MAX && <small>{Lang.get("pid_table_more", String(this.saved.length - TABLE_ROWS_MAX))}</small>}
							</div>
					})
				)}
				<div class="center spacingTop">
					{keys.length > 1 && <label class="noTitle noDesc">
						<span>{Lang.get("pid_links_key")}</span>
						<select onchange={(e: Event) => { this.linksKeyIndex = (e.target as HTMLSelectElement).selectedIndex }}>
							{keys.map((key, index) => <option selected={index == this.linksKeyIndex}>{key.get()}</option>)}
						</select>
					</label>}
					<button type="button" onclick={() => this.downloadLinks(study)}>{Lang.get("pid_links_download")}</button>
					<div><small>{Lang.get("pid_links_info")}</small></div>
				</div>
			</div>}
			{this.unlistedJoinedCount != 0 && <div class="center spacingTop">
				<small>{Lang.get("pid_unlisted_joined", String(this.unlistedJoinedCount))}</small>
				<br />
				<small>{this.unlistedJoined.join(", ")}{this.unlistedJoinedCount > this.unlistedJoined.length ? ", …" : ""}</small>
			</div>}
		</div>
	}

	public getView(): Vnode<any, any> {
		const study = this.getStudyOrThrow()
		const listMode = study.pidMode.get() === "list"
		return <div>
			<div class="center spacingBottom"><small>{Lang.get("participant_ids_info")}</small></div>
			{DashRow(
				DashElement("vertical", { content: this.getModeView(study) }),
				listMode && DashElement("vertical", {
					content:
						<div class="vAlignCenter">
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(study.pidFromUrl)} />
								<span>{Lang.get("pid_from_url")}</span>
								<small>{Lang.get("pid_from_url_info")}</small>
							</label>
						</div>
				})
			)}
			{TitleRow(Lang.getWithColon("pid_devices_title"))}
			{DashRow(DashElement("vertical", { content: this.getDevicesView(study) }))}
			<div class="center spacingBottom"><small>{Lang.get("pid_settings_saved_with_study")}</small></div>
			{listMode && this.getListView(study)}
		</div>
	}
}
