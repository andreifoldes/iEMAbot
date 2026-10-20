/**
 * Shaping of an m2c2kit task result (posted by the task iframe as `m2c2:complete`) into
 * ESMira data: a compact summary for the item's own column and one row per trial for the
 * hidden "Cognitive Trials" questionnaire. Shared by the PWA survey (App.tsx) and the
 * chat-channel handoff page (src/handoff/), so both store identical columns.
 */

export interface M2c2Complete {
  type: string;
  assessment?: string;
  summary?: { n_trials?: number; duration_s?: number | null; correct_count?: number | null };
  data?: unknown;
}

/** Hidden questionnaire used as the tabular sink for cognitive trial rows. */
export const TRIALS_QN_TITLE = 'Cognitive Trials';

export type TrialRow = Record<string, string | number>;

/* eslint-disable @typescript-eslint/no-explicit-any */
export function pickNum(o: any, keys: string[]): number | '' {
  for (const k of keys) {
    const v = o?.[k];
    if (typeof v === 'number') return v;
    if (typeof v === 'string' && v.trim() !== '' && !Number.isNaN(+v)) return +v;
  }
  return '';
}
export function pickStr(o: any, keys: string[]): string {
  for (const k of keys) {
    const v = o?.[k];
    if (v !== undefined && v !== null) return typeof v === 'object' ? JSON.stringify(v) : String(v);
  }
  return '';
}
/* eslint-enable @typescript-eslint/no-explicit-any */

/** A fresh id joining the summary to its trial rows. */
export function newCognitiveSession(): string {
  return (typeof crypto !== 'undefined' && crypto.randomUUID) ? crypto.randomUUID() : `s${Date.now()}`;
}

/** Value stored in the cognitive item's own column. */
export function cognitiveSummaryValue(payload: M2c2Complete, session: string): string {
  return JSON.stringify({ session, ...(payload.summary || {}) });
}

/** The raw trials array of a result ([] when absent). */
export function trialsOf(payload: M2c2Complete): unknown[] {
  const data = payload.data as { trials?: unknown[] } | undefined;
  return Array.isArray(data?.trials) ? data!.trials : [];
}

/** One "Cognitive Trials" row per trial. */
export function cognitiveTrialRows(payload: M2c2Complete, sourceName: string, inputName: string, session: string): TrialRow[] {
  return trialsOf(payload).map((t, i) => ({
    cogAssessment: payload.assessment ?? '',
    cogSource: sourceName,
    cogInputName: inputName,
    cogSession: session,
    cogTrialIndex: i,
    cogRt: pickNum(t, ['response_time_ms', 'rt', 'reaction_time_ms', 'elapsed_test_time_ms']),
    cogCorrect: pickStr(t, ['is_correct', 'correct']),
    cogResponse: pickStr(t, ['response', 'user_response', 'selected', 'tapped']),
    cogStimulus: pickStr(t, ['stimulus', 'condition', 'trial_type']),
    cogRaw: JSON.stringify(t),
  }));
}
