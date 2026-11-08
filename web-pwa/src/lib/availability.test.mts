/**
 * Availability in the app for check-ins as older questionnaire settings were upgraded to (ScheduleUpgrade.php): once
 * per day, open any time, signal times each completable once, days from 04:00; plus the completion store and the
 * chat summary. Run with `npm test` (Node's built-in test runner + native TS type-stripping — no extra deps).
 *
 * `availability.ts` reads/writes completion state through `localStorage`, so we install an in-memory stub before
 * importing anything that touches it.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { EsmiraQuestionnaire, EsmiraStudy, ScheduleCheckin } from '../types.ts';
import './testI18n.mts';

const store: Record<string, string> = {};
(globalThis as unknown as { localStorage: Storage }).localStorage = {
  getItem: (k: string) => (k in store ? store[k] : null),
  setItem: (k: string, v: string) => { store[k] = String(v); },
  removeItem: (k: string) => { delete store[k]; },
  clear: () => { for (const k of Object.keys(store)) delete store[k]; },
  key: () => null,
  length: 0,
} as Storage;

const { recordCompletion, loadCompletions, summarize, datesDiff } = await import('./availability.ts');
const { studyAvailability } = await import('./schedule.ts');

const H = 3600000;
// A fixed local anchor; all math is local-midnight relative so the TZ cancels out.
const JOINED = new Date(2026, 6, 10, 0, 0, 0, 0).getTime(); // enrollment
const D1 = new Date(2026, 6, 11, 0, 0, 0, 0).getTime();
const D2 = new Date(2026, 6, 12, 0, 0, 0, 0).getTime();
const at = (dayMidnight: number, h: number, m = 0) => dayMidnight + h * H + m * 60000;
const Q: EsmiraQuestionnaire = { internalId: 1, title: 'Diary', pages: [] };

let seq = 0;
/** A study with these check-ins of questionnaire 1, and a fresh participant so stored completions never leak. */
function ctx(checkins: ScheduleCheckin[], dayStart = 0) {
  const userId = `u${seq++}`;
  const s = { id: 4001, title: 'S', questionnaires: [Q], schedule: { dayStart, checkins } } as EsmiraStudy;
  const av = (now: number) => studyAvailability(s, userId, JOINED, now, loadCompletions(4001, userId))(Q);
  const complete = (now: number) => {
    const a = av(now);
    assert.equal(a.state, 'available');
    recordCompletion(4001, userId, 1, now, a.occKey);
  };
  return { av, complete };
}
/** A signal time: prompted then, open until the end of the study day. */
const signal = (h: number, extra: ScheduleCheckin = {}): ScheduleCheckin =>
  ({ questionnaireId: 1, parallel: true, startType: 'fixed', startTime: h * H, endType: 'fixed', endTime: h * H, available: 'day', ...extra });
/** No schedule: open any time. */
const anytime = (extra: ScheduleCheckin = {}): ScheduleCheckin =>
  ({ questionnaireId: 1, parallel: true, startType: 'fixed', startTime: 0, endType: 'fixed', endTime: 0, endNextDay: true, available: 'range', prompt: 'none', limit: 'none', ...extra });

test('once per day, signal-based: one completion locks until the next day', () => {
  const { av, complete } = ctx([signal(9, { limit: 'day', dayFrom: 1 }), signal(15, { limit: 'day', dayFrom: 1 })]);
  assert.equal(av(at(JOINED, 12)).state, 'upcoming');
  complete(at(D1, 10));
  const locked = av(at(D1, 16));
  assert.equal(locked.state, 'locked');
  assert.equal(locked.opensAt, at(D2, 9));
  assert.equal(av(at(D2, 9, 5)).state, 'available');
});

test('an open window reports its key and when it opened (a saved check-in resumes only into it)', () => {
  const { av } = ctx([signal(9, { dayFrom: 1 })]);
  const today = av(at(D1, 10));
  assert.equal(today.state, 'available');
  assert.equal(today.openedAt, at(D1, 9));
  const tomorrow = av(at(D2, 10));
  assert.equal(tomorrow.openedAt, at(D2, 9));
  assert.notEqual(tomorrow.occKey, today.occKey);
  assert.equal(av(at(JOINED, 12)).openedAt, undefined);
});

test('once per day, open any time: available all day, then locked until tomorrow', () => {
  const { av, complete } = ctx([anytime({ limit: 'day' })]);
  assert.equal(av(at(D1, 8)).state, 'available');
  complete(at(D1, 8));
  assert.equal(av(at(D1, 20)).state, 'locked');
  assert.equal(av(at(D2, 0, 1)).state, 'available');
});

test('two signal windows open until the end of the day: each completable once', () => {
  const { av, complete } = ctx([signal(9), signal(15)]);
  complete(at(D1, 10)); // the 09:00 window
  assert.equal(av(at(D1, 11)).state, 'locked');
  assert.equal(av(at(D1, 11)).opensAt, at(D1, 15));
  complete(at(D1, 16)); // the 15:00 window
  assert.equal(av(at(D1, 17)).state, 'locked');
});

test('open any time without a limit stays available after a completion; a minimum gap locks it for a while', () => {
  const free = ctx([anytime()]);
  free.complete(at(D1, 10));
  assert.equal(free.av(at(D1, 10, 1)).state, 'available');
  const gapped = ctx([anytime({ minGapMinutes: 90 })]);
  gapped.complete(at(D1, 10));
  assert.deepEqual([gapped.av(at(D1, 11)).state, gapped.av(at(D1, 11)).opensAt], ['locked', at(D1, 11, 30)]);
  assert.equal(gapped.av(at(D1, 11, 30)).state, 'available');
});

test('days from 04:00: once per day counts 04:00–04:00 and windows stay open past midnight', () => {
  const { av, complete } = ctx([signal(20, { limit: 'day' })], 4 * H);
  assert.equal(av(at(D2, 2)).state, 'available'); // day 1's 20:00 window, still open at 02:00
  complete(at(D2, 2));
  assert.equal(av(at(D2, 3)).state, 'locked');
  assert.equal(av(at(D2, 20, 1)).state, 'available'); // day 2's own window
});

test('the completion store keeps each window and the count', () => {
  recordCompletion(1, 'store', 5, 1000, '2026-07-11:0');
  recordCompletion(1, 'store', 5, 2000, '2026-07-11:1');
  assert.deepEqual(loadCompletions(1, 'store')[5], { lastAt: 2000, count: 2, occ: { '2026-07-11:0': 1000, '2026-07-11:1': 2000 } });
  assert.equal(datesDiff(D2, JOINED), 2);
});

test('summarize: asks to choose only when more than one questionnaire is open', () => {
  const open = () => ({ state: 'available' as const, reason: '' });
  assert.equal(summarize([Q], at(D1, 12), open).message, 'Tap below to begin.');
  assert.equal(summarize([Q, Q], at(D1, 12), open).message, 'Choose a questionnaire to begin.');
  const later = () => ({ state: 'locked' as const, reason: '', opensAt: at(D1, 15) });
  assert.match(summarize([Q], at(D1, 12), later).message, /^Nothing is open right now/);
  // Opening within the minute: not "opens at <now>" (the clock time it already is).
  const soon = () => ({ state: 'locked' as const, reason: '', opensAt: at(D1, 12) + 30000 });
  assert.equal(summarize([Q], at(D1, 12), soon).message, 'Your next questionnaire opens in a moment — it will appear below.');
  const ended = () => ({ state: 'ended' as const, reason: '' });
  assert.equal(summarize([Q], at(D1, 12), ended).message, 'You have completed the study. Thank you for taking part!');
});
