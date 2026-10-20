import {SectionContent} from "../site/SectionContent";
import m, {Vnode} from "mithril";
import {DashRow} from "../components/DashRow";
import {DashElement} from "../components/DashElement";
import {Lang} from "../singletons/Lang";
import participateSvg from "../../imgs/dashIcons/participate.svg?raw"
import questionSvg from "../../imgs/icons/question.svg?raw"
import {PromiseCache} from "../singletons/PromiseCache";
import {Requests} from "../singletons/Requests";
import {FILE_SETTINGS} from "../constants/urls";
import {SectionData} from "../site/SectionData";

export class Content extends SectionContent {
	private readonly homeMessage: string
	
	public title(): string {
		return Lang.get("home")
	}
	public static preLoad(): Promise<any>[] {
		return [
			PromiseCache.get("homeSettings", () => {
				return Requests.loadJson(FILE_SETTINGS.replace("%1", Lang.code).replace("%2", "homeMessage"))
			})
		]
	}
	constructor(sectionData: SectionData, settings: {homeMessage: string}) {
		super(sectionData)
		this.homeMessage = settings.homeMessage
	}
	public getView(): Vnode<any, any> {
		return DashRow(
			DashElement(null, {template: {title: Lang.get("participate_in_study"), icon: m.trust(participateSvg)}, href: this.getUrl("studies:attend")}),
			DashElement(null, {template: {title: Lang.get("what_is_esmira"), icon: m.trust(questionSvg)}, href: this.getUrl("about")}),
			// Server and study statistics are research-staff information: researchers find them in the admin area
		this.homeMessage.length > 0 && DashElement("stretched", {content: <div>{m.trust(this.homeMessage)}</div>}),
		)
	}
}