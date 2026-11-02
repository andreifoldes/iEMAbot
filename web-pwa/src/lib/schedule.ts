/**
 * The study's one schedule (study.schedule) in the participant app, web and Android: which check-in windows are
 * open, when the participant is prompted and when a check-in is done. The list of questionnaires, the Android app's
 * alarms and the "Complete by" texts all come from here; nothing else in the app has scheduling rules.
 *
 * The pure part (normalize … availabilityState) is a line-by-line port of src/backend/schedule/Schedule.php, which
 * drives web push, Telegram and the Android app's server nudges; test/fixtures/channels/schedule.json holds both to
 * the same output, so change them together. See Schedule.php and website/docs/backend/scheduling.md for the rules.
 *
 * The app always computes the windows itself, so it works offline: from the server's last answer (api/schedule.php,
 * cached: the participant's answers the schedule reads, such as their usual sleep times, and the wearable events it
 * reacts to) and the device's own completions. Answering the wake-up check-in marks wake-up and re-plans the rest
 * of the day at once, with or without a connection. Without a cached answer the schedule's defaults are used.
 */

import type { EsmiraQuestionnaire, EsmiraStudy, ScheduleCheckin, ScheduleConfig } from '../types';
import { DEFAULT_BODY, hash32, notificationTitle, withDeadline } from './notificationSpec.ts';
import { datesDiff, midnight, type Completions } from './availability.ts';

const DAY = 86400000;
const MINUTE = 60000;

type Required2<T> = { [K in keyof T]-?: Exclude<T[K], undefined> };
export type NormalizedCheckin = Required2<ScheduleCheckin>;
export interface NormalizedSchedule extends Required2<Omit<ScheduleConfig, 'checkins'>> { checkins: NormalizedCheckin[] }

// Defaults of the omitted-when-default fields; keep in sync with Schedule.php and data/study/Schedule.ts.
const SCHEDULE_DEFAULTS: Required2<Omit<ScheduleConfig, 'checkins'>> = {
  dayStart: 0,
  segments: 0,
  responseMinutes: 60,
  overlap: 'defer',
  cooldownMinutes: 0,
  wakeSensor: false,
  wakeFromCheckin: false,
  wakeProvider: 'any',
  wakeEvent: 'bed_out',
  wakeWindowStart: 18000000,
  wakeWindowEnd: 43200000,
  wakeVariable: '',
  wakeVariableWeekend: '',
  wakeOffsetMin: 0,
  wakeDefault: 28800000,
  bedSensor: false,
  bedProvider: 'any',
  bedEvent: 'bed_in',
  bedWindowStart: 72000000,
  bedWindowEnd: 14400000,
  bedVariable: '',
  bedVariableWeekend: '',
  bedOffsetMin: 0,
  bedDefault: 82800000,
};
const CHECKIN_DEFAULTS: NormalizedCheckin = {
  questionnaireId: -1,
  dayFrom: 0,
  dayUntil: -1,
  everyDays: 1,
  weekdays: 0,
  dayOfMonth: 0,
  dateFrom: 0,
  dateUntil: 0,
  startType: 'split',
  startTime: 32400000,
  startOffsetMin: 0,
  startSegment: 0,
  startNextDay: false,
  endType: 'split',
  endTime: 39600000,
  endOffsetMin: 0,
  endSegment: 1,
  endNextDay: false,
  prompt: 'start',
  promptGraceMin: 0,
  eventProvider: 'any',
  eventKind: 'bed_out',
  eventFrom: -1,
  eventUntil: -1,
  eventDelayMin: 0,
  eventDelayMaxMin: 0,
  fallback: 'fixed',
  fallbackTime: 43200000,
  fallbackVariable: '',
  fallbackOffsetMin: 0,
  fallbackDefault: true,
  available: 'prompt',
  responseOverride: false,
  responseMinutes: 60,
  limit: 'window',
  minGapMinutes: 0,
  parallel: false,
  autoStart: false,
  title: '',
  text: '',
  reminderCount: 0,
  reminderDelayMin: 5,
  includeDeadline: false,
};

/** One window, in ms: times of day in windowsForDay, absolute in ScheduleDay. It can be answered from `open`
 *  until `close` and is prompted at `prompt` when `notify` is set. */
export interface DayWindow {
  checkin: number; qid: number; start: number; end: number; open: number; prompt: number; close: number; notify: boolean; early: boolean;
}
/** An absolute window with its key (`YYYY-MM-DD:<check-in>`), its local day (`base`) and study day [dayFrom, dayTo). */
export interface AbsWindow extends DayWindow {
  key: string; base: number; dayFrom: number; dayTo: number;
  /** Set by applyCompletions: done by the check-in's limit, and the questionnaire completed since it opened. */
  done?: boolean; answered?: boolean;
}
export interface ScheduleDay { date: string; index: number; wake: number | null; wakeBy: number | null; bed: number; windows: AbsWindow[] }
/** A completion of a questionnaire; `key` is the window it was completed in, when known. */
export interface Completion { qid: number; t: number; key?: string | null }

/** The day a pure window calculation needs (see Schedule::windowsForDay). */
export interface DayInfo {
  index: number;
  weekday: number;
  dayOfMonth: number;
  base?: number;
  wake: number | null;
  wakeBy: number | null;
  wakeSeen?: number | null;
  bed: number;
  bedEvents?: number[];
  wakeNext?: number | null;
  eventPrompts?: Record<number, number | null>;
}

function pick<T extends object>(defaults: T, raw: object): T {
  const out = { ...defaults } as Record<string, unknown>;
  for (const k of Object.keys(defaults)) {
    if (Object.prototype.hasOwnProperty.call(raw, k)) out[k] = (raw as Record<string, unknown>)[k];
  }
  return out as T;
}

