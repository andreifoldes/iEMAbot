import { DataStructure, DataStructureInputType } from "../DataStructure";
import { Statistics } from "./Statistics";
import { Questionnaire } from "./Questionnaire";
import { ObservableTypes } from "../../observable/types/ObservableTypes";
import { EventUploadSettings } from "./EventUploadSettings";
import { BaseObservable } from "../../observable/BaseObservable";
import { TranslatableRootInterface } from "../../observable/interfaces/TranslatableRootInterface";
import { Input, InputMediaTypes } from "./Input";
import { RepairStudy } from "../../helpers/RepairStudy";
import { Lang } from "../../singletons/Lang";
import { PluginContainer } from "./PluginContainer";
import { StudyChannels } from "./StudyChannels";

export class Study extends DataStructure implements TranslatableRootInterface {
	public lastChanged: number

	public id = this.primitive<number>("id", 0)
	public version = this.primitive<number>("version", 0)
	public subVersion = this.primitive<number>("subVersion", 0)
	public packageVersion = this.primitive<string>("packageVersion", "0.0.0")
	public serverVersion = this.primitive<number>("serverVersion", 0)
	public lang = this.primitive<string>("lang", "")
	public newChanges = this.primitive<boolean>("new_changes", false)
	public published = this.primitive<boolean>("published", false)
	public publishedWeb = this.primitive<boolean>("publishedWeb", true)
	public publishedAndroid = this.primitive<boolean>("publishedAndroid", true)
	public publishedIOS = this.primitive<boolean>("publishedIOS", true)
	public studyOver = this.primitive<boolean>("studyOver", false)
	public sendMessagesAllowed = this.primitive<boolean>("sendMessagesAllowed", true)
	public randomGroups = this.primitive<number>("randomGroups", 1)
	public enableRewardSystem = this.primitive<boolean>("enableRewardSystem", false)
	public rewardVisibleAfterDays = this.primitive<number>("rewardVisibleAfterDays", 0)
	public defaultLang = this.primitive<string>("defaultLang", "en")
	public useFallback = this.primitive<boolean>("useFallback", true)
	public additionalDaysActive = this.primitive<number>("additionalDaysActive", 0)
	public legacyScheduling = this.primitive<boolean>("legacyScheduling", false)
	public enableTutorialMode = this.primitive<boolean>("enableTutorialMode", false)
	// When enabled, the participant PWA registers a web-push subscription after
	// consent and the server sends questionnaire reminders (see api/push_subscribe.php).
	public webPushEnabled = this.primitive<boolean>("webPushEnabled", false)
	// When enabled, the participant PWA only runs the study on a smartphone. Computers and
	// tablets get an invite page with a QR code to continue on a phone instead
	// (see web-pwa/src/lib/phoneGate.ts). A convenience check in the browser, not a security measure.
	public webPhoneOnly = this.primitive<boolean>("webPhoneOnly", false)
	// When enabled, the participant PWA does not ask for a display name; the participant
	// is identified only by the generated study id.
	public webSkipName = this.primitive<boolean>("webSkipName", false)
	// Names an end-of-questionnaire read-out of the participant's own answers in the PWA
	// (see web-pwa/src/lib/snapshot.ts). No editor control: set in the study source.
	public webSnapshot = this.primitive<string>("webSnapshot", "")
	// When enabled, the participant PWA sends nothing to the server (no answers, join or other
	// events, telemetry). No editor control: set in the study source. See web-pwa/src/lib/esmiraApi.ts.
	public webLocalOnly = this.primitive<boolean>("webLocalOnly", false)
	// When enabled, a cognitive task card in the PWA offers Start task / Skip task / Remind me later /
	// Skip check-in. No editor control: set in the study source.
	public webTaskActions = this.primitive<boolean>("webTaskActions", false)
	// When enabled, the PWA reveals the opening bubbles of a first visit and the closing bubbles of a
	// check-in one by one at reading speed (see web-pwa/src/lib/pacing.ts). No editor control: set in the study source.
	public webPacedMessages = this.primitive<boolean>("webPacedMessages", false)
	// When enabled, the participant PWA can connect a wearable (Withings/Google Health)
	// via OAuth and the server polls it for data (see backend/wearables/, api/wearables_*).
	// wearablesProviders limits which providers are offered; wearablesDataTypes (empty =
	// each provider's defaults) limits which metrics are collected.
	public wearablesEnabled = this.primitive<boolean>("wearablesEnabled", false)
	public wearablesProviders = this.primitiveArray<string>("wearablesProviders")
	public wearablesDataTypes = this.primitiveArray<string>("wearablesDataTypes")
	// Whether participant-facing personal charts are enabled. Defaults to true so existing
	// studies keep showing charts. Set to false to hide the charts tab entirely.
	public enablePersonalCharts = this.primitive<boolean>("enablePersonalCharts", true)
	// When true, participants only see their personal charts after the study is marked
	// as over (studyOver = true). False = charts visible throughout the study.
	public chartsVisibleOnlyAfterStudy = this.primitive<boolean>("chartsVisibleOnlyAfterStudy", false)
	// Base64 data URL of optional artwork displayed on the study invite page.
	public studyArtwork = this.primitive<string>("studyArtwork", "")

