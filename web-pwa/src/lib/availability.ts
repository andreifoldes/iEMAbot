/**
 * Schedule-aware questionnaire availability for the participant PWA.
 *
 * Ports the relevant subset of ESMira's gating so participants can only complete a
 * questionnaire when its schedule allows — not "whenever they want":
 *  - Active window:  Questionnaire.isActive (durationStartingAfterDays / durationPeriodDays
 *    / durationStart / durationEnd), using day-boundary math (Scheduler.getDatesDiff).
 *  - Intra-day window: a questionnaire opens at its signal time and closes at
 *    signal + completableMinutesAfterNotification ("Visible For X min") if set, else at
 *    end of day; or the completableAtSpecificTime window when configured.
 *  - Completion limits: completableOnce, limitCompletionFrequency, completableOncePerNotification.
 *
 * Enrollment time (not first-submission time) is the schedule anchor — see ensureEnrollment.
 */

import type { EsmiraQuestionnaire, EsmiraSchedule } from '../types';

const DAY = 86400000;

export type AvailabilityState = 'available' | 'upcoming' | 'ended' | 'completed' | 'locked';

export interface Availability {
  state: AvailabilityState;
  /** When it next opens (epoch ms); set for 'upcoming'/'locked'. */
  opensAt?: number;
  /** Short human reason, e.g. "Opens at 20:00" / "Available on Sat 28 Jun". */
  reason: string;
}

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
/** "Opens at 20:00" if today, else "Opens Sat 28 Jun, 20:00". */
function opensReason(opensAt: number, now: number): string {
  return isToday(opensAt, now) ? `Opens at ${fmtTime(opensAt)}` : `Opens ${fmtDate(opensAt)}, ${fmtTime(opensAt)}`;
}

// ── schedule-day + signal-window computation ──────────────────────────────────

/** Is `dayIndex` (days since enrollment) a scheduled day for this schedule? */
function scheduledOnDay(s: EsmiraSchedule, dayIndex: number, dayMs: number, startAfter: number): boolean {
  const repeat = Math.max(1, s.dailyRepeatRate ?? 1);
  const firstDay = startAfter + (s.skipFirstInLoop ? repeat : 0) + (s.startDayOne ? 1 : 0);
  if (dayIndex < firstDay) return false;
  if ((dayIndex - firstDay) % repeat !== 0) return false;
  const weekdays = s.weekdays ?? 0;
  if (weekdays !== 0 && (weekdays & (1 << new Date(dayMs).getDay())) === 0) return false;
  const dom = s.dayOfMonth ?? 0;
  if (dom !== 0 && new Date(dayMs).getDate() !== dom) return false;
  return true;
}

export interface Window { start: number; end: number; key: string } // start/end = ms since midnight

/** Completion windows for the given local day, or null when the questionnaire has no
 *  schedule at all (a passive questionnaire, completable any time while active). */
export function windowsForDay(q: EsmiraQuestionnaire, joined: number, dayMs: number): Window[] | null {
  const startAfter = q.durationStartingAfterDays ?? 0;
  const dayIndex = datesDiff(dayMs, joined);

  // completableAtSpecificTime overrides signals with a fixed daily window.
  if (q.completableAtSpecificTime) {
    const s = (q.completableAtSpecificTimeStart ?? -1) >= 0 ? (q.completableAtSpecificTimeStart as number) : 0;
    const e = (q.completableAtSpecificTimeEnd ?? -1) >= 0 ? (q.completableAtSpecificTimeEnd as number) : DAY;
    return [{ start: s, end: e, key: `spec:${dayIndex}` }];
  }

  const triggers = q.actionTriggers ?? [];
  const hasAnySignals = triggers.some((t) => (t.schedules ?? []).some((s) => (s.signalTimes ?? []).length > 0));
  if (!hasAnySignals) return null; // passive — gate by duration only

  const timeoutMs = q.completableOncePerNotification && (q.completableMinutesAfterNotification ?? 0) > 0
    ? (q.completableMinutesAfterNotification as number) * 60000
    : 0;

  const out: Window[] = [];
  for (const t of triggers) {
    for (const s of t.schedules ?? []) {
      if (!scheduledOnDay(s, dayIndex, dayMs, startAfter)) continue;
      for (const st of s.signalTimes ?? []) {
        const start = st.startTimeOfDay ?? 0;
        const end = timeoutMs > 0 ? Math.min(DAY, start + timeoutMs) : DAY; // else open until end of day
        out.push({ start, end, key: `${dayIndex}:${start}` });
      }
    }
  }
  out.sort((a, b) => a.start - b.start);
  return out;
}