export function normalize(raw: ScheduleConfig): NormalizedSchedule {
  const out = { ...pick(SCHEDULE_DEFAULTS, raw), checkins: (raw.checkins ?? []).filter((c) => c && typeof c === 'object').map((c) => pick(CHECKIN_DEFAULTS, c)) };
  const dayStart = Math.trunc(Number(out.dayStart));
  out.dayStart = dayStart > 0 && dayStart < DAY / 2 ? dayStart : 0;
  return out;
}

/** The study's schedule with defaults filled in, or null without check-ins. */
export function scheduleConfig(study: Pick<EsmiraStudy, 'schedule'>): NormalizedSchedule | null {
  const raw = study.schedule;
  if (!raw || typeof raw !== 'object') return null;
  const schedule = normalize(raw);
  return schedule.checkins.length ? schedule : null;
}

/** The study day start (ms after local midnight), 0 without a schedule. */
export function studyDayStart(study: Pick<EsmiraStudy, 'schedule'>): number {
  return scheduleConfig(study)?.dayStart ?? 0;
}

/** Does any check-in prompt? (Decides whether to ask for the notification permission.) */
export function hasPrompts(study: Pick<EsmiraStudy, 'schedule'>): boolean {
  return (scheduleConfig(study)?.checkins ?? []).some((c) => c.prompt !== 'none');
}

/** Questionnaires the app starts by itself while they are open (`autoStart`). */
export function autoStartIds(study: Pick<EsmiraStudy, 'schedule'>): Set<number> {
  return new Set((scheduleConfig(study)?.checkins ?? []).filter((c) => c.autoStart).map((c) => Math.trunc(Number(c.questionnaireId))));
}

/** The length of the study in days for "Day N of M": the latest `dayUntil` of its check-ins, 0 when none ends. */
/**
 * The study's days, from its prompted check-ins (all of them if none prompts; a one-off questionnaire such as the
 * day-zero sleep times is open any time and not part of them): the first and the last day index, counted from the
 * enrolment day (day 0); `last` is -1 when the study has no end.
 */
export function studyPeriod(study: Pick<EsmiraStudy, 'schedule'>): { first: number; last: number } | null {
  const checkins = scheduleConfig(study)?.checkins ?? [];
  if (!checkins.length) return null;
  const prompted = checkins.filter((c) => c.prompt !== 'none');
  const days = prompted.length ? prompted : checkins;
  const first = Math.min(...days.map((c) => Math.max(0, Math.trunc(Number(c.dayFrom)))));
  const untils = days.map((c) => Math.trunc(Number(c.dayUntil)));
  return { first, last: untils.some((u) => u < 0) ? -1 : Math.max(first, ...untils) };
}

/**
 * The header's study day: "Day 2 of 3", counted from the first study day. Before it, the enrolment day is day 0:
 * "Day 0 · Starts Tue, 3 Nov" (just "Starts …" on the days after it). `startsAt` (when the first questionnaire opens,
 * if they are all still to come) overrides the start computed from the study days. Null after the last day.
 */
export function studyDayLabel(study: Pick<EsmiraStudy, 'schedule'>, joined: number, now: number, startsAt: number | null = null): string | null {
  const period = studyPeriod(study);
  if (!period) return null;
  const index = now >= midnight(joined) ? datesDiff(now, joined) : 0;
  if (startsAt !== null || index < period.first) {
    const start = startsAt ?? midnight(midnight(joined) + period.first * DAY + DAY / 2);
    return index === 0 ? `Day 0 · Starts ${fmtDate(start)}` : `Starts ${fmtDate(start)}`;
  }
  const day = index - period.first + 1;
  if (period.last < 0) return `Day ${day}`;
  const total = period.last - period.first + 1;
  return day <= total ? `Day ${day} of ${total}` : null;
}

/** The wake-up check-in: the first one starting right at wake-up (`wake` + 0 or split point 0), or -1. */
export function wakeCheckinIndex(schedule: NormalizedSchedule): number {
  if (!schedule.wakeFromCheckin) return -1;
  return schedule.checkins.findIndex((c) =>
    (c.startType === 'wake' && Math.trunc(Number(c.startOffsetMin)) === 0)
    || (c.startType === 'split' && Math.trunc(Number(c.startSegment)) === 0));
}

/** Does a check-in depend on wearable events, which only the server receives (so it prompts it, not the device)? */
export function needsServer(schedule: NormalizedSchedule, index: number): boolean {
  const c = schedule.checkins[index];
  if (!c) return false;
  if (c.prompt === 'event') return true;
  if (schedule.wakeSensor && (c.prompt === 'wake' || ['wake', 'split'].includes(c.startType) || ['wake', 'split'].includes(c.endType))) return true;
  return !!schedule.bedSensor && (c.startType === 'bed' || c.endType === 'bed');
}

// ── pure part (ported from Schedule.php) ─────────────────────────────────────────────────────────

/** "HH:MM" / "HH:MM:SS" as ms since midnight, else null (Schedule::parseTimeOfDay). */
export function parseTimeOfDay(value: unknown): number | null {
  const m = /^(\d{1,2}):(\d{2})(?::(\d{2}))?$/.exec(String(value ?? '').trim());
  if (!m) return null;
  const [h, min, sec] = [Number(m[1]), Number(m[2]), Number(m[3] ?? 0)];
  return h < 24 && min < 60 && sec < 60 ? ((h * 60 + min) * 60 + sec) * 1000 : null;
}

export type Variables = Record<string, string | { v: string; t?: number }>;

