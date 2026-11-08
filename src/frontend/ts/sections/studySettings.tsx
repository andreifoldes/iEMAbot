import { SectionContent } from "../site/SectionContent";
import m, { Vnode } from "mithril";
import { Lang } from "../singletons/Lang";
import { BindObservable, ConstrainedNumberTransformer } from "../components/BindObservable";
import { TitleRow } from "../components/TitleRow";
import { Study } from "../data/study/Study";
import { safeConfirm } from "../constants/methods";
import { FILE_ADMIN } from "../constants/urls";
import { Requests } from "../singletons/Requests";
import { BtnTrash } from "../components/Buttons";
import { DashRow } from "../components/DashRow";
import { DashElement } from "../components/DashElement";
import { StudyMetadata } from "../loader/StudyLoader";
import { SectionData } from "../site/SectionData";
import { ServerInfo } from "../constants/ServerInfo";
import { WEB_LANGUAGES, webLanguageLabel } from "../data/webLanguages";

export class Content extends SectionContent {
	private isFrozen: boolean = false
	private hasFallbackUrls: boolean

	public static preLoad(sectionData: SectionData): Promise<any>[] {
		return [
			Requests.loadJson(`${FILE_ADMIN}?type=IsFrozen&study_id=${sectionData.getStaticInt("id")}`),
			Requests.loadJson(`${FILE_ADMIN}?type=GetOutboundFallbackUrls&study_id=${sectionData.getStaticInt("id") ?? 0}`).catch(() => { return [] }),
			sectionData.getStudyPromise()
		]
	}

	constructor(sectionData: SectionData, [frozen]: boolean[], fallbackUrls: string[]) {
		super(sectionData);
		this.isFrozen = frozen
		this.hasFallbackUrls = fallbackUrls.length != 0
	}

	public title(): string {
		return Lang.get("study_settings")
	}

	private async toggleFreezeStudy(study: Study, e: InputEvent): Promise<void> {
		const sendFrozen = (e.target as HTMLInputElement).checked
		const [loadFrozen]: boolean[] = await this.sectionData.loader.loadJson(`${FILE_ADMIN}?type=FreezeStudy${sendFrozen ? "&frozen" : ""}&study_id=${study.id.get()}`)
		this.isFrozen = loadFrozen
		this.sectionData.loader.info(this.isFrozen ? Lang.get("info_study_frozen") : Lang.get("info_study_unfrozen"))
	}

	private async resetStudy(study: Study): Promise<void> {
		if (!safeConfirm(Lang.get("confirm_reset_study", study.title.get())))
			return

		await this.sectionData.loader.loadJson(`${FILE_ADMIN}?type=EmptyData`, "post", `study_id=${study.id.get()}`)
		this.sectionData.loader.info(Lang.get("info_successful"))
	}

	private async deleteStudy(study: Study): Promise<void> {
		if (!safeConfirm(Lang.get("confirm_delete_study", study.title.get())))
			return

		await this.sectionData.loader.showLoader(this.sectionData.siteData.studyLoader.deleteStudy(study))

		this.goTo("admin/allStudies:edit")
	}

