/**
 * The participant app's local records for scheduling: the enrolment anchor (day 0 of the schedule), the
 * questionnaires completed on this device (each against the window it was completed in), date helpers, and the
 * chat summary of what is open. What is open, and when, comes from the study's schedule (lib/schedule.ts).
 *
 * Enrollment time (not first-submission time) is the schedule anchor — see ensureEnrollment.
 */

import type { EsmiraQuestionnaire } from '../types';
import type { Availability } from './schedule.ts';
import { t } from './i18n.ts';

const DAY = 86400000;
const MINUTE = 60000;

export interface CompletionRecord { lastAt: number; count: number; occ: Record<string, number>; }
export type Completions = Record<number, CompletionRecord>;

// ── date helpers (mirror ESMira's methods.ts / Scheduler.ts) ──────────────────
export function midnight(ms: number): number { const d = new Date(ms); d.setHours(0, 0, 0, 0); return d.getTime(); }
/** Whole local-day boundaries between two instants (ESMira Scheduler.getDatesDiff). */
export function datesDiff(a: number, b: number): number {
  const da = new Date(a), db = new Date(b);
  const ua = Date.UTC(da.getFullYear(), da.getMonth(), da.getDate());
  const ub = Date.UTC(db.getFullYear(), db.getMonth(), db.getDate());
  return Math.abs(Math.floor((ua - ub) / DAY));
}

function fmtTime(ms: number): string {
  return new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
function fmtDate(ms: number): string {
  return new Date(ms).toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
}
function isToday(ms: number, now: number): boolean { return midnight(ms) === midnight(now); }

/** A chat-friendly summary message for the questionnaire list given current availability. */
export function summarize(
  qs: EsmiraQuestionnaire[], now: number, availability: (q: EsmiraQuestionnaire) => Availability,
): { anyAvailable: boolean; message: string } {
  const avs = qs.map(availability);
  const open = avs.filter((a) => a.state === 'available').length;
  // "Choose" only makes sense when there is a choice; a single open questionnaire is just started.
  if (open === 1) return { anyAvailable: true, message: t('pwa_avail_summary_begin') };
  if (open > 1) return { anyAvailable: true, message: t('pwa_avail_summary_choose') };

  const opens = avs.map((a) => a.opensAt).filter((x): x is number => typeof x === 'number').sort((a, b) => a - b);
  if (opens.length) {
    const at = opens[0];
    // Windows can open mid-minute (e.g. a gap after the last check-in), so one that opens within the minute would
    // read "opens at <the time it is now>". The list shows it as soon as it opens (see App's availability timer).
    if (at - now < MINUTE)
      return { anyAvailable: false, message: t('pwa_avail_summary_moment') };
    if (isToday(at, now))
      return { anyAvailable: false, message: t('pwa_avail_summary_today', fmtTime(at)) };
    return { anyAvailable: false, message: t('pwa_avail_summary_later', fmtDate(at)) };
  }
  if (avs.length && avs.every((a) => a.state === 'ended' || a.state === 'completed'))
    return { anyAvailable: false, message: t('pwa_avail_summary_done') };
  return { anyAvailable: false, message: t('pwa_avail_summary_none') };
}

// ── enrollment anchor + completion store (localStorage) ───────────────────────

function enrollmentKey(studyId: number, userId: string): string { return `esmira_enrolled_${studyId}_${userId}`; }
function legacyJoinedKey(studyId: number, userId: string): string { return `esmira_joined_${studyId}_${userId}`; }
function completionsKey(studyId: number, userId: string): string { return `esmira_qcompletions_${studyId}_${userId}`; }

/** Enrollment timestamp (schedule anchor). Set on first call; reuses a legacy first-submission
 *  time for participants who joined before this gating existed. */
export function ensureEnrollment(studyId: number, userId: string, now: number): number {
  try {
    const existing = localStorage.getItem(enrollmentKey(studyId, userId));
    if (existing) return Number(existing);
    const legacy = localStorage.getItem(legacyJoinedKey(studyId, userId));
    const anchor = legacy ? Number(legacy) : now;
    localStorage.setItem(enrollmentKey(studyId, userId), String(anchor));
    return anchor;
  } catch {
    return now;
  }
}

export function loadCompletions(studyId: number, userId: string): Completions {
  try {
    const raw = localStorage.getItem(completionsKey(studyId, userId));
    const parsed = raw ? JSON.parse(raw) : null;
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

/** Record a completion of questionnaire `qid` at `now` against the window it was started in (`occKey`, from its
 *  availability; it may have closed while answering), bumping count/lastAt. */
export function recordCompletion(studyId: number, userId: string, qid: number, now: number, occKey?: string): void {
  try {
    const completions = loadCompletions(studyId, userId);
    const rec: CompletionRecord = completions[qid] ?? { lastAt: 0, count: 0, occ: {} };
    rec.lastAt = now;
    rec.count += 1;
    rec.occ = rec.occ ?? {};
    if (occKey) rec.occ[occKey] = now;
    completions[qid] = rec;
    localStorage.setItem(completionsKey(studyId, userId), JSON.stringify(completions));
  } catch {
    /* non-fatal */
  }
}
