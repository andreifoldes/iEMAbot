import { DataStructure } from "../DataStructure";

/** Per-study Telegram delivery settings (study JSON: channels.telegram). */
export class TelegramStudySettings extends DataStructure {
	// Offer this study through the server's Telegram bot (invite deep links, chat check-ins).
	public enabled = this.primitive<boolean>("enabled", false)
	// Open slider, keystroke and cognitive task items in a Telegram mini app. When false,
	// sliders become buttons, keystroke items plain typed text and cognitive tasks are skipped.
	public allowHandoff = this.primitive<boolean>("allowHandoff", true)
}
