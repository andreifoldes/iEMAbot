/**
 * "Remind me later" on a task card: pause a half-finished questionnaire on this device.
 *
 * Studies that set `webTaskActions` show Start task / Skip task / Remind me later / Skip check-in
 * under a cognitive task. Remind me later parks the answers given so far in localStorage and
 * returns to the questionnaire list, which then offers to resume it. Nothing is sent to the
 * server and no push is scheduled, so the app says the answers are saved rather than promising a
 * reminder. One paused check-in is kept per study and participant; resuming or skipping drops it.
 */

export interface PausedCheckin {
  questionnaireId: number;
  /** Engine position when paused, so questions skipped earlier are not asked again. */
  currentIndex: number;
  responses: Record<string, string>;
  specifyTexts: Record<string, string>;
  audioIds: number[];
  keystrokeIds: number[];
  keystrokeTexts: Record<string, string>;
  keystrokeModes: Record<string, string>;
  pausedAt: number;
}

export const pausedKey = (studyId: number, userId: string): string => `esmira_paused_${studyId}_${userId}`;

const isRecord = (v: unknown): v is Record<string, string> =>
  typeof v === 'object' && v !== null && !Array.isArray(v) && Object.values(v).every((x) => typeof x === 'string');
const isNumbers = (v: unknown): v is number[] => Array.isArray(v) && v.every((x) => typeof x === 'number');

/** Park a check-in. Returns false when storage refuses (full or blocked), so the caller can say so. */
export function savePaused(studyId: number, userId: string, paused: PausedCheckin): boolean {
  try {
    localStorage.setItem(pausedKey(studyId, userId), JSON.stringify(paused));
    return true;
  } catch {
    return false;
  }
}

/** The paused check-in, or null when there is none or the stored value is unusable. Never throws. */
export function loadPaused(studyId: number, userId: string): PausedCheckin | null {
  try {
    const raw = localStorage.getItem(pausedKey(studyId, userId));
    if (!raw) return null;
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
    ) return null;
    return v as PausedCheckin;
  } catch {
    return null;
  }
}

export function clearPaused(studyId: number, userId: string): void {
  try {
    localStorage.removeItem(pausedKey(studyId, userId));
  } catch {
    /* nothing stored, nothing to clear */
  }
}
