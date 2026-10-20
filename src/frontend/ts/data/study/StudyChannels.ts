import { DataStructure } from "../DataStructure";
import { TelegramStudySettings } from "./TelegramStudySettings";

/** Messaging channels a study can be delivered through besides the apps and the web (study JSON: channels). */
export class StudyChannels extends DataStructure {
	public telegram = this.object("telegram", TelegramStudySettings)
}
