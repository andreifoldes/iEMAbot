import { Study } from "../data/study/Study";

/**
 * Rough size of raw accelerometer recording (study setting phoneSensingAccelerometer), shown as a warning in
 * the Phone sensing panel. Bytes per sample were measured: the server keeps samples as compressed chunks
 * (backend/AccelerometerCodec.php), about 4 bytes each on disk; 5 leaves room for noisier real-world data.
 * The gzip-compressed upload takes about 10.
 */
export const DB_BYTES_PER_SAMPLE = 5
export const UPLOAD_BYTES_PER_SAMPLE = 10

export interface AccelerometerEstimateInput {
	beforeMin: number
	afterMin: number
	hz: number
	promptsPerDay: number
	participants: number
	days: number
}

export interface AccelerometerEstimate {
	samplesPerPrompt: number
	dbBytesPerPrompt: number
	dbBytesPerParticipantDay: number
	uploadBytesPerParticipantDay: number
	totalSamples: number
	totalDbBytes: number
}

export function estimateAccelerometer(i: AccelerometerEstimateInput): AccelerometerEstimate {
	const samplesPerPrompt = Math.max(0, i.beforeMin + i.afterMin) * 60 * Math.max(0, i.hz)
	const perDay = samplesPerPrompt * Math.max(0, i.promptsPerDay)
	const totalSamples = perDay * Math.max(0, i.participants) * Math.max(0, i.days)
	return {
		samplesPerPrompt,
		dbBytesPerPrompt: samplesPerPrompt * DB_BYTES_PER_SAMPLE,
		dbBytesPerParticipantDay: perDay * DB_BYTES_PER_SAMPLE,
		uploadBytesPerParticipantDay: perDay * UPLOAD_BYTES_PER_SAMPLE,
		totalSamples,
		totalDbBytes: totalSamples * DB_BYTES_PER_SAMPLE,
	}
}

/**
 * Prompts on a day on which every check-in of the schedule runs (reminders are not counted): the accelerometer
 * records one window around each of them.
 */
export function scheduledPromptsPerDay(study: Study): number {
	return study.schedule.checkins.get().filter((c) => c.prompt.get() != "none").length
}

/** The length of the study in days (the latest last day of its check-ins), or 0 when they run indefinitely. */
export function studyDays(study: Study): number {
	return Math.max(0, ...study.schedule.checkins.get().map((c) => c.dayUntil.get()))
}

export function formatBytes(bytes: number): string {
	const units = ["B", "KB", "MB", "GB", "TB"]
	let value = bytes
	let unit = 0
	while(value >= 1000 && unit < units.length - 1) {
		value /= 1000
		++unit
	}
	return `${value >= 100 || unit == 0 ? Math.round(value) : value.toFixed(1)} ${units[unit]}`
}

export function formatCount(n: number): string {
	return Math.round(n).toLocaleString()
}