function variableTod(name: string, offsetMin: number, variables: Variables): number | null {
  const n = name.trim();
  if (n === '') return null;
  const entry = variables[n];
  const tod = parseTimeOfDay(typeof entry === 'object' && entry !== null ? entry.v : entry);
  if (tod === null) return null;
  return (((tod + Math.trunc(offsetMin) * MINUTE) % DAY) + DAY) % DAY;
}

/** The usual wake-up: participant variable + offset (on a weekend day the weekend one first), else the default. */
export function wakeFallbackTod(schedule: NormalizedSchedule, variables: Variables, weekend = false): number | null {
  const tod = (weekend ? variableTod(String(schedule.wakeVariableWeekend), Number(schedule.wakeOffsetMin), variables) : null)
    ?? variableTod(String(schedule.wakeVariable), Number(schedule.wakeOffsetMin), variables);
  if (tod !== null) return tod;
  return Number(schedule.wakeDefault) >= 0 ? Math.trunc(Number(schedule.wakeDefault)) : null;
}

/** The planned bedtime: participant variable + offset (on Friday and Saturday nights the weekend one first), else the default. */
export function resolveBedTod(schedule: NormalizedSchedule, variables: Variables, weekendNight = false): number {
  const tod = (weekendNight ? variableTod(String(schedule.bedVariableWeekend), Number(schedule.bedOffsetMin), variables) : null)
    ?? variableTod(String(schedule.bedVariable), Number(schedule.bedOffsetMin), variables);
  return tod ?? Math.max(0, Math.trunc(Number(schedule.bedDefault)));
}

/** Wake-up time of one day, or null while unknown (Schedule::resolveWakeTod). */
export function resolveWakeTod(schedule: NormalizedSchedule, observedTods: number[], fallback: number | null, nowTod: number): number | null {
  if (!schedule.wakeSensor && !schedule.wakeFromCheckin) return fallback;
  const first = firstObservedWake(schedule, observedTods);
  if (first !== null && (fallback === null || first <= fallback)) return first;
  if (fallback !== null && nowTod >= fallback) return fallback;
  return null;
}

/** The earliest observed wake-up inside the wake-up window (whole minutes), or null when none is (yet). */
export function firstObservedWake(schedule: NormalizedSchedule, observedTods: number[]): number | null {
  let first: number | null = null;
  for (const tod of observedTods) {
    if (inWindow(Math.trunc(Number(schedule.wakeWindowStart)), Math.trunc(Number(schedule.wakeWindowEnd)), tod) && (first === null || tod < first)) first = tod;
  }
  return first === null ? null : Math.trunc(first / MINUTE) * MINUTE;
}

/** An event prompt's fallback time of day (Schedule::eventFallbackTod). */
export function eventFallbackTod(c: NormalizedCheckin, variables: Variables): number | null {
  const fixed = Number(c.fallbackTime) >= 0 ? Math.trunc(Number(c.fallbackTime)) : null;
  if (c.fallback === 'none') return null;
  if (c.fallback === 'variable') {
    const tod = variableTod(String(c.fallbackVariable), Number(c.fallbackOffsetMin), variables);
    return tod ?? (c.fallbackDefault ? fixed : null);
  }
  return fixed;
}

/** When an event check-in is prompted on one day (Schedule::resolveEventTod). */
export function resolveEventTod(c: NormalizedCheckin, eventTods: number[], fallback: number | null, nowTod: number, seed: string): number | null {
  let first: number | null = null;
  for (const tod of eventTods) {
    if (inWindow(Math.trunc(Number(c.eventFrom)), Math.trunc(Number(c.eventUntil)), tod) && (first === null || tod < first)) first = tod;
  }
  if (first !== null && (fallback === null || first <= fallback)) {
    const min = Math.max(0, Math.trunc(Number(c.eventDelayMin)));
    const max = Math.trunc(Number(c.eventDelayMaxMin));
    const delay = max > min ? min + (hash32(`${seed}:delay`) % (max - min + 1)) : min;
    return Math.trunc(first / MINUTE) * MINUTE + delay * MINUTE;
  }
  if (fallback !== null && nowTod >= fallback) return fallback;
  return null;
}

function inWindow(start: number, end: number, tod: number): boolean {
  if (start < 0 || end < 0) return true;
  return start <= end ? tod >= start && tod <= end : tod >= start || tod <= end;
}

/** When the participant went to bed, relative to the day's midnight (Schedule::bedEndTod). */
export function bedEndTod(schedule: NormalizedSchedule, eventTods: number[], wake: number | null, bedAdj: number): number {
  if (!schedule.bedSensor) return bedAdj;
  const start = Math.trunc(Number(schedule.bedWindowStart));
  let end = Math.trunc(Number(schedule.bedWindowEnd));
  if (end <= start) end += DAY;
  let first: number | null = null;
  for (const tod of eventTods) {
    if ((start < 0 || (tod >= start && tod <= end)) && tod >= (wake ?? 0) && tod <= bedAdj && (first === null || tod < first)) first = tod;
  }
  return first === null ? bedAdj : Math.trunc(first / MINUTE) * MINUTE;
}

function wakePromptTod(c: NormalizedCheckin, seen: number | null, wakeBy: number | null): number | null {
  const fallback = wakeBy === null ? null : wakeBy + Math.trunc(Number(c.promptGraceMin)) * MINUTE;
  const at = seen !== null && (fallback === null || seen <= fallback) ? seen : fallback;
  return at === null ? null : Math.trunc(at / MINUTE) * MINUTE;
}

