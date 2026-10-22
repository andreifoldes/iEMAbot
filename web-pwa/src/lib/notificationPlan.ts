/**
 * Rolling-window reminder plan for the native Android app.
 *
 * The WebView has no Web Push, so reminders are scheduled on the device instead: this module
 * turns a study's signal times into a flat list of upcoming notifications that the native layer
 * hands to the OS alarm service (works offline, survives reboots). It is a pure function over
 * the same schedule rules as availability.ts, so the notification a participant gets always
 * matches a questionnaire that is actually open. It is re-run whenever the app opens, a
 * questionnaire is completed, or the schedule changes, which rolls the window forward.
 */

import type { EsmiraQuestionnaire } from '../types';
import { datesDiff, midnight, windowsForDay, type Completions } from './availability.ts';

const DAY = 86400000;
/** Plan this many days ahead. Short enough to stay accurate after schedule edits. */
export const PLAN_HORIZON_DAYS = 7;
/** Android allows ~500 pending alarms per app; stay far below it. */
export const PLAN_MAX_REMINDERS = 60;

export interface PlannedReminder {
  /** Stable positive 31-bit id for this occurrence, so re-planning is idempotent. */
  id: number;
  /** Fire time, epoch ms. */
  at: number;
  title: string;
  body: string;
  questionnaireId: number;
  /** Same key availability.ts uses for "this occurrence is done". */
  occurrenceKey: string;
}

export interface PlanStudy {
  id: number;
  title?: string;
  questionnaires: EsmiraQuestionnaire[];
}

/** FNV-1a, folded to a positive int32 (Android notification ids are Java ints). */
export function reminderId(studyId: number, questionnaireId: number, occurrenceKey: string): number {
  let h = 0x811c9dc5;
  for (const ch of `${studyId}:${questionnaireId}:${occurrenceKey}`) {
    h ^= ch.charCodeAt(0);
    h = Math.imul(h, 0x01000193);
  }
  return (h >>> 0) & 0x7fffffff;
}

function fmtTime(ms: number): string {
  return new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

/** Does any questionnaire in the study have a scheduled signal? (Decides whether to ask for permission.) */
export function hasScheduledSignals(qs: EsmiraQuestionnaire[]): boolean {
  return qs.some((q) => (q.actionTriggers ?? []).some((t) => (t.schedules ?? []).some((s) => (s.signalTimes ?? []).length > 0)));
}

export function planReminders(
  study: PlanStudy,
  joined: number,
  now: number,
  completions: Completions,
  opts: { horizonDays?: number; max?: number; exclude?: (q: EsmiraQuestionnaire) => boolean } = {},
): PlannedReminder[] {
  const horizon = opts.horizonDays ?? PLAN_HORIZON_DAYS;
  const max = opts.max ?? PLAN_MAX_REMINDERS;
  const out = new Map<number, PlannedReminder>();

  for (const q of study.questionnaires) {
    if (opts.exclude?.(q)) continue;
    const rec = completions[q.internalId];
    if (q.completableOnce && rec && rec.count > 0) continue;
    const ds = q.durationStart ?? 0;
    const de = q.durationEnd ?? 0;
    const periodDays = q.durationPeriodDays ?? 0;

    for (let i = 0; i <= horizon; i++) {
      const dayMs = midnight(now) + i * DAY;
      if (periodDays > 0 && datesDiff(dayMs, joined) > periodDays) break;
      const windows = windowsForDay(q, joined, dayMs);
      if (!windows) continue; // passive questionnaire: no reminder to schedule

      for (const w of windows) {
        const at = midnight(dayMs) + w.start;
        if (at <= now) continue;
        if (ds > 0 && at < ds) continue;
        if (de > 0 && at > de) continue;
        if (rec?.occ?.[w.key]) continue; // this occurrence is already answered
        if (q.completableOncePerDay && rec?.lastAt && midnight(rec.lastAt) === midnight(at)) continue;

        const deadline = q.notificationIncludeDeadline && w.end < DAY ? ` Complete by ${fmtTime(midnight(dayMs) + w.end)}.` : '';
        const id = reminderId(study.id, q.internalId, w.key);
        out.set(id, {
          id,
          at,
          title: study.title || 'ESMira',
          body: `Time for “${q.title}”.${deadline}`,
          questionnaireId: q.internalId,
          occurrenceKey: w.key,
        });
      }
    }
  }
  return [...out.values()].sort((a, b) => a.at - b.at).slice(0, max);
}