	public accessKeys = this.primitiveArray<string>("accessKeys")
	public langCodes = this.primitiveArray<string>("langCodes", ["en"])

	public title = this.translatable("title", "Study title")
	public studyDescription = this.translatable("studyDescription", "")
	public studyTag = this.translatable("studyTag", "")
	public informedConsentForm = this.translatable("informedConsentForm", "")
	public postInstallInstructions = this.translatable("postInstallInstructions", "")
	// Web PWA tutorial: the yes/no offer prompt and the intro shown when the tour
	// begins. Empty = the PWA uses its built-in default text. (Shown in the
	// Descriptions editor only when enableTutorialMode is on.)
	public tutorialOffer = this.translatable("tutorialOffer", "")
	public tutorialIntro = this.translatable("tutorialIntro", "")
	public chooseUsernameInstructions = this.translatable("chooseUsernameInstructions", "")
	public webQuestionnaireCompletedInstructions = this.translatable("webQuestionnaireCompletedInstructions", "")
	public webInstallInstructions = this.translatable("webInstallInstructions", "")
	public contactEmail = this.translatable("contactEmail", "")
	public rewardEmailContent = this.translatable("rewardEmailContent", "")
	public rewardInstructions = this.translatable("rewardInstructions", "")
	public postStudyNote = this.translatable("postStudyNote", "")
	public faq = this.translatable("faq", "")

	public questionnaires = this.objectArray("questionnaires", Questionnaire)

	public eventUploadSettings = this.object("eventUploadSettings", EventUploadSettings)
	public publicStatistics = this.object("publicStatistics", Statistics)
	public personalStatistics = this.object("personalStatistics", Statistics)
	public pluginData = this.object("pluginData", PluginContainer)
	// Messaging channels (currently Telegram, see backend/channels/). The backend reads
	// channels.telegram.enabled; defaults are omitted from the saved JSON.
	public channels = this.object("channels", StudyChannels)

	constructor(data: DataStructureInputType, parent: BaseObservable<ObservableTypes> | null, lastChanged: number, repair: RepairStudy | null) {
		if (repair != null && !repair.repairStudy(data))
			throw Lang.get("error_study_not_compatible", data["title"]?.toString() ?? "Error")

		let defaultLang = data["defaultLang"] as string ?? "en"
		if (data.hasOwnProperty("langCodes") && (data["langCodes"] as string[]).indexOf(defaultLang) == -1)
			defaultLang = (data["langCodes"] as string[])[0]

		super(data, parent, data["id"] as string ?? "-1", defaultLang)
		this.lastChanged = lastChanged
	}

	public updateKeyName() {
		super.updateKeyName(this.id.get().toString())
	}

	/**
	 * Executes the callback function for each input element.
	 * If callback function returns false, the loop is canceled
	 */
	public forEachInput(callback: (input: Input, index: number) => void | boolean) {
		let questionnaires = this.questionnaires.get();
		let index = 0
		for (let iQ = questionnaires.length - 1; iQ >= 0; iQ--) {
			let pages = questionnaires[iQ].pages.get();
			for (let iP = pages.length - 1; iP >= 0; iP--) {
				let inputs = pages[iP].inputs.get();
				for (let iI = inputs.length - 1; iI >= 0; iI--) {
					if (callback(inputs[iI], index++) === false)
						return;
				}
			}
		}
	}

	public hasMedia(): boolean {
		let hasMedia = false
		this.forEachInput((input) => {
			if (input.getMediaType() != null) {
				hasMedia = true
				return false
			}
		})
		return hasMedia
	}

	public hasMerlinScripts(): boolean {
		return this.questionnaires.get().some((questionnaire) => {
			return questionnaire.endScriptBlock.get() !== "" || questionnaire.pages.get().some((page) => {
				return page.relevance.get() !== "" || page.inputs.get().some((input) => {
					input.relevance.get() !== "" || input.textScript.get() !== ""
				})
			})
		})
	}

	public getInputNamesPerType(): Record<InputMediaTypes, string[]> {
		const names: Record<string, string[]> = {}
		this.forEachInput((input) => {
			const type = input.getMediaType()
			if (type != null) {
				if (!names.hasOwnProperty(type))
					names[type] = []
				names[type].push(input.name.get());
			}
		});

		return names;
	}

	public countScheduledAlarms(): number {
		let alarms: number = 0
		for (let questionnaire of this.questionnaires.get()) {
			alarms += questionnaire.countScheduledAlarms()
		}
		return alarms
	}
}