/** Does a check-in run on this day? (Schedule::runsOnDay) */
export function runsOnDay(c: NormalizedCheckin, day: Pick<DayInfo, 'index' | 'weekday' | 'dayOfMonth'>): boolean {
  const from = Math.trunc(Number(c.dayFrom)), until = Math.trunc(Number(c.dayUntil));
  if (day.index < from || (until >= 0 && day.index > until)) return false;
  if ((day.index - from) % Math.max(1, Math.trunc(Number(c.everyDays))) !== 0) return false;
  const weekdays = Math.trunc(Number(c.weekdays));
  if (weekdays !== 0 && (weekdays & (1 << day.weekday)) === 0) return false;
  const dom = Math.trunc(Number(c.dayOfMonth));
  return dom === 0 || dom === day.dayOfMonth;
}

interface BoundaryContext { wake: number | null; wakeBy: number | null; segments: number; bedAdj: number; bedEnd: number; wakeNext: number | null }

function boundary(c: NormalizedCheckin, side: 'start' | 'end', ctx: BoundaryContext): number | null {
  const offset = Math.trunc(Number(c[`${side}OffsetMin`])) * MINUTE;
  const nextDay = !!c[`${side}NextDay`];
  let wake = ctx.wake;
  let value: number;
  switch (c[`${side}Type`]) {
    case 'fixed':
      value = Math.trunc(Number(c[`${side}Time`]));
      if (value < 0) return null;
      value += nextDay ? DAY : 0;
      break;
    case 'wake':
      if (nextDay) wake = ctx.wakeNext === null ? null : DAY + ctx.wakeNext;
      if (wake === null) return null;
      value = wake + offset;
      break;
    case 'usualWake':
      if (ctx.wakeBy === null) return null;
      value = ctx.wakeBy + offset + (nextDay ? DAY : 0);
      break;
    case 'bed':
      value = ctx.bedEnd + offset;
      break;
    default: // split
      if (wake === null) return null;
      value = wake + Math.trunc(((ctx.bedAdj - wake) * Math.trunc(Number(c[`${side}Segment`]))) / ctx.segments);
  }
  return Math.trunc(value / MINUTE) * MINUTE;
}

/** The day's windows (times of day), ordered by when they open (Schedule::windowsForDay). */
export function windowsForDay(schedule: NormalizedSchedule, day: DayInfo, seed: string): DayWindow[] {
  const { wake, wakeBy, bed } = day;
  const base = day.base ?? 0;
  const checkins = schedule.checkins;
  const overlap = schedule.overlap;
  const dayEnd = DAY + Math.trunc(Number(schedule.dayStart));
  const bedAdj = (wake !== null ? bed <= wake : bed < DAY / 2) ? bed + DAY : bed;
  const ctx: BoundaryContext = {
    wake,
    wakeBy,
    segments: Number(schedule.segments) > 0 ? Math.trunc(Number(schedule.segments)) : checkins.length,
    bedAdj,
    bedEnd: bedEndTod(schedule, day.bedEvents ?? [], wake, bedAdj),
    wakeNext: day.wakeNext ?? wakeBy, // not known yet: the latest it can be
  };

  const ranges: { checkin: number; start: number; end: number }[] = [];
  checkins.forEach((c, i) => {
    if (!runsOnDay(c, day)) return;
    const start = boundary(c, 'start', ctx);
    let end = boundary(c, 'end', ctx);
    if (start === null || end === null) return;
    // A clock time before the start is the next day's; any other end leaves no range.
    if (end < start) end = c.endType === 'fixed' && !c.endNextDay ? end + DAY : start;
    ranges.push({ checkin: i, start, end });
  });
  ranges.sort((a, b) => a.start - b.start || a.checkin - b.checkin);

  const out: DayWindow[] = [];
  let prevClose: number | null = null;
  for (const { checkin: i, start, end } of ranges) {
    const c = checkins[i];
    const parallel = !!c.parallel;
    // 'defer': a check-in opens no earlier than the previous one's window closes.
    const earliest: number = !parallel && overlap === 'defer' && prevClose !== null ? Math.max(start, prevClose) : start;
    const last = Math.max(end, earliest);
    let prompt: number = earliest;
    let notify = c.prompt !== 'none';
    let at: number | null = earliest;
    if (c.prompt === 'random') {
      const minutes = Math.trunc((last - earliest) / MINUTE);
      at = earliest + (minutes > 0 ? (hash32(`${seed}:${i}`) % (minutes + 1)) * MINUTE : 0);
    } else if (c.prompt === 'wake') {
      at = wakePromptTod(c, day.wakeSeen ?? null, wakeBy);
    } else if (c.prompt === 'event') {
      at = day.eventPrompts?.[i] ?? null;
    }
    if (at === null) { // not known yet: only a window open throughout its range exists, unprompted
      if (c.available !== 'range') continue;
      notify = false;
    } else {
      prompt = Math.min(Math.max(at, earliest), last);
    }
    let open: number, close: number;
    if (c.available === 'range') {
      open = earliest;
      close = last;
    } else if (c.available === 'day') {
      open = prompt;
      close = dayEnd;
    } else {
      const response = c.responseOverride ? Math.trunc(Number(c.responseMinutes)) : Math.trunc(Number(schedule.responseMinutes));
      open = prompt;
      close = response > 0 ? prompt + response * MINUTE : last;
    }
    if ((Number(c.dateFrom) > 0 && base + open < Number(c.dateFrom)) || (Number(c.dateUntil) > 0 && base + open > Number(c.dateUntil))) continue;
    if (!parallel) prevClose = prevClose === null ? close : Math.max(prevClose, close);
    out.push({ checkin: i, qid: Math.trunc(Number(c.questionnaireId)), start, end, open, prompt, close, notify, early: false });
  }
  const wakeCheckin = wakeCheckinIndex(schedule);
  if (wake === null && wakeCheckin >= 0 && runsOnDay(checkins[wakeCheckin], day)) {
    const start = Math.max(0, Math.trunc(Number(schedule.wakeWindowStart)));
    let close = wakeBy ?? (Number(schedule.wakeWindowEnd) >= 0 ? Math.trunc(Number(schedule.wakeWindowEnd)) : DAY);
    if (close < start) close += DAY;
    out.push({
      checkin: wakeCheckin, qid: Math.trunc(Number(checkins[wakeCheckin].questionnaireId)), start, end: close,
      open: start, prompt: start, close, notify: false, early: true,
    });
  }
  out.sort((a, b) => a.open - b.open || a.checkin - b.checkin);
  // One check-in at a time: a window closes when the next one opens; parallel check-ins are left out.
  let next: number | null = null;
  for (let k = out.length - 1; k >= 0; k--) {
    if (checkins[out[k].checkin].parallel) continue;
    if (next !== null) out[k].close = Math.min(out[k].close, next);
    next = out[k].open;
  }
  return closed(out);
}

