/**
 * The list of participant ids a study is restricted to (Study → Participant IDs). Pure helpers, so the rules are tested without
 * a page: what counts as an id, how a pasted or uploaded list is read, how random ids are made, and the file of invite links.
 * The server applies the same rules again (backend/participants/ParticipantAllowList.php), and is the one that decides.
 */

/** The longest id the server's list takes. */
export const PID_MAX_LENGTH = 128
/** The most ids a study's list takes. */
export const PID_MAX_COUNT = 20000

/** The characters the server accepts in an id anywhere (Main::strictCheckInput): letters, marks, digits, _ ' - ( ) . and spaces. */
const PID_PATTERN = /^[\p{L}\p{M}\p{N}_'\-().\s]+$/u

export function isValidPid(id: string): boolean {
	return id.length > 0 && id.length <= PID_MAX_LENGTH && PID_PATTERN.test(id)
}

/**
 * Reads a pasted or uploaded list: one id per line, though commas, semicolons and tabs also separate (an id never holds
 * them). Ids are trimmed, empty ones dropped, and one that repeats another in another letter case is dropped (the first
 * spelling stays), as the server does.
 */
export function parseIdList(text: string): string[] {
	const seen = new Set<string>()
	const ids: string[] = []
	for(const part of text.split(/[\r\n,;\t]+/)) {
		const id = part.trim()
		if(id === "")
			continue
		const key = id.toLowerCase()
		if(seen.has(key))
			continue
		seen.add(key)
		ids.push(id)
	}
	return ids
}

/** The ids the server would refuse to take into a list. */
export function invalidIds(ids: string[]): string[] {
	return ids.filter((id) => !isValidPid(id))
}

/** 32 letters and digits without I, O, 0 and 1, which are easily mixed up when an id is read out or copied by hand. */
const ID_ALPHABET = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789"
export const GENERATED_ID_LENGTH = 12

export type RandomBytes = (length: number) => Uint8Array

function browserRandomBytes(length: number): Uint8Array {
	return crypto.getRandomValues(new Uint8Array(length))
}

/**
 * New random ids, 12 characters from a 32-letter alphabet (60 bits, from the browser's crypto generator), none of them
 * already in `existing` in any letter case, and no two alike. A byte is reduced with `& 31`, which is unbiased as 256 is a
 * multiple of 32. Ids made this way cannot be guessed, unlike P001, P002.
 */
export function generateIds(count: number, existing: string[] = [], randomBytes: RandomBytes = browserRandomBytes): string[] {
	const taken = new Set(existing.map((id) => id.toLowerCase()))
	const made: string[] = []
	while(made.length < count) {
		const bytes = randomBytes(GENERATED_ID_LENGTH)
		const id = Array.from(bytes, (byte) => ID_ALPHABET[byte & 31]).join("")
		if(taken.has(id.toLowerCase()))
			continue
		taken.add(id.toLowerCase())
		made.push(id)
	}
	return made
}

/** A file of personal links to hand out, one row per id: `participant_id;link`. */
export function inviteLinksCsv(ids: string[], makeLink: (id: string) => string): string {
	const cell = (value: string) => /[";\r\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value
	return ["participant_id;link", ...ids.map((id) => `${cell(id)};${cell(makeLink(id))}`)].join("\r\n") + "\r\n"
}
