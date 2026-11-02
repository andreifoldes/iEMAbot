/**
 * The Android app's alarm plan (notificationPlan.ts): the schedule's prompts and reminders, planned on the device
 * and grouped per minute like web push.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { EsmiraQuestionnaire, ScheduleCheckin } from '../types.ts';
import { planReminders, reminderId } from './notificationPlan.ts';

const DAY = 86400000;
const H = 3600000;
const M = 60000;
const JOINED = new Date(2026, 6, 10, 0, 0, 0, 0).getTime();
const NOW = new Date(2026, 6, 10, 9, 0, 0, 0).getTime(); // 09:00 on day 0
const q = (id: number, title: string): EsmiraQuestionnaire => ({ internalId: id, title, pages: [] });
/** A check-in prompted at a fixed time and open until the end of the day (the upgraded form of a signal time). */
const at = (hour: number, extra: ScheduleCheckin = {}): ScheduleCheckin =>
  ({ questionnaireId: 1, parallel: true, startType: 'fixed', startTime: hour * H, endType: 'fixed', endTime: hour * H, available: 'day', ...extra });
const study = (checkins: ScheduleCheckin[], extra: object = {}) =>
  ({ id: 7, title: 'Demo', questionnaires: [q(1, 'Check-in'), q(2, 'Sleep diary')], schedule: { checkins, ...extra } });

test('plans only future prompts, in order, across the horizon', () => {
  const plan = planReminders(study([at(8), at(20)]), JOINED, NOW, {}, { horizonDays: 2 });
  // today only 20:00 is ahead; then 08:00 + 20:00 on days 1 and 2
  assert.equal(plan.length, 5);
  assert.equal(plan[0].at, NOW + 11 * H);
  assert.deepEqual(plan.map((p) => p.at), [...plan.map((p) => p.at)].sort((a, b) => a - b));
  // the same title and body web push uses: the questionnaire title, the default body
  assert.equal(plan[0].title, 'Check-in');
  assert.equal(plan[0].body, 'You have a questionnaire to complete.');
  assert.deepEqual(plan[0].keys, [`availability:1:${NOW + 11 * H}`]);
});

test("each notification expires when its check-in's window closes", () => {
  // open until the end of the day: last night's prompt is gone by the morning
  assert.equal(planReminders(study([at(20)]), JOINED, NOW, {}, { horizonDays: 0 })[0].deadline, JOINED + DAY);
  // days that start at 04:00 keep the evening window open until then
  assert.equal(planReminders(study([at(20)], { dayStart: 4 * H }), JOINED, NOW, {}, { horizonDays: 0 })[0].deadline, JOINED + DAY + 4 * H);
  // a coalesced notification stays until the last of its windows closes
  const both = planReminders(study([at(20, { available: 'window', endTime: 21 * H }), { ...at(20), questionnaireId: 2 }]), JOINED, NOW, {}, { horizonDays: 0 });
  assert.equal(both.length, 1);
  assert.equal(both[0].deadline, JOINED + DAY);
});

test('check-ins without a prompt plan nothing', () => {
  assert.equal(planReminders(study([at(20, { prompt: 'none' })]), JOINED, NOW, {}).length, 0);
  assert.equal(planReminders({ id: 7, title: 'Demo', questionnaires: [q(1, 'Check-in')] }, JOINED, NOW, {}).length, 0);
});

test('skips a window that is already done', () => {
  const evening = new Date(JOINED).toISOString() && `${new Date(JOINED).getFullYear()}-07-10:1`;
  const done = { 1: { lastAt: NOW, count: 1, occ: { [evening]: NOW } } };
  assert.equal(planReminders(study([at(8), at(20)]), JOINED, NOW, done, { horizonDays: 0 }).length, 0);
});

test('once in the study: a completion plans nothing more', () => {
  const done = { 1: { lastAt: NOW - DAY, count: 1, occ: {} } };
  assert.equal(planReminders(study([at(20, { limit: 'once' })]), JOINED, NOW, done).length, 0);
});

test('once per day: a completion today drops only the rest of today', () => {
  const done = { 1: { lastAt: NOW, count: 1, occ: {} } };
  const plan = planReminders(study([at(8, { limit: 'day' }), at(20, { limit: 'day' })]), JOINED, NOW, done, { horizonDays: 1 });
  assert.equal(plan.length, 2); // tomorrow 08:00 + 20:00; today's 20:00 suppressed
  assert.ok(plan.every((p) => p.at >= JOINED + DAY));
});

test('once per day with a 04:00 day start: a completion after midnight still counts for the evening before', () => {
  const lateNight = JOINED + DAY + 1 * H; // 01:00 on day 1 — still day 0 when the day starts at 04:00
  const done = { 1: { lastAt: lateNight, count: 1, occ: {} } };
  const daily = [at(2, { limit: 'day' }), at(20, { limit: 'day' })];
  const shifted = planReminders(study(daily, { dayStart: 4 * H }), JOINED, lateNight, done, { horizonDays: 0 });
  assert.deepEqual(shifted.map((p) => p.at), [JOINED + DAY + 20 * H], '02:00 belongs to the day already done; 20:00 is a new day');
  const midnightDays = planReminders(study(daily), JOINED, lateNight, done, { horizonDays: 0 });
  assert.equal(midnightDays.length, 0, 'with midnight days both of day 1’s check-ins are done');
});