/** Drops windows left without time and the prompts that would come once a window has closed. */
function closed<W extends { open: number; prompt: number; close: number; notify: boolean }>(windows: W[]): W[] {
  return windows.filter((w) => w.close > w.open).map((w) => ({ ...w, notify: w.notify && w.prompt < w.close }));
}

/** One check-in at a time across midnight too (Schedule::capAcrossDays). */
export function capAcrossDays<W extends DayWindow>(schedule: NormalizedSchedule, earlier: W[], later: W[]): W[] {
  const exclusive = (w: W) => !schedule.checkins[w.checkin]?.parallel;
  const opens = later.filter(exclusive).map((w) => w.open);
  if (!opens.length) return earlier;
  const first = Math.min(...opens);
  return closed(earlier.map((w) => (exclusive(w) ? { ...w, close: Math.min(w.close, first) } : w)));
}

/** Applies completions to absolute windows: done, answered, the cool-down and the minimum gap (Schedule::applyCompletions). */
export function applyCompletions(schedule: NormalizedSchedule, windows: AbsWindow[], completions: Completion[]): AbsWindow[] {
  const cooldown = Math.max(0, Math.trunc(Number(schedule.cooldownMinutes))) * MINUTE;
  const exclusive = new Set(schedule.checkins.filter((c) => !c.parallel).map((c) => Math.trunc(Number(c.questionnaireId))));
  // Completions of a window: by its key, and each completion without a key for one window it falls into.
  const claimed = new Set<number>();
  windows.forEach((w, k) => {
    if (completions.some((c) => c.qid === w.qid && (c.key ?? null) === w.key)) claimed.add(k);
  });
  const order = windows.map((_, k) => k).sort((a, b) => windows[a].open - windows[b].open || windows[a].checkin - windows[b].checkin);
  for (const completion of completions.filter((c) => (c.key ?? null) === null).sort((a, b) => a.t - b.t)) {
    const k = order.find((i) => !claimed.has(i) && completion.qid === windows[i].qid && completion.t >= windows[i].open && completion.t < windows[i].close);
    if (k !== undefined) claimed.add(k);
  }
  return closed(windows.map((w, index) => {
    const c = schedule.checkins[w.checkin];
    const limit = c.limit;
    const gap = Math.max(0, Math.trunc(Number(c.minGapMinutes))) * MINUTE;
    const cooled = cooldown > 0 && !c.parallel;
    let done = false;
    let answered = false;
    let from = w.open;
    for (const completion of completions) {
      const t = completion.t;
      const same = completion.qid === w.qid;
      if (same) {
        if (limit === 'once'
          || (limit === 'day' && t >= w.dayFrom && t < w.dayTo)
          || (limit === 'window' && claimed.has(index))) done = true;
        if (t >= w.open) answered = true;
        if (gap > 0 && t < w.close) from = Math.max(from, t + gap);
      }
      if (cooled && exclusive.has(completion.qid) && t < w.close && (!same || t < w.open)) from = Math.max(from, t + cooldown);
    }
    const out: AbsWindow = { ...w, done, answered: answered || done };
    if (from > w.open) {
      out.open = from;
      out.prompt = Math.max(w.prompt, from);
    }
    return out;
  }));
}

export type AvailabilityState = 'available' | 'upcoming' | 'ended' | 'completed' | 'locked';

/** A questionnaire's state from windows with completions applied (Schedule::availability). */
export function availabilityState(
  schedule: NormalizedSchedule, qid: number, windows: AbsWindow[], now: number, dayIndex: number,
): { state: AvailabilityState; occKey: string | null; opensAt: number | null } {
  const mine = windows.filter((w) => w.qid === qid).sort((a, b) => a.open - b.open);
  const open = mine.find((w) => now >= w.open && now < w.close && !w.done);
  if (open) return { state: 'available', occKey: open.key, opensAt: null };
  const checkins = schedule.checkins.filter((c) => Math.trunc(Number(c.questionnaireId)) === qid);
  if (!checkins.length) return { state: 'locked', occKey: null, opensAt: null };
  const next = mine.find((w) => w.open > now && !w.done)?.open ?? null;
  let once = true, before = true, after = true;
  for (const c of checkins) {
    once = once && c.limit === 'once';
    before = before && (dayIndex < Number(c.dayFrom) || (Number(c.dateFrom) > 0 && now < Number(c.dateFrom)));
    after = after && ((Number(c.dayUntil) >= 0 && dayIndex > Number(c.dayUntil)) || (Number(c.dateUntil) > 0 && now > Number(c.dateUntil)));
  }
  if (before) return { state: 'upcoming', occKey: null, opensAt: next };
  if (next !== null) return { state: 'locked', occKey: null, opensAt: next };
  if (once && mine.some((w) => w.done)) return { state: 'completed', occKey: null, opensAt: null };
  if (after) return { state: 'ended', occKey: null, opensAt: null };
  return { state: 'locked', occKey: null, opensAt: null };
}

