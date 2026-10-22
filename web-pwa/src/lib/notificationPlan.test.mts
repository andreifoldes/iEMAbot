import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { EsmiraQuestionnaire } from '../types.ts';
import { planReminders, reminderId, hasScheduledSignals } from './notificationPlan.ts';

const DAY = 86400000;
const H = 3600000;
const JOINED = new Date(2026, 6, 10, 0, 0, 0, 0).getTime();
const NOW = new Date(2026, 6, 10, 9, 0, 0, 0).getTime(); // 09:00 on day 0
const q = (over: Partial<EsmiraQuestionnaire> = {}, hours: number[] = [8, 20]): EsmiraQuestionnaire =>
  ({ internalId: 1, title: 'Check-in', pages: [], actionTriggers: [{ schedules: [{ signalTimes: hours.map((h) => ({ startTimeOfDay: h * H })) }] }], ...over } as EsmiraQuestionnaire);
const study = (...qs: EsmiraQuestionnaire[]) => ({ id: 7, title: 'Demo', questionnaires: qs });

test('plans only future signals, in order, across the horizon', () => {
  const plan = planReminders(study(q()), JOINED, NOW, {}, { horizonDays: 2 });
  // today only 20:00 is ahead; then 08:00+20:00 on days 1 and 2
  assert.equal(plan.length, 5);
  assert.equal(plan[0].at, NOW + 11 * H);
  assert.deepEqual(plan.map((p) => p.at), [...plan.map((p) => p.at)].sort((a, b) => a - b));
  assert.equal(plan[0].title, 'Demo');
  assert.match(plan[0].body, /Check-in/);
});

test('passive questionnaires (no signals) plan nothing', () => {
  assert.equal(planReminders(study(q({ actionTriggers: [] })), JOINED, NOW, {}).length, 0);
  assert.equal(hasScheduledSignals([q({ actionTriggers: [] })]), false);
  assert.equal(hasScheduledSignals([q()]), true);
});

test('skips an occurrence that is already answered', () => {
  const done = { 1: { lastAt: NOW, count: 1, occ: { '0:72000000': NOW } } };
  const plan = planReminders(study(q()), JOINED, NOW, done, { horizonDays: 0 });
  assert.equal(plan.length, 0);
});

test('completableOnce with a completion plans nothing', () => {
  const done = { 1: { lastAt: NOW, count: 1, occ: {} } };
  assert.equal(planReminders(study(q({ completableOnce: true })), JOINED, NOW, done).length, 0);
});

test('once-per-day: a completion today drops only the rest of today', () => {
  const done = { 1: { lastAt: NOW, count: 1, occ: {} } };
  const plan = planReminders(study(q({ completableOncePerDay: true })), JOINED, NOW, done, { horizonDays: 1 });
  assert.equal(plan.length, 2); // tomorrow 08:00 + 20:00; today's 20:00 suppressed
  assert.ok(plan.every((p) => p.at >= JOINED + DAY));
});

test('respects the study period and activation delay', () => {
  const ended = planReminders(study(q({ durationPeriodDays: 1 })), JOINED, NOW, {}, { horizonDays: 5 });
  assert.ok(ended.every((p) => p.at < JOINED + 2 * DAY)); // days 0 and 1 only
  const delayed = planReminders(study(q({ durationStartingAfterDays: 2 })), JOINED, NOW, {}, { horizonDays: 3 });
  assert.ok(delayed.every((p) => p.at >= JOINED + 2 * DAY));
});

test('absolute window bounds are honoured', () => {
  const plan = planReminders(study(q({ durationEnd: NOW + 12 * H })), JOINED, NOW, {}, { horizonDays: 3 });
  assert.equal(plan.length, 1); // only today's 20:00
});

test('deadline text appears when a visibility window is set', () => {
  const plan = planReminders(
    study(q({ completableOncePerNotification: true, completableMinutesAfterNotification: 30, notificationIncludeDeadline: true })),
    JOINED, NOW, {}, { horizonDays: 0 });
  assert.match(plan[0].body, /Complete by/);
});

test('ids are stable, positive 31-bit, and distinct per occurrence', () => {
  const a = planReminders(study(q()), JOINED, NOW, {});
  const b = planReminders(study(q()), JOINED, NOW, {});
  assert.deepEqual(a.map((p) => p.id), b.map((p) => p.id));
  assert.equal(new Set(a.map((p) => p.id)).size, a.length);
  assert.ok(a.every((p) => p.id > 0 && p.id <= 0x7fffffff));
  assert.notEqual(reminderId(7, 1, '0:1'), reminderId(7, 2, '0:1'));
});

test('caps the number of reminders', () => {
  const plan = planReminders(study(q({}, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20])), JOINED, NOW, {}, { horizonDays: 7, max: 25 });
  assert.equal(plan.length, 25);
});