	/**
	 * Device permissions the participant web app asks for, worked out from the study's items,
	 * with the switch that controls each. The PWA explains each one during onboarding before
	 * the browser's prompt (see web-pwa/src/lib/permissions.ts).
	 */
	private getPermissionsView(study: Study): Vnode<any, any> {
		const scheduled = study.getScheduledQuestionnaires().map((questionnaire) => questionnaire.getTitle())
		const audioItems = study.getAudioInputLabels()
		const notificationsAsked = study.webPushEnabled.get()
		const microphoneAsked = audioItems.length != 0 && study.webMicOnboarding.get()
		const askedBadge = (asked: boolean) =>
			<small class={asked ? "" : "highlight"}>{Lang.get(asked ? "permission_asked_at_onboarding" : "permission_not_asked")}</small>

		return <div>
			{TitleRow(Lang.getWithColon("participant_permissions"))}
			<div class="center spacingBottom"><small>{Lang.get("participant_permissions_info")}</small></div>
			{DashRow(
				DashElement("vertical", {
					content:
						<div>
							<div class="dashTitle">{Lang.get("permission_notifications")} · {askedBadge(notificationsAsked)}</div>
							<small>{scheduled.length
								? Lang.get("permission_needed_by", scheduled.join(", "))
								: Lang.get("permission_notifications_not_needed")}</small>
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(study.webPushEnabled)} />
								<span>{Lang.get("permission_notifications_toggle")}</span>
							</label>
							{scheduled.length != 0 && !notificationsAsked &&
								<div><small class="highlight">{Lang.get("permission_notifications_off_warning")}</small></div>}
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(study.notificationNudge)} />
								<span>{Lang.get("permission_notifications_nudge_toggle")}</span>
							</label>
						</div>
				}),
				DashElement("vertical", {
					content:
						<div>
							<div class="dashTitle">{Lang.get("permission_microphone")} · {askedBadge(microphoneAsked)}</div>
							<small>{audioItems.length
								? Lang.get("permission_needed_by", audioItems.join(", "))
								: Lang.get("permission_microphone_not_needed")}</small>
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(study.webMicOnboarding)} disabled={audioItems.length == 0} />
								<span>{Lang.get("permission_microphone_toggle")}</span>
							</label>
							{audioItems.length != 0 && !microphoneAsked &&
								<div><small>{Lang.get("permission_microphone_first_use")}</small></div>}
						</div>
				})
			)}
		</div>
	}

	public getView(): Vnode<any, any> {
		const study = this.getStudyOrThrow()
		const studyMetadata: StudyMetadata | null = this.sectionData.siteData.studyLoader.studyMetadata[study.id.get()]
		const uploadSettings = study.eventUploadSettings
		return <div>
			{DashRow(
				DashElement("stretched", {
					content:
						<div class="center">
							<table class="studyInfoTable"><tbody>
								<tr>
									<td>{Lang.getWithColon("study_version")}</td>
									<td>{`${study.version.get()}.${study.subVersion.get()}`}</td>
								</tr>
								<tr>
									<td>{Lang.getWithColon("created_by")}</td>
									{studyMetadata && <td>{studyMetadata.owner}</td>}
								</tr>
								<tr>
									<td>{Lang.getWithColon("creation_date")}</td>
									{studyMetadata && <td>{new Date(studyMetadata.createdTimestamp * 1000).toLocaleString()}</td>}
								</tr>
								{studyMetadata && studyMetadata.owner != studyMetadata.lastSavedBy && <tr>
									<td>{Lang.getWithColon("last_saved_by")}</td>
									{<td>{studyMetadata.lastSavedBy}</td>}
								</tr>}
								{studyMetadata && studyMetadata.lastSavedAt != studyMetadata.createdTimestamp && <tr>
									<td>{Lang.getWithColon("last_saved_at")}</td>
									{<td>{new Date(studyMetadata.lastSavedAt * 1000).toLocaleString()}</td>}
								</tr>}
							</tbody></table>
						</div>
				}),
				DashElement(null, {
					content:
						<div class="center">
							<span class="middle spacingRight">
								{Lang.getWithColon("study_availability")}
							</span>
							<br />
							<br />
							<label class="middle noDesc" style={ServerInfo.webOnlyMode ? "opacity:0.45" : ""}>
								<small>{Lang.get('Android')}</small>
								<input type="checkbox" {...BindObservable(study.publishedAndroid)} disabled={ServerInfo.webOnlyMode} />
							</label>
							&nbsp;
							<label class="middle noDesc" style={ServerInfo.webOnlyMode ? "opacity:0.45" : ""}>
								<small>{Lang.get('iOS')}</small>
								<input type="checkbox" {...BindObservable(study.publishedIOS)} disabled={ServerInfo.webOnlyMode} />
							</label>
							&nbsp;
							<label class="middle noDesc">
								<small>{Lang.get('Web')}</small>
								<input type="checkbox" {...BindObservable(study.publishedWeb)} />
							</label>
							&nbsp;
							{/* Optional: not offered without a bot, except to switch off a study that still has it on. */}
							{(ServerInfo.telegramConfigured || study.channels.telegram.enabled.get()) &&
								<label class="middle noDesc" title={Lang.get('telegram_availability_info')}>
									<small>{Lang.get('telegram')}</small>
									<input type="checkbox" {...BindObservable(study.channels.telegram.enabled)} />
								</label>}
							{ServerInfo.webOnlyMode && <div><small><i>{Lang.get('web_only_disabled_note')}</i></small></div>}
							{!ServerInfo.telegramConfigured && study.channels.telegram.enabled.get() &&
								<div><small class="highlight">{Lang.get('telegram_not_set_up_on_server')}</small></div>}
						</div>
				}),
				DashElement("vertical", {
					content:
						<div class="vAlignCenter">
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(study.webPhoneOnly)} disabled={!study.publishedWeb.get()} />
								<span>{Lang.get('web_phone_only')}</span>
								<small>{Lang.get('web_phone_only_info')}</small>
							</label>
						</div>
				}),
				DashElement("vertical", {
					content:
						<div class="vAlignCenter">
							<label class="noTitle noDesc">
								<span>{Lang.get('web_language')}</span>
								<select {...BindObservable(study.webLanguage)}>
									<option value="">{Lang.get('web_language_auto')}</option>
									{WEB_LANGUAGES.map((code) => <option value={code}>{webLanguageLabel(code)}</option>)}
								</select>
								<small>{Lang.get('web_language_info')}</small>
							</label>
						</div>
				}),
				DashElement("vertical", {
					content:
						<div class="vAlignCenter">
							<label class="noTitle noDesc">
								<input type="number" {...BindObservable(study.messagePacingWpm, new ConstrainedNumberTransformer(0, 2000))} />
								<span>{Lang.get('message_pacing_wpm')}</span>
								<small>{Lang.get('message_pacing_wpm_info')}</small>
							</label>
						</div>
				}),
				DashElement("vertical", {
					content:
						<div class="vAlignCenter">
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(study.sendMessagesAllowed)} />
								<span>{Lang.get('allow_incoming_messages')}</span>
							</label>
						</div>
				}),
				DashElement("vertical", {
					content:
						<div class="vAlignCenter">
							<label class="noTitle noDesc">
								<input type="number" {...BindObservable(study.additionalDaysActive, new ConstrainedNumberTransformer(0, undefined))} />
								<span>{Lang.get('additional_days_active')}</span>
								<small>{Lang.get('additional_days_active_info')}</small>
							</label>
						</div>
				}),
				DashElement("vertical", {
					content:
						<div class="vAlignCenter">
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(study.legacyScheduling)} />
								<span>{Lang.get('use_legacy_scheduling')}</span>
								<small>{Lang.get('use_legacy_scheduling_info')}</small>
							</label>
						</div>
				}),
				DashElement("vertical", {
					content:
						<div class="vAlignCenter">
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(study.enableTutorialMode)} />
								<span>{Lang.get('enable_tutorial_mode')}</span>
								<small>{Lang.get('enable_tutorial_mode_info')}</small>
							</label>
						</div>
				}),
				this.hasFallbackUrls && DashElement(null, {
					content:
						<div>
							<label class="noTitle">
								<input type="checkbox" {...BindObservable(study.useFallback)} />
								<span>{Lang.get("study_use_fallback")}</span>
								<small>{Lang.get("study_fallback_info")}</small>
							</label>
						</div>
				}),
				DashElement(null, {
					content:
						<div>
							<label class="noTitle">
								<input type="checkbox" checked={this.isFrozen} onchange={this.toggleFreezeStudy.bind(this, study)} />
								<span>{Lang.get("freeze_study")}</span>
								<small>{Lang.get("desc_freeze_study")}</small>
							</label>
						</div>
				})
			)}






			{this.getPermissionsView(study)}

			{TitleRow(Lang.getWithColon("personal_charts"))}
			{DashRow(
				DashElement("vertical", {
					content:
						<div class="vAlignCenter">
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(study.enablePersonalCharts)} />
								<span>{Lang.get("enable_personal_charts")}</span>
								<small>{Lang.get("enable_personal_charts_info")}</small>
							</label>
						</div>
				}),
				study.enablePersonalCharts.get() && DashElement("vertical", {
					content:
						<div class="vAlignCenter">
							<label class="noTitle noDesc">
								<input type="checkbox" {...BindObservable(study.chartsVisibleOnlyAfterStudy)} />
								<span>{Lang.get("charts_visible_only_after_study")}</span>
								<small>{Lang.get("charts_visible_only_after_study_info")}</small>
							</label>
						</div>
				})
			)}

			{TitleRow(Lang.getWithColon("inform_server_about_events"))}
			{DashRow(
				DashElement("stretched", {
					content:
						<div class="vertical hAlignStart">
							<label class="noTitle">
								<input type="checkbox" checked="checked" disabled="disabled" />
								<span>joined</span>
								<small>{Lang.get("desc_joined")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" checked="checked" disabled="disabled" />
								<span>questionnaire</span>
								<small>{Lang.get("desc_questionnaire")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" checked="checked" disabled="disabled" />
								<span>quit</span>
								<small>{Lang.get("desc_quit")}</small>
							</label>


							<label class="noTitle">
								<input type="checkbox" checked="checked" {...BindObservable(uploadSettings.actions_executed)} />
								<span>actions_executed</span>
								<small>{Lang.get("desc_actions_executed")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" {...BindObservable(uploadSettings.invitation)} />
								<span>invitation</span>
								<small>{Lang.get("desc_invitation")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" {...BindObservable(uploadSettings.invitation_missed)} />
								<span>invitation_missed</span>
								<small>{Lang.get("desc_invitation_missed")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" {...BindObservable(uploadSettings.language_changed)} />
								<span>language_changed</span>
								<small>{Lang.get("desc_language_changed")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" {...BindObservable(uploadSettings.message)} />
								<span>message</span>
								<small>{Lang.get("desc_message")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" {...BindObservable(uploadSettings.notification)} />
								<span>notification</span>
								<small>{Lang.get("desc_notification")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" {...BindObservable(uploadSettings.rejoined)} />
								<span>rejoined</span>
								<small>{Lang.get("desc_rejoined")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" {...BindObservable(uploadSettings.reminder)} />
								<span>reminder</span>
								<small>{Lang.get("desc_reminder")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" {...BindObservable(uploadSettings.requested_reward_code)} />
								<span>requested_reward_code</span>
								<small>{Lang.get("desc_requested_reward_code")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" checked="checked" {...BindObservable(uploadSettings.schedule_planned)} />
								<span>schedule_planned</span>
								<small>{Lang.get("desc_schedule_planned")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" checked="checked" {...BindObservable(uploadSettings.schedule_removed)} />
								<span>schedule_removed</span>
								<small>{Lang.get("desc_schedule_removed")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" {...BindObservable(uploadSettings.schedule_changed)} />
								<span>schedule_changed</span>
								<small>{Lang.get("desc_schedule_changed")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" {...BindObservable(uploadSettings.statistic_viewed)} />
								<span>statistic_viewed</span>
								<small>{Lang.get("desc_statistic_viewed")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" {...BindObservable(uploadSettings.study_message)} />
								<span>study_message</span>
								<small>{Lang.get("desc_study_message")}</small>
							</label>
							<label class="noTitle">
								<input type="checkbox" {...BindObservable(uploadSettings.study_updated)} />
								<span>study_updated</span>
								<small>{Lang.get("desc_study_updated")}</small>
							</label>
						</div>
				}))}

			{TitleRow(Lang.getWithColon("reset_study"))}
			<div class="center">
				<small>{Lang.get("reset_study_desc")}</small>
				<br />
				<br />
				{BtnTrash(this.resetStudy.bind(this, study), Lang.get("reset_study"))}
			</div>

			{TitleRow(Lang.getWithColon("delete_study"))}
			<div class="center">
				{BtnTrash(this.deleteStudy.bind(this, study), Lang.get("delete_study"))}
			</div>
		</div>
	}
}