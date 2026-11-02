/**
 * A half-finished check-in kept on this device, so it survives the app being closed or killed.
 *
 * The app saves the answers given so far after every answer (and when a check-in starts), keyed by
 * study, participant and questionnaire. Reopening the app carries on where it stopped as long as
 * the window the check-in was opened in is still open; once that window has closed, or the
 * questionnaire is done, the saved progress is dropped. A check-in is never resumed into a later
 * window (tomorrow's morning check-in starts empty, not with today's answers).
 *
 * "Remind me later" on a task card (study field `webTaskActions`) saves the same way, but marks the
 * check-in as parked: the list offers to resume it instead of reopening it straight away.
 * Nothing here is sent to the server. Submitting or skipping the check-in drops it.
 */

import type { Availability } from './schedule';

export interface PausedCheckin {
  questionnaireId: number;
  /** Engine position when saved, so questions skipped earlier are not asked again. */
  currentIndex: number;
  responses: Record<string, string>;
  specifyTexts: Record<string, string>;
  audioIds: number[];
  keystrokeIds: number[];
  keystrokeTexts: Record<string, string>;
  keystrokeModes: Record<string, string>;
  /** Cognitive task ids in the run, so a resume keeps the battery subset drawn at the start. */
  cognitiveIds?: string[];
  /** Question ids in the order shown, so randomized pages come back in the same order. */
  order?: string[];
  /** Skip-fallback questions already revealed (a typed answer standing in for a skipped voice memo). */
  fallbacks?: string[];
  /** The window the check-in was opened in (Availability.occKey). Absent in saves from older versions. */
  occKey?: string;
  /** Parked with "Remind me later": offered on the list rather than reopened by itself. */
  remindLater?: boolean;
  /** When the save was made. */
  pausedAt: number;
  /** When the check-in was first opened, so the submitted form duration covers the whole check-in. */
  startedAt?: number;
}

export const pausedKey = (studyId: number, userId: string, questionnaireId: number): string =>
  `esmira_paused_${studyId}_${userId}_${questionnaireId}`;
/** Before saves were per questionnaire there was one slot per study and participant. Still read. */
const legacyKey = (studyId: number, userId: string): string => `esmira_paused_${studyId}_${userId}`;

const isRecord = (v: unknown): v is Record<string, string> =>
  typeof v === 'object' && v !== null && !Array.isArray(v) && Object.values(v).every((x) => typeof x === 'string');
const isNumbers = (v: unknown): v is number[] => Array.isArray(v) && v.every((x) => typeof x === 'number');
const isStrings = (v: unknown): v is string[] => Array.isArray(v) && v.every((x) => typeof x === 'string');

function parse(raw: string | null): PausedCheckin | null {
  if (!raw) return null;
  try {
    const v = JSON.parse(raw) as Partial<PausedCheckin> | null;
    if (
      !v
      || typeof v.questionnaireId !== 'number'
      || typeof v.currentIndex !== 'number'
      || typeof v.pausedAt !== 'number'
      || !isRecord(v.responses)
      || !isRecord(v.specifyTexts)
      || !isNumbers(v.audioIds)
      || !isNumbers(v.keystrokeIds)
      || !isRecord(v.keystrokeTexts)
      || !isRecord(v.keystrokeModes)
      || (v.cognitiveIds !== undefined && !isStrings(v.cognitiveIds))
      || (v.order !== undefined && !isStrings(v.order))
      || (v.fallbacks !== undefined && !isStrings(v.fallbacks))
      || (v.occKey !== undefined && typeof v.occKey !== 'string')
      || (v.startedAt !== undefined && typeof v.startedAt !== 'number')
    ) return null;
    return v as PausedCheckin;
  } catch {
    return null;
  }
}

/** Save a check-in's progress. Returns false when storage refuses (full or blocked), so the caller can say so. */
export function savePaused(studyId: number, userId: string, paused: PausedCheckin): boolean {
  try {
    localStorage.setItem(pausedKey(studyId, userId, paused.questionnaireId), JSON.stringify(paused));
  } catch {
    return false;
  }
  // The legacy slot for the same questionnaire is now out of date.
  if (parse(safeGet(legacyKey(studyId, userId)))?.questionnaireId === paused.questionnaireId) safeRemove(legacyKey(studyId, userId));
  return true;
}

/** The saved check-in for a questionnaire, or null when there is none or the stored value is unusable. Never throws. */
export function loadPaused(studyId: number, userId: string, questionnaireId: number): PausedCheckin | null {
  const own = parse(safeGet(pausedKey(studyId, userId, questionnaireId)));
  if (own?.questionnaireId === questionnaireId) return own;
  const legacy = parse(safeGet(legacyKey(studyId, userId)));
  return legacy?.questionnaireId === questionnaireId ? legacy : null;
}

export function clearPaused(studyId: number, userId: string, questionnaireId: number): void {
  safeRemove(pausedKey(studyId, userId, questionnaireId));
  const legacy = safeGet(legacyKey(studyId, userId));
  if (legacy !== null && (parse(legacy)?.questionnaireId ?? questionnaireId) === questionnaireId) safeRemove(legacyKey(studyId, userId));
}

/**
 * Whether a saved check-in can carry on now: its questionnaire is open, in the same window it was
 * saved in. Saves from older versions carry no window; they count when made after the open window opened.
 */
export function canResume(paused: PausedCheckin, av: Availability | undefined): boolean {
  if (!av || av.state !== 'available') return false;
  if (paused.occKey !== undefined) return paused.occKey === av.occKey;
  if (av.openedAt !== undefined) return paused.pausedAt >= av.openedAt;
  return av.occKey === undefined;
}

function safeGet(key: string): string | null {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

function safeRemove(key: string): void {
  try {
    localStorage.removeItem(key);
  } catch {
    /* nothing stored, nothing to clear */
  }
}
