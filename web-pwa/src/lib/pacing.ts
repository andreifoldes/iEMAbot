/**
 * Reading-speed pacing for batches of chat bubbles (study field `webPacedMessages`).
 *
 * With pacing on, the welcome, description and consent bubbles of a first visit, and the closing
 * bubbles after a check-in (e.g. snapshot, your answers, thank-you), appear one after another
 * instead of all at once: before each bubble the app shows a typing indicator for as long as the
 * previous bubble takes to read.
 */

/** Average silent reading rate of adults for English non-fiction (Brysbaert, 2019). */
export const READING_WPM = 238;
/** Even a one-word bubble gets a beat before the next one arrives. */
export const MIN_PAUSE_MS = 1200;
/** Long bubbles stay readable after the next one arrives, so never keep the reader waiting longer. */
export const MAX_PAUSE_MS = 10000;

/** Words in a bubble; rich-text bubbles are counted without their markup. */
export function countWords(content: string, html = false): number {
  const text = html
    ? content.replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/&[a-z#0-9]+;/gi, '')
    : content;
  return text.split(/\s+/).filter((w) => /[\p{L}\p{N}]/u.test(w)).length;
}

/** How long to wait after a bubble before revealing the next one, in milliseconds. */
export function readingPauseMs(content: string, html = false): number {
  const ms = (countWords(content, html) / READING_WPM) * 60000;
  return Math.round(Math.min(MAX_PAUSE_MS, Math.max(MIN_PAUSE_MS, ms)));
}
