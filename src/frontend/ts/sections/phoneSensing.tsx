import { SectionContent } from "../site/SectionContent";
import m, { Vnode } from "mithril";
import { Lang } from "../singletons/Lang";
import { BindObservable, ConstrainedNumberTransformer } from "../components/BindObservable";
import { DashRow } from "../components/DashRow";
import { DashElement } from "../components/DashElement";
import { SectionData } from "../site/SectionData";
import { Requests } from "../singletons/Requests";
import { FILE_ADMIN, FILE_PHONE_SENSING_DATA } from "../constants/urls";
import downloadSvg from "../../imgs/icons/download.svg?raw";
import {
	estimateAccelerometer, formatBytes, formatCount, scheduledPromptsPerDay, studyDays, DB_BYTES_PER_SAMPLE
} from "../helpers/AccelerometerEstimate";
import { Study } from "../data/study/Study";

interface StreamInfo {
	participants: number
	/** Newest upload of this stream (ms), null when nothing has arrived */
	lastUpload: number | null
}

interface PhoneSensingInfo {
	streams: Record<string, StreamInfo>
}

/** Same order and names as backend/PhoneSensingStore.php::STREAMS */
const STREAMS = ["prompts", "activity", "accelerometer", "usage_daily", "screen_daily", "usage_events"]

/**
 * Study-level admin panel for phone sensing in the native Android app: ambient light at each prompt,
 * activity recognition, raw accelerometer windows around prompts and app usage (screen time), plus how
 * many participants have sent each kind of data and the download.
 */
/** Expected total above which the accelerometer estimate is highlighted. */
const LARGE_TOTAL_BYTES = 10e9

export class Content extends SectionContent {
	private readonly info: PhoneSensingInfo
	// Only for the accelerometer estimate, not saved with the study. null = derived from the study.
	private estimateParticipants = 100
	private estimateDays: number | null = null
	private estimatePromptsPerDay: number | null = null

	public static preLoad(sectionData: SectionData): Promise<any>[] {
		return [
			Requests.loadJson(`${FILE_ADMIN}?type=GetPhoneSensingInfo&study_id=${sectionData.getStaticInt("id") ?? 0}`),
			sectionData.getStudyPromise()
		]
	}

	constructor(sectionData: SectionData, info: PhoneSensingInfo) {
		super(sectionData)
		this.info = info ?? { streams: {} }
		this.info.streams = this.info.streams ?? {}
	}

	public title(): string {
		return Lang.get("phone_sensing")
	}

	private checkbox(obs: Parameters<typeof BindObservable>[0], title: string, info: string): Vnode<any, any> {
		return <label class="noTitle noDesc">
			<input type="checkbox" {...BindObservable(obs)} />
			<span>{title}</span>
			<small>{info}</small>
		</label>
	}

	private streamsView(): Vnode<any, any> {
		const streams = this.info.streams
		const hasData = STREAMS.some((s) => (streams[s]?.participants ?? 0) > 0)
		const studyId = this.getStudyOrThrow().id.get().toString()
		return <div class="vertical hAlignStart">
			<label class="noTitle"><span>{Lang.getWithColon("phone_sensing_data")}</span></label>
			{hasData
				? <div>
					<table><tbody>
						<tr>
							<th>{Lang.get("phone_sensing_stream")}</th>
							<th class="spacingLeft">{Lang.get("wearables_participants")}</th>
							<th class="spacingLeft">{Lang.get("phone_sensing_last_upload")}</th>
						</tr>
						{STREAMS.map((s) => {
							const info = streams[s]
							return <tr>
								<td>{s}.csv</td>
								<td class="spacingLeft">{info?.participants ?? 0}</td>
								<td class="spacingLeft">{info?.lastUpload ? new Date(info.lastUpload).toLocaleString() : "-"}</td>
							</tr>
						})}
					</tbody></table>
					<a class="spacingTop" href={FILE_PHONE_SENSING_DATA.replace("%1", studyId)} download="phone_sensing.zip">
						{m.trust(downloadSvg)}<span class="spacingLeft">phone_sensing.zip</span>
					</a>
				</div>
				: <small>{Lang.get("phone_sensing_no_data")}</small>}
		</div>
	}

	private numberField(label: string, value: number, set: (v: number) => void, min: number): Vnode<any, any> {
		return <label class="spacingRight">
			<small>{label}</small>
			<input type="number" min={min} value={value}
				oninput={(e: InputEvent) => set(Math.max(min, parseInt((e.target as HTMLInputElement).value) || min))} />
		</label>
	}

	/** Warning with an estimate of how much raw accelerometer data the settings produce. */
	private accelerometerEstimateView(study: Study): Vnode<any, any> {
		const scheduled = scheduledPromptsPerDay(study)
		const promptsPerDay = this.estimatePromptsPerDay ?? Math.max(1, scheduled)
		const days = this.estimateDays ?? (studyDays(study) || 30)
		const e = estimateAccelerometer({
			beforeMin: study.phoneSensingAccelBeforeMin.get(),
			afterMin: study.phoneSensingAccelAfterMin.get(),
			hz: study.phoneSensingAccelHz.get(),
			promptsPerDay: promptsPerDay,
			participants: this.estimateParticipants,
			days: days,
		})
		return <div class="vertical hAlignStart">
			<label class="noTitle"><span>{Lang.getWithColon("phone_sensing_estimate")}</span>
				<small>{Lang.get("phone_sensing_estimate_info", DB_BYTES_PER_SAMPLE)}</small></label>
			<div class="horizontal hAlignStart">
				{this.numberField(Lang.get("phone_sensing_estimate_prompts", scheduled), promptsPerDay, (v) => this.estimatePromptsPerDay = v, 1)}
				{this.numberField(Lang.get("phone_sensing_estimate_participants"), this.estimateParticipants, (v) => this.estimateParticipants = v, 1)}
				{this.numberField(Lang.get("phone_sensing_estimate_days"), days, (v) => this.estimateDays = v, 1)}
			</div>
			<ul class="spacingTop">
				<li>{Lang.get("phone_sensing_estimate_per_prompt", formatCount(e.samplesPerPrompt), formatBytes(e.dbBytesPerPrompt))}</li>
				<li>{Lang.get("phone_sensing_estimate_per_day", formatBytes(e.dbBytesPerParticipantDay), formatBytes(e.uploadBytesPerParticipantDay))}</li>
				<li><b>{Lang.get("phone_sensing_estimate_total", formatCount(e.totalSamples), formatBytes(e.totalDbBytes))}</b></li>
			</ul>
			{e.totalDbBytes >= LARGE_TOTAL_BYTES && <small><b>{Lang.get("phone_sensing_estimate_large")}</b></small>}
		</div>
	}

