import {SectionContent} from "../site/SectionContent";
import m, {Vnode} from "mithril";
import {Lang} from "../singletons/Lang";
import {TitleRow} from "../components/TitleRow";
import {DashRow} from "../components/DashRow";
import {DashElement} from "../components/DashElement";
import {FILE_RESPONSES} from "../constants/urls";
import {CsvLoader} from "../loader/csv/CsvLoader";
import {ChartData} from "../data/study/ChartData";
import {CsvLoaderCollectionFromCharts, LoadedStatistics} from "../loader/csv/CsvLoaderCollectionFromCharts";
import {ObservablePromise} from "../observable/ObservablePromise";
import {ChartView} from "../components/ChartView";
import {SearchBox} from "../components/SearchBox";
import {ValueListInfo} from "../loader/csv/ValueListInfo";
import {StatisticsCollection} from "../data/statistics/StatisticsCollection";
import {Study} from "../data/study/Study";
import {AxisContainer} from "../data/study/AxisContainer";
import {BtnReload} from "../components/Buttons";
import {SectionData} from "../site/SectionData";

interface ParticipantSummary {
	userId: string
	isActive: boolean
	studyStart: number | null
	studyEnd: number | null
	responseCounts: Record<string, number>
}

export class Content extends SectionContent {
	private readonly csvLoader: CsvLoader
	private readonly personalStatisticsCsvLoaderCollection: CsvLoaderCollectionFromCharts
	private readonly enableGroupStatistics: boolean
	private publicStatistics?: StatisticsCollection

	private participantList: { name: string, count: number }[] = []
	private groupList: ValueListInfo[] = []
	private timezoneList: ValueListInfo[] = []
	private appTypeList: ValueListInfo[] = []
	private modelList: ValueListInfo[] = []
	private joinedTimeList: ValueListInfo[] = []
	private quitTimeList: ValueListInfo[] = []
	private currentParticipant = ""
	private isLoading: boolean = false

	private summaryList: ParticipantSummary[] = []
	private surveyNames: string[] = []

	private readonly joinedPerDayChart: ChartData

	private readonly joinedPerDayPromise: ObservablePromise<LoadedStatistics>
	private readonly personalChartPromises: ObservablePromise<LoadedStatistics>[]

	public static preLoad(sectionData: SectionData): Promise<any>[] {
		const url = FILE_RESPONSES.replace('%1', (sectionData.getStaticInt("id") ?? 0).toString()).replace('%2', 'events');
		return [
			CsvLoader.fromUrl(sectionData.loader, url),
			sectionData.getStudyPromise()
		]
	}

	constructor(sectionData: SectionData, csvLoader: CsvLoader, study: Study) {
		super(sectionData)
		this.csvLoader = csvLoader
		this.personalStatisticsCsvLoaderCollection = new CsvLoaderCollectionFromCharts(sectionData.loader, this.getStudyOrThrow())

		this.enableGroupStatistics = csvLoader.hasColumn("group")

		const tempPromise = Promise.resolve({mainStatistics: {}})
		this.joinedPerDayChart = ChartData.createPerDayChartData(Lang.get("questionnaires"))
		this.joinedPerDayPromise = new ObservablePromise<LoadedStatistics>(tempPromise, null, "questionnairePerDayPromise")

		this.personalChartPromises = study.personalStatistics.charts.get().map(
			(_chart, index) => new ObservablePromise<LoadedStatistics>(tempPromise, null, `personalChart${index}`)
		)
	}

	public async preInit(): Promise<void> {
		await Promise.all([
			this.loadSummary(),
			this.personalStatisticsCsvLoaderCollection.setupLoadersForCharts(this.getStudyOrThrow().personalStatistics.charts.get())
		])
		await this.loadParticipants()
		const userId = this.getStaticString("userId")
		if(userId)
			await this.selectParticipant(atob(userId))

		window.setTimeout(() => {
			const line = document.getElementsByClassName("currentParticipant")
			if(line[0])
				line[0].scrollIntoView({behavior: "smooth", block: "nearest"})
		}, 500)
	}

	public title(): string {
		return Lang.get("participants")
	}
	public titleExtra(): Vnode<any, any> | null {
		return BtnReload(this.sectionData.callbacks?.reload.bind(this.sectionData), Lang.get("reload"))
	}