test('respects the check-in days and absolute dates', () => {
  const ended = planReminders(study([at(8, { dayUntil: 1 }), at(20, { dayUntil: 1 })]), JOINED, NOW, {}, { horizonDays: 5 });
  assert.ok(ended.length > 0 && ended.every((p) => p.at < JOINED + 2 * DAY)); // days 0 and 1 only
  const delayed = planReminders(study([at(8, { dayFrom: 2 })]), JOINED, NOW, {}, { horizonDays: 3 });
  assert.ok(delayed.length > 0 && delayed.every((p) => p.at >= JOINED + 2 * DAY));
  assert.equal(planReminders(study([at(8, { dateUntil: NOW + 12 * H }), at(20, { dateUntil: NOW + 12 * H })]), JOINED, NOW, {}, { horizonDays: 3 }).length, 1);
});

test('deadline text is the same 24-hour "Complete by" as web push', () => {
  const plan = planReminders(study([at(20, { available: 'prompt', responseOverride: true, responseMinutes: 30, includeDeadline: true })]), JOINED, NOW, {}, { horizonDays: 0 });
  assert.equal(plan[0].body, 'You have a questionnaire to complete. Complete by 20:30.');
});

test('ids are stable, positive 31-bit, and distinct per occurrence', () => {
  const a = planReminders(study([at(8), at(20)]), JOINED, NOW, {});
  const b = planReminders(study([at(8), at(20)]), JOINED, NOW, {});
  assert.deepEqual(a.map((p) => p.id), b.map((p) => p.id));
  assert.equal(new Set(a.map((p) => p.id)).size, a.length);
  assert.ok(a.every((p) => p.id > 0 && p.id <= 0x7fffffff));
  assert.notEqual(reminderId(7, 1, '0:1'), reminderId(7, 2, '0:1'));
});

test('caps the number of reminders', () => {
  const hourly = Array.from({ length: 20 }, (_, i) => at(i + 1));
  assert.equal(planReminders(study(hourly), JOINED, NOW, {}, { horizonDays: 7, max: 25 }).length, 25);
});

test('the check-in\'s own title and text', () => {
  const plan = planReminders(study([at(20, { title: ' Evening ', text: '  How was your day? ' })]), JOINED, NOW, {}, { horizonDays: 0 });
  assert.deepEqual([plan[0].title, plan[0].body], ['Evening', 'How was your day?']);
});

test('follow-up reminders are planned on the device and stop once the questionnaire is completed', () => {
  const withReminders = [at(20, { reminderCount: 2, reminderDelayMin: 10 })];
  const plan = planReminders(study(withReminders), JOINED, NOW, {}, { horizonDays: 0 });
  assert.deepEqual(plan.map((p) => p.at), [NOW + 11 * H, NOW + 11 * H + 10 * M, NOW + 11 * H + 20 * M]);
  assert.match(plan[1].keys[0], /^reminder:1:/);
  // answered at 20:05 (re-plan when the app opens): the 20:10 and 20:20 reminders go
  const later = NOW + 11 * H + 5 * M;
  const done = { 1: { lastAt: later, count: 1, occ: {} } };
  assert.equal(planReminders(study(withReminders), JOINED, later, done, { horizonDays: 0 }).length, 0);
});

test('questionnaires due at the same minute share one counted notification, like web push', () => {
  const both = [at(20), at(20, { questionnaireId: 2 })];
  const plan = planReminders(study(both), JOINED, NOW, {}, { horizonDays: 0 });
  assert.equal(plan.length, 1);
  assert.deepEqual([plan[0].title, plan[0].body, plan[0].keys.length], ['Demo', 'You have 2 questionnaires to complete.', 2]);
  // once one of them is done, the other gets its own notification under a new id
  const done = { 2: { lastAt: NOW, count: 1, occ: { [`${new Date(NOW).getFullYear()}-07-10:1`]: NOW } } };
  const after = planReminders(study(both), JOINED, NOW, done, { horizonDays: 0 });
  assert.equal(after[0].title, 'Check-in');
  assert.notEqual(after[0].id, plan[0].id);
});

test('random prompts are drawn per participant and day, the same on every run', () => {
  const blocks = [
    { questionnaireId: 1, parallel: true, startType: 'fixed', startTime: 10 * H, endType: 'fixed', endTime: 14 * H, prompt: 'random', available: 'day' },
    { questionnaireId: 1, parallel: true, startType: 'fixed', startTime: 14 * H, endType: 'fixed', endTime: 18 * H, prompt: 'random', available: 'day' },
  ] as ScheduleCheckin[];
  const a = planReminders(study(blocks), JOINED, NOW, {}, { horizonDays: 0, userId: 'alice' });
  const again = planReminders(study(blocks), JOINED, NOW, {}, { horizonDays: 0, userId: 'alice' });
  assert.equal(a.length, 2);
  assert.deepEqual(a.map((p) => p.at), again.map((p) => p.at));
  assert.ok(a[0].at >= NOW + 1 * H && a[0].at <= NOW + 5 * H); // 10:00–14:00
  assert.ok(a[1].at >= NOW + 5 * H && a[1].at <= NOW + 9 * H); // 14:00–18:00
  assert.ok(a.every((p) => p.at % M === 0));
});

test('check-ins that depend on a wearable are left to the server', () => {
  const plan = planReminders(study([at(20), { questionnaireId: 2, prompt: 'event', available: 'range' }]), JOINED, NOW, {}, { horizonDays: 0 });
  assert.deepEqual(plan.map((p) => p.questionnaireId), [1]);
});