	/** When the app may upload: any connection, Wi-Fi with a mobile-data fallback, or Wi-Fi only. */
	private uploadNetworkView(study: Study): Vnode<any, any> {
		const network = study.phoneSensingUploadNetwork.get()
		const participantChoice = network !== "wifi_only" && study.phoneSensingParticipantNetwork.get()
		return <div class="vertical hAlignStart">
			<label class="noTitle">
				<span>{Lang.getWithColon("phone_sensing_upload")}</span>
				<small>{Lang.get("phone_sensing_upload_info")}</small>
			</label>
			<select {...BindObservable(study.phoneSensingUploadNetwork)}>
				<option value="any">{Lang.get("phone_sensing_upload_any")}</option>
				<option value="wifi_preferred">{Lang.get("phone_sensing_upload_wifi_preferred")}</option>
				<option value="wifi_only">{Lang.get("phone_sensing_upload_wifi_only")}</option>
			</select>
			{(network === "wifi_preferred" || participantChoice) &&
				<label class="spacingTop">
					<small>{Lang.get("phone_sensing_upload_fallback_hours")}</small>
					<input type="number" min="1" max="168" {...BindObservable(study.phoneSensingWifiFallbackHours, new ConstrainedNumberTransformer(1, 168))} />
				</label>
			}
			{network === "wifi_only" && <small class="spacingTop"><b>{Lang.get("phone_sensing_upload_wifi_only_warning")}</b></small>}
			{network !== "wifi_only" &&
				<div class="spacingTop">
					{this.checkbox(study.phoneSensingParticipantNetwork, Lang.get("phone_sensing_participant_network"), Lang.get("phone_sensing_participant_network_info"))}
				</div>
			}
		</div>
	}

	public getView(): Vnode<any, any> {
		const study = this.getStudyOrThrow()
		const minutes = new ConstrainedNumberTransformer(0, 60)

		return <div>
			{DashRow(
				DashElement("stretched", {
					content: <div class="vertical hAlignStart">
						<small>{Lang.get("phone_sensing_info")}</small>
					</div>
				})
			)}

			{DashRow(
				DashElement(null, {
					content: <div class="vertical hAlignStart">
						{this.checkbox(study.phoneSensingLight, Lang.get("phone_sensing_light"), Lang.get("phone_sensing_light_info"))}
					</div>
				}),
				DashElement(null, {
					content: <div class="vertical hAlignStart">
						{this.checkbox(study.phoneSensingActivity, Lang.get("phone_sensing_activity"), Lang.get("phone_sensing_activity_info"))}
					</div>
				})
			)}

			{DashRow(
				DashElement("stretched", {
					content: <div class="vertical hAlignStart">
						{this.checkbox(study.phoneSensingAccelerometer, Lang.get("phone_sensing_accelerometer"), Lang.get("phone_sensing_accelerometer_info"))}
						{study.phoneSensingAccelerometer.get() &&
							<div class="horizontal hAlignStart spacingTop">
								<label class="spacingRight">
									<small>{Lang.get("phone_sensing_accel_before")}</small>
									<input type="number" min="0" max="60" {...BindObservable(study.phoneSensingAccelBeforeMin, minutes)} />
								</label>
								<label class="spacingRight">
									<small>{Lang.get("phone_sensing_accel_after")}</small>
									<input type="number" min="0" max="60" {...BindObservable(study.phoneSensingAccelAfterMin, minutes)} />
								</label>
								<label>
									<small>{Lang.get("phone_sensing_accel_hz")}</small>
									<input type="number" min="1" max="100" {...BindObservable(study.phoneSensingAccelHz, new ConstrainedNumberTransformer(1, 100))} />
								</label>
							</div>
						}
					</div>
				})
			)}

			{study.phoneSensingAccelerometer.get() && DashRow(
				DashElement("stretched", { highlight: true, content: this.accelerometerEstimateView(study) })
			)}

			{DashRow(
				DashElement("stretched", {
					content: <div class="vertical hAlignStart">
						<label class="noTitle">
							<span>{Lang.getWithColon("phone_sensing_usage")}</span>
							<small>{Lang.get("phone_sensing_usage_info")}</small>
						</label>
						<select {...BindObservable(study.phoneSensingUsage)}>
							<option value="">{Lang.get("phone_sensing_usage_off")}</option>
							<option value="daily">{Lang.get("phone_sensing_usage_daily")}</option>
							<option value="events">{Lang.get("phone_sensing_usage_events")}</option>
							<option value="both">{Lang.get("phone_sensing_usage_both")}</option>
						</select>
					</div>
				})
			)}

			{DashRow(
				DashElement("stretched", { content: this.uploadNetworkView(study) })
			)}

			{DashRow(
				DashElement("stretched", { content: this.streamsView() })
			)}
		</div>
	}
}