// ── in the app ───────────────────────────────────────────────────────────────

/** Local calendar date of an instant, YYYY-MM-DD (the server's `date`). */
export function localDate(ms: number): string {
  const d = new Date(ms);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** The server's last answer (api/schedule.php), cached for offline use. */
export interface ServerInputs {
  /** The participant's answers the schedule reads (usual sleep times, event fallbacks). */
  variables?: Variables;
  /** Wearable events the schedule reacts to, absolute ms. */
  events?: { t: number; provider: string; kind: string }[];
}

/** The device's completions as the engine's list: each window completed (`occ`), plus older ones without a key. */
export function deviceCompletions(completions: Completions): Completion[] {
  const out: Completion[] = [];
  for (const [qidStr, rec] of Object.entries(completions)) {
    const qid = Number(qidStr);
    const occ = Object.entries(rec?.occ ?? {}).filter(([, t]) => typeof t === 'number');
    for (const [key, t] of occ) out.push({ qid, t, key });
    if (rec && rec.count > occ.length && rec.lastAt > 0 && !occ.some(([, t]) => t === rec.lastAt)) out.push({ qid, t: rec.lastAt });
  }
  return out.sort((a, b) => a.t - b.t);
}

/**
 * One day's windows, computed on the device (Schedule::days for one day): local midnight `dayMs`, the day index
 * counted from the enrolment day, the participant's answers and wearable events from the server's last answer,
 * and wake-up also from this device's completions of the wake-up check-in. `assumeWake` plans a day whose wake-up
 * or event is not known yet as if it came at the fallback (prompts planned ahead).
 */
function computeDay(
  schedule: NormalizedSchedule, studyId: number, userId: string, dayMs: number, joined: number, now: number,
  server: ServerInputs | null, completions: Completion[], assumeWake: boolean,
): ScheduleDay {
  const variables = server?.variables ?? {};
  const events = server?.events ?? [];
  const date = localDate(dayMs);
  const nextDay = midnight(dayMs + DAY + DAY / 2);
  const wakeByOf = (ms: number): number | null => wakeFallbackTod(schedule, variables, [0, 6].includes(new Date(ms).getDay()));
  const matches = (provider: string, kind: string, wantedProvider: string, wantedKind: string) => kind === wantedKind && (wantedProvider === 'any' || wantedProvider === provider);
  const wakeEvents = schedule.wakeSensor ? events.filter((e) => matches(e.provider, e.kind, schedule.wakeProvider, schedule.wakeEvent)).map((e) => e.t) : [];
  const bedEvents = schedule.bedSensor ? events.filter((e) => matches(e.provider, e.kind, schedule.bedProvider, schedule.bedEvent)).map((e) => e.t) : [];
  const observed = [...wakeEvents];
  const wakeCheckin = wakeCheckinIndex(schedule);
  if (wakeCheckin >= 0) {
    const wakeQid = Math.trunc(Number(schedule.checkins[wakeCheckin].questionnaireId));
    for (const c of completions) if (c.qid === wakeQid) observed.push(c.t);
  }
  const relative = (times: number[], ms: number, from: number, to: number) => times.map((t) => t - ms).filter((rel) => rel >= from && rel < to);
  const wakeOf = (ms: number): number | null =>
    resolveWakeTod(schedule, relative(observed, ms, 0, DAY), wakeByOf(ms), now - ms) ?? (assumeWake ? wakeByOf(ms) : null);
  const nowTod = assumeWake ? 2 * DAY : now - dayMs;
  const eventPrompts: Record<number, number | null> = {};
  schedule.checkins.forEach((c, i) => {
    if (c.prompt !== 'event') return;
    const times = events.filter((e) => matches(e.provider, e.kind, c.eventProvider, c.eventKind)).map((e) => e.t);
    eventPrompts[i] = resolveEventTod(c, relative(times, dayMs, 0, DAY), eventFallbackTod(c, variables), nowTod, `${studyId}:${userId}:${date}:${i}`);
  });
  const wake = wakeOf(dayMs);
  const wakeBy = wakeByOf(dayMs);
  const bed = resolveBedTod(schedule, variables, [5, 6].includes(new Date(dayMs).getDay()));
  const day: DayInfo = {
    index: dayMs >= midnight(joined) ? datesDiff(dayMs, joined) : -datesDiff(dayMs, joined),
    weekday: new Date(dayMs).getDay(),
    dayOfMonth: new Date(dayMs).getDate(),
    base: dayMs,
    wake, wakeBy, bed,
    wakeSeen: firstObservedWake(schedule, relative(wakeEvents, dayMs, 0, DAY)),
    bedEvents: relative(bedEvents, dayMs, 0, 2 * DAY),
    wakeNext: wakeOf(nextDay) ?? wakeByOf(nextDay), // not known yet: its fallback
    eventPrompts,
  };
  const dayStart = Math.trunc(Number(schedule.dayStart));
  return {
    date,
    index: day.index,
    wake: wake === null ? null : dayMs + wake,
    wakeBy: wakeBy === null ? null : dayMs + wakeBy,
    bed: dayMs + bed + ((wake !== null ? bed <= wake : bed < DAY / 2) ? DAY : 0),
    windows: windowsForDay(schedule, day, `${studyId}:${userId}:${date}`).map((w) => {
      // The study day the window opens in (a window opening at 02:00 with days from 04:00 is the evening before's).
      const dayFrom = midnight(dayMs + w.open - dayStart) + dayStart;
      return {
        ...w, key: `${date}:${w.checkin}`, base: dayMs, dayFrom, dayTo: midnight(dayFrom + DAY + DAY / 2 - dayStart) + dayStart,
        start: dayMs + w.start, end: dayMs + w.end, open: dayMs + w.open, prompt: dayMs + w.prompt, close: dayMs + w.close,
      };
    }),
  };
}

/**
 * The participant's days from `fromDay` to `toDay` (relative to today), computed on the device, with completions
 * applied. Days after today are planned with an assumed wake-up (their fallback); with `assumeWake` every day is.
 */
export function localDays(
  study: Pick<EsmiraStudy, 'id' | 'schedule'>, userId: string, joined: number, now: number, server: ServerInputs | null,
  completions: Completion[], fromDay = -1, toDay = 0, assumeWake = false,
): ScheduleDay[] {
  const schedule = scheduleConfig(study);
  if (!schedule) return [];
  const today = midnight(now);
  const days: ScheduleDay[] = [];
  for (let d = fromDay; d <= toDay; d++) {
    const dayMs = midnight(today + d * DAY + DAY / 2);
    days.push(computeDay(schedule, study.id, userId, dayMs, joined, now, server, completions, assumeWake || d > 0));
  }
  for (let k = 0; k + 1 < days.length; k++) days[k] = { ...days[k], windows: capAcrossDays(schedule, days[k].windows, days[k + 1].windows) };
  return days.map((day) => ({ ...day, windows: applyCompletions(schedule, day.windows, completions) }));
}

/** Title, body and reminders of a check-in's notification for a window (Schedule::notification). */
export function notification(
  study: { title?: string; questionnaires: EsmiraQuestionnaire[] }, schedule: NormalizedSchedule, w: AbsWindow,
): { title: string; body: string; reminderCount: number; reminderDelayMs: number } {
  const c = schedule.checkins[w.checkin];
  const q = study.questionnaires.find((x) => Number(x.internalId) === w.qid);
  const title = String(c.title).trim() !== '' ? String(c.title).trim() : notificationTitle(q ?? { title: '' }, study);
  let body = String(c.text).trim() !== '' ? String(c.text).trim() : DEFAULT_BODY;
  if (c.includeDeadline) {
    // The close relative to the window's day: midnight shows as 23:59, a close after midnight as that time.
    const closeTod = w.close - w.base;
    body = withDeadline(body, closeTod > DAY ? closeTod - DAY : closeTod);
  }
  const delay = Math.max(0, Math.trunc(Number(c.reminderDelayMin))) * MINUTE;
  return { title, body, reminderCount: delay > 0 ? Math.max(0, Math.trunc(Number(c.reminderCount))) : 0, reminderDelayMs: delay };
}

export interface Occurrence {
  type: 'availability' | 'reminder';
  qid: number;
  title: string;
  body: string;
  timestamp: number;
  /** When its window opened: completed since then = this check-in done. */
  windowStart: number;
  deadline: number;
  occKey: string;
}

/**
 * The prompts and reminders from now to the end of the day `horizonDays` after today, planned on the device for
 * the Android app (notificationPlan.ts), so they fire on the minute and offline. Windows that are done are left out,
 * and so are check-ins that depend on wearable events, which the server prompts (api/native_pending.php).
 */
export function planOccurrences(
  study: { id: number; title?: string; schedule?: ScheduleConfig; questionnaires: EsmiraQuestionnaire[] }, userId: string, joined: number, now: number,
  horizonDays: number, completions: Completions,
): Occurrence[] {
  const schedule = scheduleConfig(study);
  if (!schedule) return [];
  const until = midnight(midnight(now) + (horizonDays + 1) * DAY + DAY / 2); // end of the last day
  const days = localDays(study, userId, joined, now, loadServerInputs(study.id, userId), deviceCompletions(completions), -1, horizonDays + 1, true);
  const out: Occurrence[] = [];
  for (const w of days.slice(0, -1).flatMap((d) => d.windows)) { // the extra day only closes windows that run into it
    if (!w.notify || w.done || needsServer(schedule, w.checkin)) continue;
    const n = notification(study, schedule, w);
    const base = { qid: w.qid, title: n.title, body: n.body, windowStart: w.open, deadline: w.close, occKey: w.key };
    if (w.prompt > now && w.prompt <= until) out.push({ ...base, type: 'availability', timestamp: w.prompt });
    for (let k = 1; k <= n.reminderCount; k++) {
      const rt = w.prompt + k * n.reminderDelayMs;
      if (rt > now && rt <= until && rt < w.close && !w.answered) out.push({ ...base, type: 'reminder', timestamp: rt });
    }
  }
  return out;
}

/** What the participant app shows for a questionnaire. */
export interface Availability {
  state: AvailabilityState;
  /** When it next opens (epoch ms); set for 'upcoming'/'locked' when known. */
  opensAt?: number;
  /** Short human reason, e.g. "Opens at 20:00" / "Opens Sat 28 Jun, 21:00" / "Available on Sat 28 Jun". */
  reason: string;
  /** The window this availability is for; record the completion against it. */
  occKey?: string;
  /** When that window opened (epoch ms); set for 'available'. */
  openedAt?: number;
}

function fmtTime(ms: number): string {
  return new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
function fmtDate(ms: number): string {
  return new Date(ms).toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
}
/**
 * How well a planned window's opening is known ahead of the day: 'time' when it opens at a time the participant's
 * answers or the study fix (a clock time, the usual wake-up or bedtime); 'wake' when it opens as they get up, which a
 * sensor or the wake-up check-in tells on the day; 'date' otherwise: at a random or event-driven prompt, after an
 * earlier check-in, or at a point of the waking day that moves with the actual wake-up or bedtime.
 */
function openingKnown(schedule: NormalizedSchedule, w: AbsWindow): 'time' | 'wake' | 'date' {
  const c = schedule.checkins[w.checkin];
  if (!c || w.early) return 'date';
  if (c.available !== 'range' && c.prompt !== 'start' && c.prompt !== 'none') return 'date'; // opens at its prompt
  if (w.open !== w.start) return 'date'; // deferred by an earlier check-in
  const wakeOnTheDay = !!schedule.wakeSensor || !!schedule.wakeFromCheckin;
  switch (c.startType) {
    case 'fixed':
    case 'usualWake':
      return 'time';
    case 'wake':
      if (!wakeOnTheDay) return 'time';
      return !c.startNextDay && Math.trunc(Number(c.startOffsetMin)) >= 0 ? 'wake' : 'date';
    case 'bed':
      return schedule.bedSensor ? 'date' : 'time';
    default: // split
      if (!wakeOnTheDay) return 'time';
      return Math.trunc(Number(c.startSegment)) === 0 ? 'wake' : 'date';
  }
}

/** "Opens at 20:00" if today, else "Opens Sat 28 Jun, 20:00". */
function opensReason(opensAt: number, now: number): string {
  return midnight(opensAt) === midnight(now) ? `Opens at ${fmtTime(opensAt)}` : `Opens ${fmtDate(opensAt)}, ${fmtTime(opensAt)}`;
}

/** Days looked ahead for "opens …". */
const AVAILABILITY_HORIZON_DAYS = 14;

/**
 * Availability of every questionnaire now, computed once: yesterday, today and the next days (for "opens …"), from
 * the schedule, the server's cached inputs and the device's completions. Questionnaires without a check-in are locked.
 */
export function studyAvailability(
  study: Pick<EsmiraStudy, 'id' | 'schedule'>, userId: string, joined: number, now: number, completions: Completions,
): (q: { internalId: number }) => Availability {
  const schedule = scheduleConfig(study);
  if (!schedule) return () => ({ state: 'locked', reason: 'Not scheduled' });
  const days = localDays(study, userId, joined, now, loadServerInputs(study.id, userId), deviceCompletions(completions), -1, AVAILABILITY_HORIZON_DAYS);
  const windows = days.flatMap((d) => d.windows);
  const today = days.find((d) => d.date === localDate(now));
  const dayIndex = today?.index ?? datesDiff(now, joined);
  // While today's wake-up is not known yet: the latest it will be.
  const wakeBy = today && today.wake === null && today.wakeBy !== null && today.wakeBy > now ? today.wakeBy : null;
  return (q) => {
    const a = availabilityState(schedule, q.internalId, windows, now, dayIndex);
    switch (a.state) {
      case 'available': {
        const opened = windows.find((w) => w.qid === q.internalId && w.key === a.occKey)?.open;
        return { state: 'available', reason: 'Available now', occKey: a.occKey ?? undefined, ...(opened !== undefined ? { openedAt: opened } : {}) };
      }
      case 'completed': return { state: 'completed', reason: 'Completed' };
      case 'ended': return { state: 'ended', reason: 'No longer available' };
      case 'upcoming': {
        const first = a.opensAt ?? Math.min(...schedule.checkins.filter((c) => Math.trunc(Number(c.questionnaireId)) === q.internalId)
          .map((c) => Math.max(midnight(midnight(joined) + Math.trunc(Number(c.dayFrom)) * DAY + DAY / 2), Number(c.dateFrom))));
        // Before the study starts: the time too, when the participant's answers fix it (see openingKnown).
        const w = a.opensAt === null ? undefined : windows.find((x) => x.qid === q.internalId && x.open === a.opensAt && !x.done);
        const known = w ? openingKnown(schedule, w) : 'date';
        const reason = known === 'time' ? `Opens ${fmtDate(first)}, ${fmtTime(first)}`
          : known === 'wake' ? `Opens ${fmtDate(first)}, after you get up` : `Available on ${fmtDate(first)}`;
        return { state: 'upcoming', opensAt: first, reason };
      }
      default:
        if (a.opensAt !== null) {
          // A window that opens at the usual wake-up while today's wake-up is still unknown opens earlier if they get up earlier.
          if (wakeBy !== null && a.opensAt === wakeBy) return { state: 'locked', opensAt: wakeBy, reason: `Opens after you get up (by ${fmtTime(wakeBy)})` };
          return { state: 'locked', opensAt: a.opensAt, reason: opensReason(a.opensAt, now) };
        }
        if (wakeBy !== null) return { state: 'locked', opensAt: wakeBy, reason: `Opens after you get up (by ${fmtTime(wakeBy)})` };
        return { state: 'locked', reason: 'Done for today' };
    }
  };
}

function cacheKey(studyId: number, userId: string): string { return `esmira_schedule_${studyId}_${userId}`; }

/** The server's last answer, kept so the app keeps the right windows offline. */
export function loadServerInputs(studyId: number, userId: string): ServerInputs | null {
  try {
    const parsed = JSON.parse(localStorage.getItem(cacheKey(studyId, userId)) ?? 'null');
    return parsed && typeof parsed === 'object' ? parsed : null;
  } catch {
    return null;
  }
}

export function saveServerInputs(studyId: number, userId: string, inputs: ServerInputs): void {
  try {
    localStorage.setItem(cacheKey(studyId, userId), JSON.stringify(inputs));
  } catch {
    /* non-fatal */
  }
}
