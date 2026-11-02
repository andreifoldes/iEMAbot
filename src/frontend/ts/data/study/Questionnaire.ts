import { DataStructure, DataStructureInputType } from "../DataStructure";
import { ActionTrigger } from "./ActionTrigger";
import { Page } from "./Page";
import { SumScore } from "./SumScore";
import { BaseObservable } from "../../observable/BaseObservable";
import { ObservableTypes } from "../../observable/types/ObservableTypes";
import "../../number.extensions"
import { Lang } from "../../singletons/Lang";

export class Questionnaire extends DataStructure {
	public internalId = this.primitive<number>("internalId", -1)
	public publishedAndroid = this.primitive<boolean>("publishedAndroid", true)
	public publishedIOS = this.primitive<boolean>("publishedIOS", true)
	public publishedWeb = this.primitive<boolean>("publishedWeb", true)
	// PWA chat flow only: which answered items show a "Change response" affordance.
	// "previous" = only the last item (t-1), "any" = every prior item, "none" = disabled
	// (e.g. for cognitive tasks, which must not be re-attempted).
	public changeResponseMode = this.primitive<string>("changeResponseMode", "previous")
	// PWA tutorial overview only: offer a practice run of this questionnaire (off for e.g. a
	// second daytime check-in that is identical to the first).
	public includeInTutorial = this.primitive<boolean>("includeInTutorial", true)
	public limitToGroup = this.primitive<number>("limitToGroup", 0)
	public minDataSetsForReward = this.primitive<number>("minDataSetsForReward", 0)
	public isBackEnabled = this.primitive<boolean>("isBackEnabled", true)
	public endScriptBlock = this.primitive<string>("endScriptBlock", "")
	public showInDisabledList = this.primitive<boolean>("showInDisabledList", true)
	public virtualInputs = this.primitiveArray<string>("virtualInputs", [])
	public scriptFilter = this.primitive<string>("scriptFilter", "")
	public showPagination = this.primitive<boolean>("showPagination", true)
	public showSkipToast = this.primitive<boolean>("showSkipToast", true)

	public title = this.translatable("title", "")

	// ESMira action triggers (in-app messages, event cues) are not run by this server and have no editor here; kept
	// as they are. When a questionnaire is open and prompted is the study's schedule (data/study/StudySchedule.ts).
	public actionTriggers = this.objectArray("actionTriggers", ActionTrigger)
	public pages = this.objectArray("pages", Page)
	public sumScores = this.objectArray("sumScores", SumScore)

	constructor(data: DataStructureInputType, parent: BaseObservable<ObservableTypes> | null) {
		super(data, parent, data["internalId"]?.toString() ?? "0")
	}
	public updateKeyName(_keyName: string) {
		super.updateKeyName(this.internalId.get().toString())
	}

	public getTitle(): string {
		if (this.limitToGroup.get() == 0)
			return this.title.get()
		else
			return `${this.title.get()} (${Lang.get("group")} ${this.limitToGroup.get()})`
	}
}
