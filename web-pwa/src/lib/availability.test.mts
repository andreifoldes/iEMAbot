/**
 * Tests for schedule-aware availability gating. Run with `npm test` (Node's
 * built-in test runner + native TS type-stripping — no extra deps).
 *
 * `availability.ts` reads/writes completion state through `localStorage`, so we
 * install an in-memory stub before importing anything that touches it.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { EsmiraQuestionnaire } from '../types.ts';

const store: Record<string, string> = {};
(globalThis as unknown as { localStorage: Storage }).localStorage = {
  getItem: (k: string) => (k in store ? store[k] : null),
  setItem: (k: string, v: string) => { store[k] = String(v); },
  removeItem: (k: string) => { delete store[k]; },
  clear: () => { for (const k of Object.keys(store)) delete store[k]; },
  key: () => null,
  length: 0,
} as Storage;

const { computeAvailability, recordCompletion, loadCompletions, summarize } = await import('./availability.ts');

const DAY = 86400000;
const H = 3600000;
// A fixed local anchor; all math is local-midnight relative so the TZ cancels out.
const JOINED = new Date(2026, 6, 10, 0, 0, 0, 0).getTime(); // enrollment
const D1 = new Date(2026, 6, 11, 0, 0, 0, 0).getTime();     // day 1 (startingAfterDays=1)
const at = (dayMidnight: number, h: number, m = 0) => dayMidnight + h * H + m * 60000;

let seq = 0;
/** Fresh, isolated (studyId,userId) per case so stored completions never leak. */
function ctx() { const userId = `u${seq++}`; return { sid: 9727, userId, cs: () => loadCompletions(9727, userId) }; }

const signal = (h: number) => ({ startTimeOfDay: h * H });
const scheduled = (...hours: number[]): Partial<EsmiraQuestionnaire> =>
  ({ actionTriggers: [{ schedules: [{ signalTimes: hours.map(signal) }] }] } as Partial<EsmiraQuestionnaire>);

test('once-per-day, signal-based: one completion locks until the next day', () => {
  const { sid, userId, cs } = ctx();
  const q = {
    internalId: 1, durationStartingAfterDays: 1, durationPeriodDays: 3,
    completableOncePerDay: true, ...scheduled(12),
  } as EsmiraQuestionnaire;

  assert.equal(computeAvailability(q, JOINED, at(D1, 11), cs()).state, 'locked', 'before the 12:00 signal');
  assert.equal(computeAvailability(q, JOINED, at(D1, 12, 30), cs()).state, 'available', 'inside the window, not yet done');

  recordCompletion(sid, userId, q, JOINED, at(D1, 12, 30));

  assert.equal(computeAvailability(q, JOINED, at(D1, 13), cs()).state, 'locked', 'same day after completion');
  assert.equal(computeAvailability(q, JOINED, at(D1, 23, 59), cs()).state, 'locked', 'still locked late the same day');
  assert.equal(computeAvailability(q, JOINED, at(D1 + DAY, 12, 30), cs()).state, 'available', 'reopens the next day');
});

test('once-per-day, passive (no signals): available all day, then locked until tomorrow', () => {
  const { sid, userId, cs } = ctx();
  const q = {
    internalId: 2, durationStartingAfterDays: 1, durationPeriodDays: 3,
    completableOncePerDay: true,
  } as EsmiraQuestionnaire;

  assert.equal(computeAvailability(q, JOINED, at(D1, 9), cs()).state, 'available', 'passive is open any time');
  recordCompletion(sid, userId, q, JOINED, at(D1, 9));
  const after = computeAvailability(q, JOINED, at(D1, 18), cs());
  assert.equal(after.state, 'locked', 'locked for the rest of the day once done');
  assert.equal(after.opensAt, D1 + DAY, 'reopens at the next local midnight');
  assert.equal(computeAvailability(q, JOINED, at(D1 + DAY, 9), cs()).state, 'available', 'available again next day');
});

test('per-notification with two end-of-day windows: each occurrence completable once (overlap fix)', () => {
  const { sid, userId, cs } = ctx();
  const q = {
    internalId: 3, durationStartingAfterDays: 1, durationPeriodDays: 3,
    completableOncePerNotification: true, ...scheduled(12, 16),
  } as EsmiraQuestionnaire;

  // Complete the 12:00 occurrence.
  assert.equal(computeAvailability(q, JOINED, at(D1, 12, 30), cs()).state, 'available', 'noon window open');
  recordCompletion(sid, userId, q, JOINED, at(D1, 12, 30));
  assert.equal(computeAvailability(q, JOINED, at(D1, 13), cs()).state, 'locked', 'noon done, 16:00 not open yet');

  // The 16:00 occurrence is a distinct window that must still be completable...
  assert.equal(computeAvailability(q, JOINED, at(D1, 16, 30), cs()).state, 'available', '16:00 window open, not yet done');
  recordCompletion(sid, userId, q, JOINED, at(D1, 16, 30));
  // ...and after completing it, the questionnaire is done for the day (regression:
  // the mark used to land on the still-open noon window, leaving 16:00 re-completable).
  assert.notEqual(computeAvailability(q, JOINED, at(D1, 17), cs()).state, 'available', 'both occurrences done for the day');
});

test('no completion flag: signal window still locks after completion (regression: used to stay available all day)', () => {
  const { sid, userId, cs } = ctx();
  const q = {
    internalId: 4, durationStartingAfterDays: 1, durationPeriodDays: 3,
    ...scheduled(12),
  } as EsmiraQuestionnaire;

  assert.equal(computeAvailability(q, JOINED, at(D1, 13), cs()).state, 'available');
  recordCompletion(sid, userId, q, JOINED, at(D1, 13));
  const after = computeAvailability(q, JOINED, at(D1, 14), cs());
  assert.equal(after.state, 'locked', 'no flag needed ⇒ the opened occurrence is still marked done');
  assert.equal(after.opensAt, D1 + DAY + 12 * H, 'reopens at tomorrow’s 12:00 signal, not immediately re-completable');
});

test('summarize: asks to choose only when more than one questionnaire is open', () => {
  const { cs } = ctx();
  const passive = { actionTriggers: [] } as unknown as EsmiraQuestionnaire;
  const now = at(D1, 12);
  assert.equal(summarize([passive], JOINED, now, cs()).message, 'Tap below to begin.');
  assert.equal(summarize([passive, passive], JOINED, now, cs()).message, 'Choose a questionnaire to begin.');
});
