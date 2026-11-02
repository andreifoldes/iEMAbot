/**
 * Rolling-window reminder plan for the native Android app.
 *
 * The WebView has no Web Push, so notifications are scheduled on the device instead: this module turns the
 * study's schedule into the list of upcoming notifications that the native layer hands to the OS alarm service
 * (works offline, survives reboots). The prompts, reminders and what is already done come from the schedule engine
 * (lib/schedule.ts, the port of the server's Schedule.php that drives web push); here they are only grouped like the
 * server does it: several check-ins due in the same minute share one "You have N questionnaires" notification. So a
 * participant on the Android app gets the notification a participant in the browser gets, at the same minute.
 *
 * The plan is re-run whenever the app opens, a questionnaire is completed, or the schedule changes, which rolls the
 * window forward and cancels reminders that a completion made unnecessary.
 */

import type { EsmiraQuestionnaire, ScheduleConfig } from '../types';
import type { Completions } from './availability.ts';
import { coalesce } from './notificationSpec.ts';
import { planOccurrences, type Occurrence } from './schedule.ts';

/** Plan this many days ahead. Short enough to stay accurate after schedule edits. */
export const PLAN_HORIZON_DAYS = 7;
/** Android allows ~500 pending alarms per app; stay far below it. */
export const PLAN_MAX_REMINDERS = 60;

export interface PlannedReminder {
  /** Stable positive 31-bit id for this notification, so re-planning is idempotent. */
  id: number;
  /** Fire time, epoch ms. */
  at: number;
  /**
   * When the notification stops being useful, epoch ms: its check-in window closes (for a coalesced notification,
   * the last of its windows). The phone takes the notification down then (ReminderNotifier, setTimeoutAfter), so
   * last night's prompt is not still on screen the next morning, or after the study has ended.
   */
  deadline: number;
  title: string;
  body: string;
  /** First questionnaire it is about (a coalesced notification can cover several). */
  questionnaireId: number;
  /** The window's key (lib/schedule.ts), against which a completion is recorded. */
  occurrenceKey: string;
  /** Per-occurrence identities (`type:qid:time`, as in web push), for monitoring. */
  keys: string[];
}

export interface PlanStudy {
  id: number;
  title?: string;
  questionnaires: EsmiraQuestionnaire[];
  schedule?: ScheduleConfig;
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

export function planReminders(
  study: PlanStudy,
  joined: number,
  now: number,
  completions: Completions,
  opts: { horizonDays?: number; max?: number; exclude?: (q: EsmiraQuestionnaire) => boolean; userId?: string } = {},
): PlannedReminder[] {
  const horizon = opts.horizonDays ?? PLAN_HORIZON_DAYS;
  const max = opts.max ?? PLAN_MAX_REMINDERS;
  const qs = study.questionnaires.filter((q) => !opts.exclude?.(q));
  const ids = new Set(qs.map((q) => Number(q.internalId)));
  const due: Occurrence[] = planOccurrences({ ...study, questionnaires: qs }, opts.userId ?? '', joined, now, horizon, completions);

  // Group what fires in the same minute (the server sends everything due in one per-minute run as one push).
  const groups = new Map<number, Map<number, Occurrence>>();
  for (const occ of due) {
    if (!ids.has(occ.qid)) continue;
    const minute = Math.floor(occ.timestamp / 60000);
    const group = groups.get(minute) ?? new Map<number, Occurrence>();
    const prev = group.get(occ.qid);
    if (!prev || occ.timestamp > prev.timestamp) group.set(occ.qid, occ); // one per questionnaire, latest wins
    groups.set(minute, group);
  }

  const out: PlannedReminder[] = [];
  for (const group of groups.values()) {
    const items = [...group.values()];
    const keys = items.map((o) => `${o.type}:${o.qid}:${o.timestamp}`);
    const [title, body] = coalesce(items, study);
    out.push({
      id: items.length === 1 ? reminderId(study.id, items[0].qid, keys[0]) : reminderId(study.id, 0, [...keys].sort().join('|')),
      at: Math.max(...items.map((o) => o.timestamp)),
      deadline: Math.max(...items.map((o) => o.deadline)),
      title,
      body,
      questionnaireId: items[0].qid,
      occurrenceKey: items[0].occKey,
      keys,
    });
  }
  return out.sort((a, b) => a.at - b.at).slice(0, max);
}
