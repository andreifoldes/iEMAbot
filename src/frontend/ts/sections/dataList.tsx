import {SectionContent} from "../site/SectionContent";
import m, {Vnode} from "mithril";
import {Lang} from "../singletons/Lang";
import downloadSvg from "../../imgs/icons/download.svg?raw"
import questionnaireSvg from "../../imgs/icons/questionnaire.svg?raw"
import backupSvg from "../../imgs/icons/backup.svg?raw"
import {FILE_ADMIN, FILE_CREATE_MEDIA, FILE_MEDIA, FILE_RESPONSES, FILE_RESPONSES_AS, FILE_WEARABLE_DATA, FILE_PHONE_SENSING_DATA} from "../constants/urls";
import {Study} from "../data/study/Study";
import {TitleRow} from "../components/TitleRow";
import {Requests} from "../singletons/Requests";
import {safeConfirm} from "../constants/methods";
import {Questionnaire} from "../data/study/Questionnaire";
import {BtnTrash} from "../components/Buttons";
import {SectionData} from "../site/SectionData";

interface DataLineEntry {
	title: string
	fileName: string | number
	previewId?: number
}

export class Content extends SectionContent {
	private is_generating_zip: boolean = false
	private readonly backupEntries: DataLineEntry[]

	public static preLoad(sectionData: SectionData): Promise<any>[] {
		return [
			sectionData.getStudyPromise(),
			Requests.loadJson(`${FILE_ADMIN}?type=ListData&study_id=${sectionData.getStaticInt("id") ?? 0}`),
			sectionData.getAdmin().init()
		]
	}

	constructor(sectionData: SectionData, study: Study, dataEntries: string[]) {
		super(sectionData)

		const questionnaires = study.questionnaires.get()
		const questionnaireIndex: Record<number, Questionnaire> = {};
		for (let i = questionnaires.length - 1; i >= 0; --i) {
			const questionnaire = questionnaires[i]
			questionnaireIndex[questionnaire.internalId.get()] = questionnaire
		}

		this.backupEntries = dataEntries
			.filter((fileName) => !questionnaireIndex.hasOwnProperty(parseInt(fileName)))
			.map((fileName) => {
				const [match, date, internalId] = fileName.match(/^(\d{4}-\d{2}-\d{2})_(\d+)$/) ?? []
				if (match != null) {
					if (questionnaireIndex.hasOwnProperty(parseInt(internalId)))
						return { title: `${date} ${questionnaireIndex[parseInt(internalId)].getTitle()}`, fileName: fileName }
				}

				return { title: fileName, fileName: fileName }
			})
	}

	public title(): string {
		return Lang.get("data_table")
	}

	private async backupStudy(study: Study): Promise<any> {
		if (!confirm(Lang.get("confirm_backup", study.title.get())))
			return

		await this.sectionData.loader.loadJson(`${FILE_ADMIN}?type=BackupStudy`, "post", `study_id=${study.id.get()}`)
		await this.sectionData.callbacks?.reload()
		this.sectionData.loader.info(Lang.get("info_successful"))
	}

	private async emptyData(study: Study): Promise<any> {
		if (!safeConfirm(Lang.get("confirm_delete_data", study.title.get())))
			return;

		await this.sectionData.loader.loadJson(`${FILE_ADMIN}?type=EmptyData`, "post", `study_id=${study.id.get()}`)
		await this.sectionData.callbacks?.reload()

		this.sectionData.loader.info(Lang.get("info_successful"))
	}

	private async deleteBackups(study: Study): Promise<any> {
		if (!safeConfirm(Lang.get("confirm_delete_backups", study.title.get())))
			return

		await this.sectionData.loader.loadJson(`${FILE_ADMIN}?type=DeleteBackups`, "post", `study_id=${study.id.get()}`)
		await this.sectionData.callbacks?.reload()

		this.sectionData.loader.info(Lang.get("info_successful"))
	}

	public getView(): Vnode<any, any> {
		const study = this.getStudyOrThrow()
		return study.version.get() == 0
			? <div></div>
			: <div>
				{this.getDataLineView(
					"general",
					study,
					[
						{ title: Lang.get("events_csv_title"), fileName: "events" },
						{ title: Lang.get("web_access_csv_title"), fileName: "web_access" }
					]
				)}

				{TitleRow(Lang.getWithColon("questionnaires"))}
				{this.getDataLineView(
					"questionnaire",
					study,
					study.questionnaires.get().map((questionnaire) => {
						return { title: questionnaire.getTitle(), fileName: questionnaire.internalId.get(), previewId: questionnaire.internalId.get() }
					})
				)}

				{/* Always offered: recordings, photos and files, and wearable data. A study can hold some even if its settings do not
				    announce them (a voice note from the Telegram bot, wearables connected later), and a researcher should never have to
				    know a hidden switch to get their data. With nothing collected yet the click shows a message. */}
				<div>
					{TitleRow(Lang.getWithColon("media_download"))}
					<a class="spacingRight" onclick={this.waitForDownload.bind(this, study)}>
						{m.trust(downloadSvg)}
						<span id="mediaZipSpan" class="spacingLeft">media.zip</span>
					</a>
				</div>

				<div>
					{TitleRow(Lang.getWithColon("wearable_data_download"))}
					<a class="spacingRight" onclick={this.downloadWearables.bind(this, study)} title={Lang.get("download")}>
						{m.trust(downloadSvg)}
						<span class="spacingLeft">wearables.zip</span>
					</a>
				</div>

				{(study.phoneSensingLight.get() || study.phoneSensingActivity.get() || study.phoneSensingAccelerometer.get() || study.phoneSensingUsage.get() !== "") &&
					<div>
						{TitleRow(Lang.getWithColon("phone_sensing_data"))}
						<a class="spacingRight" href={FILE_PHONE_SENSING_DATA.replace('%1', study.id.get().toString())} download="phone_sensing.zip" title={Lang.get("download")}>
							{m.trust(downloadSvg)}
							<span class="spacingLeft">phone_sensing.zip</span>
						</a>
					</div>
				}

				{TitleRow(Lang.getWithColon("backups"))}
				<div class="verticalPadding">
					<a class="horizontal" onclick={this.backupStudy.bind(this, study)}>
						{m.trust(backupSvg)}
						<span>{Lang.get("create_backup")}</span>
					</a>
				</div>
				<br />
				{this.getDataLineView(
					"backup",
					study,
					this.backupEntries
				)}

				<br />
				{this.hasPermission("write", study.id.get()) &&
					<div>
						<div class="verticalPadding highlight">
							{BtnTrash(this.emptyData.bind(this, study), Lang.get("empty_data"))}
						</div>
						{this.backupEntries.length > 0 &&
							<div class="verticalPadding highlight">
								{BtnTrash(this.deleteBackups.bind(this, study), Lang.get("empty_backups"))}
							</div>
						}
					</div>

				}
			</div>
	}