	private async loadSummary(): Promise<void> {
		const studyId = (this.sectionData.getStaticInt("id") ?? 0).toString()
		const url = FILE_RESPONSES.replace('%1', studyId).replace('%2', 'events')
		let response: Response
		try {
			response = await fetch(location.origin + location.pathname + url)
			if(!response.ok) return
		}
		catch(_) {
			return
		}
		const text = await response.text()
		const lines = text.split('\n').filter(l => l.trim())
		if(lines.length < 2) return

		const headers = lines[0].split(';').map(h => h.replace(/^"|"$/g, ''))
		const userIdIdx = headers.indexOf('userId')
		const eventTypeIdx = headers.indexOf('eventType')
		const responseTimeIdx = headers.indexOf('responseTime')
		const questionnaireNameIdx = headers.indexOf('questionnaireName')
		if(userIdIdx < 0 || eventTypeIdx < 0) return

		const byUser: Record<string, {joined: number|null, quit: number|null, counts: Record<string, number>}> = {}
		const surveyNameSet = new Set<string>()

		for(let i = 1; i < lines.length; i++) {
			const cells = lines[i].split(';').map(c => c.replace(/^"|"$/g, ''))
			const uid = cells[userIdIdx]
			const etype = cells[eventTypeIdx]
			if(!uid || !etype) continue
			if(!byUser[uid]) byUser[uid] = {joined: null, quit: null, counts: {}}
			const rtime = responseTimeIdx >= 0 ? parseInt(cells[responseTimeIdx]) : NaN
			if(etype === 'joined' && !isNaN(rtime) && (!byUser[uid].joined || rtime < byUser[uid].joined!))
				byUser[uid].joined = rtime
			else if(etype === 'quit' && !isNaN(rtime))
				byUser[uid].quit = rtime
			else if(etype === 'questionnaire' && questionnaireNameIdx >= 0) {
				const qname = cells[questionnaireNameIdx]
				if(qname) {
					byUser[uid].counts[qname] = (byUser[uid].counts[qname] ?? 0) + 1
					surveyNameSet.add(qname)
				}
			}
		}

		this.surveyNames = Array.from(surveyNameSet).sort()
		this.summaryList = Object.entries(byUser).map(([uid, d]) => ({
			userId: uid,
			isActive: !!d.joined && !d.quit,
			studyStart: d.joined,
			studyEnd: d.quit,
			responseCounts: d.counts
		})).sort((a, b) => {
			const ta = Object.values(a.responseCounts).reduce((s, v) => s + v, 0)
			const tb = Object.values(b.responseCounts).reduce((s, v) => s + v, 0)
			return tb - ta
		})
	}

	private async loadParticipants(): Promise<void> {
		await this.csvLoader.filterEntireColumn(false, "eventType")
		await this.csvLoader.filterByValue(true, "eventType", "questionnaire")
		const fullList = await this.csvLoader.getValueCellList("userId")

		for(const value in fullList) {
			this.participantList.push({
				name: value,
				count: await this.csvLoader.getVisibleCount("userId", value)
			})
		}
		this.participantList.sort((a, b) => {
			if(a.count == b.count) {
				if(a.name < b.name)
					return -1
				else if(a.name > b.name)
					return 1
				else
					return 0
			}
			if(a.count < b.count)
				return 1
			else
				return -1
		})
	}

	private async selectParticipant(userId: string): Promise<void> {
		this.isLoading = true
		await this.csvLoader.reset()

		await this.csvLoader.filterEntireColumn(false, "userId")
		await this.csvLoader.filterByValue(true, "userId", userId)

		this.timezoneList = await this.csvLoader.getValueListInfo("timezone")
		this.appTypeList = await this.csvLoader.getValueListInfo("appType")
		this.modelList = await this.csvLoader.getValueListInfo("model")
		if(this.enableGroupStatistics)
			this.groupList = await this.csvLoader.getValueListInfo("group")

		await this.csvLoader.filterEntireColumn(false, "eventType")
		await this.csvLoader.filterByValue(true, "eventType", "questionnaire")

		this.joinedPerDayChart.axisContainer.replace(await AxisContainer.getPerDayAxisCodeFromValueList(this.csvLoader, "questionnaireName"))
		this.joinedPerDayPromise.setValue(await this.csvLoader.getPersonalStatisticsFromChart(this.joinedPerDayChart))


		await this.csvLoader.filterByValue(false, "eventType", "questionnaire")
		await this.csvLoader.filterByValue(true, "eventType", "joined")
		this.joinedTimeList = await this.csvLoader.getValueListInfo("responseTime")
		await this.csvLoader.filterByValue(false, "eventType", "joined")

		await this.csvLoader.filterByValue(true, "eventType", "quit")
		this.quitTimeList = await this.csvLoader.getValueListInfo("responseTime")

		this.sectionData.loader.update(Lang.get("state_loading_file", Lang.get("statistics")))


		const loadedStatisticsData = await this.personalStatisticsCsvLoaderCollection.loadStatisticsFromFiles(userId, !!this.publicStatistics)


		//we only load public statistics when loading it the first time. For all the other times, we reuse the cached version:
		if(loadedStatisticsData.additionalStatistics)
			this.publicStatistics = loadedStatisticsData.additionalStatistics
		const statisticsData = {
			mainStatistics: loadedStatisticsData.mainStatistics,
			additionalStatistics: this.publicStatistics
		}

		this.personalChartPromises.forEach((promise) => {
			promise.set(Promise.resolve(statisticsData))
		})

		this.currentParticipant = userId
		this.isLoading = false

		m.redraw()
	}

