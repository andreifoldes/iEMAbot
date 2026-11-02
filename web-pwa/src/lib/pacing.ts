/**
 * Reading-speed pacing of chat bubbles, for every study and channel.
 *
 * When the app says several things in a row (welcome, description and consent; the tutorial intro
 * and its instructions; the closing bubbles after a check-in), the bubbles appear one after another
 * instead of all at once: before each bot bubble that follows another bot bubble, the app shows a
 * typing indicator until the previous bubble has had time to be read. The first answer to
 * something the participant did is never held back. The reading speed is a study setting
 * (`messagePacingWpm`; 0 turns pacing off). The Telegram channel paces its messages by the same
 * rule (src/backend/channels/ReadingPace.php); keep the two in step.
 */

/** Average silent reading rate of adults for English non-fiction (Brysbaert, 2019); the default speed. */
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

/** A study's reading speed in words per minute; 0 = no pacing. */
export function studyPacingWpm(study: { messagePacingWpm?: number } | null | undefined): number {
  const wpm = Number(study?.messagePacingWpm ?? READING_WPM);
  return Number.isFinite(wpm) && wpm > 0 ? wpm : 0;
}

/** How long to wait after a bubble before revealing the next one, in milliseconds. */
export function readingPauseMs(content: string, html = false, wpm = READING_WPM): number {
  if (wpm <= 0) return 0;
  const ms = (countWords(content, html) / wpm) * 60000;
  return Math.round(Math.min(MAX_PAUSE_MS, Math.max(MIN_PAUSE_MS, ms)));
}

/** The parts of a chat bubble pacing looks at. */
export interface PacedBubble {
  sender: 'bot' | 'user';
  content: string;
  html?: boolean;
  /** Settled question/answer pair: it replaces the live question card, so it is never delayed. */
  qid?: string;
  /** Shown at once (e.g. the conversation a returning participant reopens). */
  instant?: boolean;
}

/** True when `next` may only appear once `prev` has been read: a bot bubble right after a bot bubble. */
export function waitsToBeRead(prev: PacedBubble | undefined, next: PacedBubble, wpm = READING_WPM): boolean {
  return wpm > 0 && !!prev && prev.sender === 'bot' && next.sender === 'bot' && !next.qid && !next.instant;
}

/**
 * How much longer `next` has to wait, in milliseconds, given that `prev` (the bubble before it)
 * appeared at `prevShownAt`; 0 = show it now. Only a bot bubble that follows a bot bubble waits,
 * and only for what is left of the previous bubble's reading time, so a message arriving long
 * after the last one is not held back either.
 */
export function revealDelayMs(prev: PacedBubble | undefined, next: PacedBubble, prevShownAt: number, now: number, wpm = READING_WPM): number {
  if (!waitsToBeRead(prev, next, wpm)) return 0;
  return Math.max(0, readingPauseMs(prev!.content, prev!.html, wpm) - (now - prevShownAt));
}