/** Earliest future opening within the active period (for "opens …" messaging). */
function nextOpening(q: EsmiraQuestionnaire, joined: number, now: number): number | undefined {
  const periodDays = q.durationPeriodDays ?? 0;
  for (let i = 0; i <= 14; i++) {
    const dayMs = midnight(now) + i * DAY;
    const dayIndex = datesDiff(dayMs, joined);
    if (periodDays > 0 && dayIndex > periodDays) break;
    const ws = windowsForDay(q, joined, dayMs);
    if (ws === null) continue;
    for (const w of ws) {
      const at = midnight(dayMs) + w.start;
      if (at > now) return at;
    }
  }
  return undefined;
}

/** Earliest opening on a day strictly after today, within the active period — used for
 *  once-per-day reopen messaging (never returns a later-today window). */
function nextOpeningAfterToday(q: EsmiraQuestionnaire, joined: number, now: number): number | undefined {
  const periodDays = q.durationPeriodDays ?? 0;
  for (let i = 1; i <= 14; i++) {
    const dayMs = midnight(now) + i * DAY;
    const dayIndex = datesDiff(dayMs, joined);
    if (periodDays > 0 && dayIndex > periodDays) break;
    const ws = windowsForDay(q, joined, dayMs);
    if (ws === null) return midnight(dayMs);            // passive — reopens at midnight
    if (ws.length) return midnight(dayMs) + ws[0].start; // first signal window that day
  }
  return undefined;
}

// ── main entry ────────────────────────────────────────────────────────────────

export function computeAvailability(q: EsmiraQuestionnaire, joined: number, now: number, completions: Completions): Availability {
  // Absolute window (durationStart / durationEnd).
  const ds = q.durationStart ?? 0, de = q.durationEnd ?? 0;
  if (ds > 0 && now < ds) return { state: 'upcoming', opensAt: ds, reason: `Available on ${fmtDate(ds)}` };
  if (de > 0 && now > de) return { state: 'ended', reason: 'No longer available' };

  // Day-based window (activation delay + active period), anchored on enrollment.
  const d = datesDiff(now, joined);
  const startAfter = q.durationStartingAfterDays ?? 0;
  const periodDays = q.durationPeriodDays ?? 0;
  if (startAfter > 0 && d < startAfter) {
    const opensAt = midnight(joined) + startAfter * DAY;
    return { state: 'upcoming', opensAt, reason: `Available on ${fmtDate(opensAt)}` };
  }
  if (periodDays > 0 && d > periodDays) return { state: 'ended', reason: 'Study period has ended' };

  const rec = completions[q.internalId];

  // Completion limits.
  if (q.completableOnce && rec && rec.count > 0) return { state: 'completed', reason: 'Completed' };
  // Once per local calendar day: a single completion locks the questionnaire until its
  // next scheduled day, no matter how many signals fire today (and for passive
  // questionnaires with no signals at all). Takes precedence over the intra-day windows.
  if (q.completableOncePerDay && rec?.lastAt && isToday(rec.lastAt, now)) {
    const next = nextOpeningAfterToday(q, joined, now);
    return next ? { state: 'locked', opensAt: next, reason: opensReason(next, now) }
                : { state: 'completed', reason: 'Completed' };
  }
  if (q.limitCompletionFrequency && rec && rec.lastAt) {
    const gap = (q.completionFrequencyMinutes ?? 60) * 60000;
    if (now - rec.lastAt < gap) {
      const opensAt = rec.lastAt + gap;
      return { state: 'locked', opensAt, reason: opensReason(opensAt, now) };
    }
  }

  // Intra-day signal windows.
  const windows = windowsForDay(q, joined, now);
  if (windows === null) return { state: 'available', reason: 'Available now' }; // passive

  const tod = now - midnight(now);
  for (const w of windows) {
    if (tod >= w.start && tod <= w.end) {
      if (rec?.occ?.[w.key]) continue; // this occurrence already done
      return { state: 'available', reason: 'Available now' };
    }
  }

  const nextToday = windows.filter((w) => tod < w.start).map((w) => w.start).sort((a, b) => a - b)[0];
  if (nextToday !== undefined) {
    const opensAt = midnight(now) + nextToday;
    return { state: 'locked', opensAt, reason: opensReason(opensAt, now) };
  }
  const next = nextOpening(q, joined, now);
  return next ? { state: 'locked', opensAt: next, reason: opensReason(next, now) }
              : { state: 'locked', reason: 'Done for today' };
}

