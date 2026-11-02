/**
 * The schedule engine in the app (lib/schedule.ts) against the server's (src/backend/schedule/Schedule.php):
 * both run test/fixtures/channels/schedule.json (PHP side: test/backend/schedule/ScheduleTest.php).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const {
  normalize, windowsForDay, resolveWakeTod, resolveEventTod, eventFallbackTod, runsOnDay, applyCompletions, availabilityState, notification,
} = await import('./schedule.ts');

const fx = JSON.parse(readFileSync(new URL('../../../test/fixtures/channels/schedule.json', import.meta.url), 'utf8'));
const checkin = (c: object) => normalize({ checkins: [c] }).checkins[0];

test('windows of a day match the shared fixture', () => {
  for (const k of fx.windowsForDay) assert.deepEqual(windowsForDay(normalize(k.schedule), k.day, k.seed), k.expected, k.name);
});

test('wake-up, event prompts, fallbacks and days match the shared fixture', () => {
  for (const k of fx.resolveWakeTod) assert.equal(resolveWakeTod(normalize(k.schedule), k.observed, k.fallback, k.nowTod), k.expected, k.name);
  for (const k of fx.resolveEventTod) assert.equal(resolveEventTod(checkin(k.checkin), k.events, k.fallback, k.nowTod, k.seed), k.expected, k.name);
  for (const k of fx.eventFallbackTod) assert.equal(eventFallbackTod(checkin(k.checkin), k.variables), k.expected, k.name);
  for (const k of fx.runsOnDay) assert.equal(runsOnDay(checkin(k.checkin), k.day), k.expected, k.name);
});

test('completions, notifications and availability match the shared fixture', () => {
  for (const k of fx.applyCompletions) assert.deepEqual(applyCompletions(normalize(k.schedule), k.windows, k.completions), k.expected, k.name);
  for (const k of fx.notification) {
    const n = notification(k.study, normalize(k.schedule), k.window);
    assert.deepEqual([n.title, n.body, n.reminderCount, n.reminderDelayMs], k.expected, k.name);
  }
  for (const k of fx.availability) assert.deepEqual(availabilityState(normalize(k.schedule), k.qid, k.windows, k.now, k.dayIndex), k.expected, k.name);
});