	private selectParticipantFromSummary(userId: string): void {
		this.selectParticipant(userId).then(() => {
			window.setTimeout(() => {
				document.getElementById('participant-drilldown')?.scrollIntoView({behavior: 'smooth', block: 'start'})
			}, 100)
		})
	}

	private summaryView(): Vnode<any, any> {
		if(!this.summaryList.length) return <div></div>
		const fmt = (ts: number | null) => ts ? new Date(ts).toLocaleDateString() : '-'
		return <div>
			{TitleRow(Lang.get("participants_overview"))}
			<div style="overflow-x:auto;margin-bottom:1em">
				<table class="boxStyle" style="width:100%;border-collapse:collapse">
					<tbody>
						<tr>
							<th style="text-align:left;padding:4px 8px">{Lang.get("participant")}</th>
							<th style="padding:4px 8px">{Lang.get("status")}</th>
							<th style="padding:4px 8px">{Lang.get("joined_study")}</th>
							<th style="padding:4px 8px">{Lang.get("quit_study")}</th>
							{this.surveyNames.map(name =>
								<th title={name} style="padding:4px 8px;max-width:120px;overflow:hidden;text-overflow:ellipsis">{name}</th>
							)}
							<th style="padding:4px 8px">{Lang.get("total")}</th>
						</tr>
						{this.summaryList.map(p => {
							const total = Object.values(p.responseCounts).reduce((s, v) => s + v, 0)
							const isSelected = this.currentParticipant === p.userId
							return <tr
								class={`clickable${isSelected ? ' currentParticipant' : ''}`}
								onclick={this.selectParticipantFromSummary.bind(this, p.userId)}
								style={isSelected ? 'background:#2DBFF3;color:white' : ''}
							>
								<td style="font-family:monospace;padding:4px 8px;word-break:break-all">{p.userId}</td>
								<td class="center" style={`padding:4px 8px;color:${isSelected ? 'inherit' : (p.isActive ? '#2e7d32' : '#888')}`}>
									{p.isActive ? Lang.get("active") : (p.studyEnd ? Lang.get("ended") : Lang.get("ongoing"))}
								</td>
								<td class="center" style="padding:4px 8px;white-space:nowrap">{fmt(p.studyStart)}</td>
								<td class="center" style="padding:4px 8px;white-space:nowrap">{fmt(p.studyEnd)}</td>
								{this.surveyNames.map(name =>
									<td class="center" style="padding:4px 8px">{p.responseCounts[name] ?? 0}</td>
								)}
								<td class="center" style="padding:4px 8px"><b>{total}</b></td>
							</tr>
						})}
					</tbody>
				</table>
			</div>
		</div>
	}

	public getView(): Vnode<any, any> {
		const study = this.getStudyOrThrow()
		const participantList = DashElement(null, {
			content: SearchBox(Lang.get("participants_with_count", this.participantList.length), this.participantList.map((valueListInfo) => {
				return {
					key: valueListInfo.name,
					view:
						<div
							class={`clickable verticalPadding searchTarget smallText ${this.currentParticipant == valueListInfo.name ? "highlight currentParticipant" : ""}`}
							onclick={this.selectParticipant.bind(this, valueListInfo.name)}
						>{Lang.get("text_with_questionnaireCount", valueListInfo.name, valueListInfo.count)}</div>
				}
			}))
		})

		return <div>
			{this.summaryView()}
			<div id="participant-drilldown">
				{this.currentParticipant
					? <div class={this.isLoading ? "fadeOut" : "fadeIn"}>
						{DashRow(
							participantList,
							DashElement("vertical",
								this.enableGroupStatistics && {content: this.getListEntryView(Lang.getWithColon("group"), this.groupList)},
								{content: this.getListEntryView(Lang.getWithColon("timezone"), this.timezoneList)},
								{content: this.getListEntryView(Lang.getWithColon("app_type"), this.appTypeList)},
								{content: this.getListEntryView(Lang.getWithColon("model"), this.modelList)}
							),
							DashElement(null, {content: this.getListEntryView(Lang.getWithColon("joined_study"), this.joinedTimeList)}),
							DashElement(null, {content: this.getListEntryView(Lang.getWithColon("quit_study"), this.quitTimeList)}),
							DashElement("stretched", {
								content: ChartView(this.joinedPerDayChart, this.joinedPerDayPromise)
							})
						)}

						{TitleRow(Lang.getWithColon("personal_charts_for_x", this.currentParticipant))}
						{
							study.personalStatistics.charts.get().map((chartData, index) => {
								return ChartView(chartData, this.personalChartPromises[index])
							})
						}
					</div>
					: DashRow(participantList)
				}
			</div>
		</div>
	}

	private getListEntryView(header: string, list: ValueListInfo[]): Vnode<any, any> {
		return <div>
			<h2 class="spacingLeft">{header}</h2>
			<div class="horizontalPadding center">
				{list.map((info) =>
					<div class="verticalPadding">{info.name}</div>)
				}
			</div>
		</div>
	}
}