/** A chat-friendly summary message for the questionnaire list given current availability. */
export function summarize(qs: EsmiraQuestionnaire[], joined: number, now: number, completions: Completions): { anyAvailable: boolean; message: string } {
  const avs = qs.map((q) => computeAvailability(q, joined, now, completions));
  const open = avs.filter((a) => a.state === 'available').length;
  // "Choose" only makes sense when there is a choice; a single open questionnaire is just started.
  if (open === 1) return { anyAvailable: true, message: 'Tap below to begin.' };
  if (open > 1) return { anyAvailable: true, message: 'Choose a questionnaire to begin.' };

  const opens = avs.map((a) => a.opensAt).filter((x): x is number => typeof x === 'number').sort((a, b) => a - b);
  if (opens.length) {
    const at = opens[0];
    if (isToday(at, now))
      return { anyAvailable: false, message: `Nothing is open right now — your next questionnaire opens at ${fmtTime(at)}. Feel free to close the app; we'll remind you.` };
    return { anyAvailable: false, message: `You're all set. Your study starts on ${fmtDate(at)} — there's nothing to do until then, so you can close the app. We'll remind you when it begins.` };
  }
  if (avs.length && avs.every((a) => a.state === 'ended' || a.state === 'completed'))
    return { anyAvailable: false, message: 'You have completed the study. Thank you for taking part!' };
  return { anyAvailable: false, message: 'There are no questionnaires available right now.' };
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

/** Record a completion of `q` at `now`, marking the current open occurrence (for
 *  once-per-notification) and bumping count/lastAt (for once / frequency limits). */
export function recordCompletion(studyId: number, userId: string, q: EsmiraQuestionnaire, joined: number, now: number): void {
  try {
    const completions = loadCompletions(studyId, userId);
    const rec: CompletionRecord = completions[q.internalId] ?? { lastAt: 0, count: 0, occ: {} };
    rec.lastAt = now;
    rec.count += 1;
    rec.occ = rec.occ ?? {};
    const windows = windowsForDay(q, joined, now);
    if (windows) {
      const tod = now - midnight(now);
      // Mark the occurrence computeAvailability deemed available: the first open
      // window that isn't already completed. Without the occ check, two same-day
      // windows running to end-of-day overlap and the earlier (done) one absorbs
      // the mark, leaving the later one perpetually re-completable.
      const open = windows.find((w) => tod >= w.start && tod <= w.end && !rec.occ[w.key]);
      if (open) rec.occ[open.key] = now;
    }
    completions[q.internalId] = rec;
    localStorage.setItem(completionsKey(studyId, userId), JSON.stringify(completions));
  } catch {
    /* non-fatal */
  }
}