	private getDataLineView(sectionValue: string, study: Study, list: DataLineEntry[]): Vnode<any, any>[] {
		return list.map((entry) =>
			<div>
				<div>
					{this.downloadLinks(study, entry)}
					{entry.previewId &&
						<span>
							<a class="spacingRight" href={this.getUrl(`demo:static,qId:${entry.previewId}`)}>
								{m.trust(questionnaireSvg)}
							</a>
						</span>
					}

					<a href={this.getUrl(`dataView:${sectionValue},${typeof entry.fileName == "number" ? "qId" : "file"}:${entry.fileName}`)}>{entry.title}</a>
				</div>
			</div>
		)
	}

	/**
	 * Questionnaires, events and web access can be downloaded as CSV or Excel, built from the database (the server answers with an
	 * error message instead of a partial file if it cannot). Dated backup copies are files and stay a plain download.
	 */
	private downloadLinks(study: Study, entry: DataLineEntry): Vnode<any, any> {
		const studyId = study.id.get().toString()
		const fileName = entry.fileName.toString()
		const fromDatabase = typeof entry.fileName == "number" || fileName == "events" || fileName == "web_access"
		if(!fromDatabase) {
			return <a class="spacingRight" href={FILE_RESPONSES.replace('%1', studyId).replace('%2', fileName)} download={entry.title} title={Lang.get("download")}>
				{m.trust(downloadSvg)}
			</a>
		}
		const link = (format: string) => FILE_RESPONSES_AS.replace('%1', studyId).replace('%2', fileName).replace('%3', format)
		return <span>
			<a class="spacingRight" href={link("csv")} target="_blank" rel="noopener" title={Lang.get("download_as_csv")}>
				{m.trust(downloadSvg)}
			</a>
			<a class="spacingRight smallText" href={link("xlsx")} target="_blank" rel="noopener" title={Lang.get("download_as_xlsx")}>XLSX</a>
		</span>
	}

	private async waitForDownload(study: Study) {
		if (this.is_generating_zip) {
			return
		}
		this.is_generating_zip = true
		
		try {
			await this.sectionData.loader.loadWithSSE(
				`${FILE_CREATE_MEDIA.replace("%1", study.id.get().toString())}`,
				percent => "media.zip (%1 ... %2\%)".replace("%1", Lang.get("generating")).replace("%2", percent.toString())
			)
		}
		catch(e) {
			// The loader has shown the server's message (for example "no files yet"). Allow another try later.
			return
		}
		finally {
			this.is_generating_zip = false
		}
		let element = document.createElement('a')
		element.setAttribute('href', `${FILE_MEDIA.replace("%1", study.id.get().toString())}`)
		element.setAttribute('download', 'media.zip')
		document.body.appendChild(element)
		element.click();
		document.body.removeChild(element)
	}
	
	/**
	 * The wearable zip is built on request. When the study has no wearable data the server answers with an error message instead of a
	 * file, so the answer is checked before it is saved: a plain download link would save that message as "wearables.zip".
	 */
	private async downloadWearables(study: Study) {
		const url = FILE_WEARABLE_DATA.replace('%1', study.id.get().toString())
		try {
			const response = await fetch(url, { credentials: 'same-origin' })
			const type = response.headers.get('Content-Type') ?? ''
			if(!response.ok || type.includes('json') || type.includes('text')) {
				let message = Lang.get("error_unknown")
				try {
					const body = await response.json()
					message = body.error ?? body.msg ?? message
				}
				catch(e) { /* keep the generic message */ }
				this.sectionData.loader.error(message)
				return
			}
			const blobUrl = URL.createObjectURL(await response.blob())
			const element = document.createElement('a')
			element.setAttribute('href', blobUrl)
			element.setAttribute('download', 'wearables.zip')
			document.body.appendChild(element)
			element.click()
			document.body.removeChild(element)
			setTimeout(() => URL.revokeObjectURL(blobUrl), 10000)
		}
		catch(e) {
			this.sectionData.loader.error(Lang.get("error_unknown"))
		}
	}
}