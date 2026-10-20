import { SectionContent } from "../site/SectionContent";
import m, { Vnode } from "mithril";
import { Lang } from "../singletons/Lang";
import { BindObservable } from "../components/BindObservable";
import { Study } from "../data/study/Study";
import { SHOW_GOOGLE_HEALTH, WEARABLE_PROVIDER_GOOGLE_HEALTH } from "../data/study/EventTrigger";
import { DashRow } from "../components/DashRow";
import { DashElement } from "../components/DashElement";
import { SectionData } from "../site/SectionData";
import { Requests } from "../singletons/Requests";
import { FILE_ADMIN, FILE_WEARABLE_DATA } from "../constants/urls";
import downloadSvg from "../../imgs/icons/download.svg?raw";

interface ParticipantProvider {
	provider: string
	/** UTC day (ms) the sync last covered, null if it has not run for this participant yet */
	lastSync: number | null
	hasData: boolean
	/** The provider rejected this link (revoked, or taken over by another participant ID): the participant must connect again */
	needsReconnect: boolean
}

interface ParticipantOverview {
	userId: string
	providers: ParticipantProvider[]
}

interface WearableInfo {
	allProviders: string[]
	configuredProviders: string[]
	redirectUri: string
	connections: Record<string, number>
	hasData: boolean
	participants: ParticipantOverview[]
}

const PROVIDER_LABEL: Record<string, string> = { fitbit: "Fitbit", withings: "Withings", googlehealth: "Google Health (Fitbit)" }

/**
 * Dedicated study-level admin panel for wearable data sharing: enable it, pick which
 * providers are offered (gated by what the server has credentials for), see how many
 * participants have connected, get the OAuth redirect URI to register, and download data.
 */
export class Content extends SectionContent {
	private readonly info: WearableInfo

	public static preLoad(sectionData: SectionData): Promise<any>[] {
		return [
			Requests.loadJson(`${FILE_ADMIN}?type=GetWearableInfo&study_id=${sectionData.getStaticInt("id") ?? 0}`),
			sectionData.getStudyPromise()
		]
	}

	constructor(sectionData: SectionData, info: WearableInfo) {
		super(sectionData)
		this.info = info ?? { allProviders: [], configuredProviders: [], redirectUri: "", connections: {}, hasData: false, participants: [] }
		this.info.participants = this.info.participants ?? []
	}

	private providerStatus(p: ParticipantProvider): string {
		const label = PROVIDER_LABEL[p.provider] ?? p.provider
		if (p.needsReconnect)
			return `${label} (${Lang.get("wearables_participant_needs_reconnect")})`
		const sync = p.lastSync != null
			? new Date(p.lastSync).toLocaleDateString()
			: Lang.get("wearables_participant_not_synced")
		const data = Lang.get(p.hasData ? "wearables_participant_has_data" : "wearables_participant_no_data")
		return `${label} (${Lang.get("wearables_participant_last_sync")}: ${sync}, ${data})`
	}

	private participantsView(participants: ParticipantOverview[]): Vnode<any, any> {
		const connected = participants.filter((p) => p.providers.some((provider) => !provider.needsReconnect)).length
		return <div class="vertical hAlignStart">
			<label class="noTitle"><span>{Lang.getWithColon("wearables_participants")}</span></label>
			{participants.length == 0
				? <small>{Lang.get("wearables_participants_none")}</small>
				: <div>
					<small>{Lang.get("wearables_participants_summary", connected, participants.length)}</small>
					<table class="spacingTop"><tbody>
						<tr>
							<th>{Lang.get("wearables_participant_id")}</th>
							<th class="spacingLeft">{Lang.get("wearables_participant_status")}</th>
						</tr>
						{participants.map((p) =>
							<tr>
								<td>{p.userId}</td>
								<td class="spacingLeft" style={p.providers.some((provider) => !provider.needsReconnect) ? "" : "opacity:0.6"}>
									{p.providers.length
										? p.providers.map((provider) => <div>{this.providerStatus(provider)}</div>)
										: Lang.get("wearables_participant_not_connected")}
								</td>
							</tr>
						)}
					</tbody></table>
				</div>}
		</div>
	}

	public title(): string {
		return Lang.get("wearables")
	}

	private toggleProvider(study: Study, provider: string, enabled: boolean): void {
		const index = study.wearablesProviders.indexOf(provider)
		if (enabled && index == -1)
			study.wearablesProviders.push(provider)
		else if (!enabled && index != -1)
			study.wearablesProviders.remove(index)
	}

	public getView(): Vnode<any, any> {
		const study = this.getStudyOrThrow()
		const info = this.info
		const enabled = study.wearablesEnabled.get()
		const providers = (info.allProviders.length ? info.allProviders : ["fitbit", "withings", "googlehealth"])
			.filter((p) => SHOW_GOOGLE_HEALTH || p != WEARABLE_PROVIDER_GOOGLE_HEALTH)

		return <div>
			{DashRow(
				DashElement("stretched", {
					content:
						<div class="vAlignCenter">
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(study.wearablesEnabled)} />
								<span>{Lang.get("enable_wearables")}</span>
								<small>{Lang.get("enable_wearables_info")}</small>
							</label>
						</div>
				})
			)}

			{enabled && DashRow(
				DashElement("stretched", {
					content:
						<div class="vertical hAlignStart">
							<label class="noTitle"><span>{Lang.getWithColon("wearables_providers")}</span></label>
							{providers.map((provider) => {
								const configured = info.configuredProviders.includes(provider)
								const count = info.connections[provider] ?? 0
								return <label class="noTitle noDesc" style={configured ? "" : "opacity:0.5"}>
									<input type="checkbox"
										disabled={!configured}
										checked={study.wearablesProviders.indexOf(provider) != -1}
										onchange={(e: InputEvent) => this.toggleProvider(study, provider, (e.target as HTMLInputElement).checked)} />
									<span>{PROVIDER_LABEL[provider] ?? provider}</span>
									<small>{configured
										? Lang.get("wearables_connected_count", count)
										: Lang.get("wearables_provider_not_configured")}</small>
								</label>
							})}
						</div>
				})
			)}

			{enabled && DashRow(
				DashElement("stretched", { content: this.participantsView(info.participants) })
			)}

			{enabled && DashRow(
				DashElement("stretched", {
					content:
						<div class="vertical hAlignStart">
							<label class="noTitle"><span>{Lang.getWithColon("wearables_redirect_uri")}</span>
								<small>{Lang.get("wearables_redirect_uri_info")}</small></label>
							<input type="text" readonly value={info.redirectUri} onclick={(e: Event) => (e.target as HTMLInputElement).select()} />
						</div>
				}),
				DashElement("stretched", {
					content:
						<div class="vertical hAlignStart">
							<label class="noTitle"><span>{Lang.getWithColon("wearable_data_download")}</span></label>
							{info.hasData
								? <a class="spacingRight" href={FILE_WEARABLE_DATA.replace("%1", study.id.get().toString())} download="wearables.zip">
									{m.trust(downloadSvg)}<span class="spacingLeft">wearables.zip</span>
								</a>
								: <small>{Lang.get("wearables_no_data")}</small>}
						</div>
				})
			)}
		</div>
	}
}
