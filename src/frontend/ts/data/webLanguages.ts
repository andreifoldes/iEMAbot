import {Lang} from "../singletons/Lang"
import machineTranslated from "../../../locales/meta/pwa-machine-translated.json"

/**
 * Languages the participant web app's interface can be set to (study setting `webLanguage`).
 * These are the file names in src/locales/. A language shows translated text only for the
 * `pwa_*` keys it has; the rest appears in English. web-pwa/src/lib/webLanguages.test.mts
 * checks this list against the folder.
 */
export const WEB_LANGUAGES = [
	"bs", "cs", "da", "de", "el", "en", "es", "fi", "fr", "he", "hi", "hr", "it", "ja", "ko", "nb_NO",
	"nl", "pl", "pt", "ro", "sl", "sr", "sv", "tl", "tr", "uk", "zh_Hans",
] as const

/** A language's name in the dashboard's own language ("German"), falling back to the code. */
export function webLanguageName(code: string): string {
	const tag = code.replace("_", "-")
	try {
		return new Intl.DisplayNames([Lang.code.replace("_", "-")], {type: "language"}).of(tag) ?? code
	} catch(e) {
		return code
	}
}

/**
 * The name shown in the study settings: "Danish", or "Danish (machine-translated)" for a language whose
 * participant-app text has not been reviewed by people (see src/locales/meta/pwa-machine-translated.json).
 */
export function webLanguageLabel(code: string): string {
	return (machineTranslated as string[]).includes(code)
		? `${webLanguageName(code)} (${Lang.get("web_language_machine")})`
		: webLanguageName(code)
}
