import { Study } from "../data/study/Study";
import { Input, InputResponseType } from "../data/study/Input";

/**
 * How questionnaire items appear when a study is answered in a Telegram chat.
 * Mirrors the backend conversation engine (backend/channels/conversation/QuestionnaireFlattener.php
 * and PromptFactory.php):
 *  - native:       asked directly in the chat (buttons, typed reply, voice message, photo, ...)
 *  - miniApp:      opened in a Telegram mini app; without handoff it is approximated
 *                  (va_scale → 0–100 buttons, record_keystrokes → plain typed text) or,
 *                  for cognitive tasks, skipped
 *  - unavailable:  sensor items that a chat cannot provide; skipped
 */
export type TelegramSupport = "native" | "miniApp" | "unavailable"

const UNAVAILABLE_TYPES: InputResponseType[] = [
	"ambient_light", "app_usage", "battery_level", "bluetooth_devices", "compass", "noise_level"
]
const MINI_APP_TYPES: InputResponseType[] = ["va_scale", "record_keystrokes", "webapp"]

/** Item relevance supported by the PWA and the chat: a single `name ==|!=|>= value` comparison. */
const SUPPORTED_RELEVANCE = /^\s*[A-Za-z_]\w*\s*(==|!=|>=)\s*(?:"[^"]*"|'[^']*'|\S+)\s*$/

/** A cognitive task is a `webapp` item or any item whose text holds a link (see QuestionnaireFlattener). */
const LINK_IN_TEXT = /<a\b[^>]*href="[^"]+"[^>]*>/i

export interface TelegramItemReport {
	questionnaire: string
	name: string
	responseType: InputResponseType
	support: TelegramSupport
	// The item is a cognitive task (webapp or text link)
	cognitive: boolean
	// The item has a relevance condition the chat ignores (the item always shows)
	unsupportedRelevance: boolean
	// The item opens a page with a page relevance condition, which the chat ignores
	pageRelevanceIgnored: boolean
}

export interface TelegramCompatibilityReport {
	total: number
	native: number
	items: TelegramItemReport[]
}

export function telegramSupportForType(type: InputResponseType): TelegramSupport {
	if (UNAVAILABLE_TYPES.indexOf(type) != -1)
		return "unavailable"
	if (MINI_APP_TYPES.indexOf(type) != -1)
		return "miniApp"
	return "native"
}

export function isRelevanceSupported(relevance: string): boolean {
	return relevance === "" || SUPPORTED_RELEVANCE.test(relevance)
}

function inputText(input: Input): string {
	const text = input.text.get()
	return typeof text == "string" ? text : ""
}

export function telegramSupportForInput(input: Input): { support: TelegramSupport, cognitive: boolean } {
	const type = input.responseType.get()
	const cognitive = type == "webapp" || LINK_IN_TEXT.test(inputText(input))
	return { support: cognitive ? "miniApp" : telegramSupportForType(type), cognitive: cognitive }
}

/**
 * Walks all questionnaires, pages and items of the study (in order) and reports each item that
 * does not appear natively in Telegram or whose condition is ignored. Page relevance is not
 * evaluated in the chat at all; it is reported on the page's first item.
 */
export function checkTelegramCompatibility(study: Study): TelegramCompatibilityReport {
	const report: TelegramCompatibilityReport = { total: 0, native: 0, items: [] }
	for (const questionnaire of study.questionnaires.get()) {
		const qTitle = questionnaire.getTitle()
		for (const page of questionnaire.pages.get()) {
			const hasPageRelevance = page.relevance.get() !== ""
			page.inputs.get().forEach((input, index) => {
				++report.total
				const { support, cognitive } = telegramSupportForInput(input)
				const unsupportedRelevance = !isRelevanceSupported(input.relevance.get())
				const pageRelevanceIgnored = index == 0 && hasPageRelevance
				if (support == "native")
					++report.native
				if (support == "native" && !unsupportedRelevance && !pageRelevanceIgnored)
					return
				report.items.push({
					questionnaire: qTitle,
					name: input.name.get(),
					responseType: input.responseType.get(),
					support: support,
					cognitive: cognitive,
					unsupportedRelevance: unsupportedRelevance,
					pageRelevanceIgnored: pageRelevanceIgnored
				})
			})
		}
	}
	return report
}
