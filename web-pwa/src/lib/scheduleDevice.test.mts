/**
 * The study's schedule in the app (lib/schedule.ts), offline: what is open now and why, computed on the device from
 * the cached server inputs and the device's own completions; answering the wake-up check-in re-plans the day without
 * the server. The engine's parity with the server is in schedule.test.mts.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { EsmiraQuestionnaire, EsmiraStudy, ScheduleCheckin, ScheduleConfig } from '../types.ts';

const store: Record<string, string> = {};
(globalThis as unknown as { localStorage: Storage }).localStorage = {
  getItem: (k: string) => (k in store ? store[k] : null),
  setItem: (k: string, v: string) => { store[k] = String(v); },
  removeItem: (k: string) => { delete store[k]; },
  clear: () => { for (const k of Object.keys(store)) delete store[k]; },
  key: () => null,
  length: 0,
} as Storage;

const { studyAvailability, saveServerInputs, localDate, autoStartIds, studyPeriod, studyDayLabel, hasPrompts } = await import('./schedule.ts');
const { recordCompletion, loadCompletions } = await import('./availability.ts');
const { planReminders } = await import('./notificationPlan.ts');

const H = 3600000;
const M = 60000;
const DAY = 86400000;
const DAY0 = new Date(2026, 9, 27, 0, 0, 0, 0).getTime(); // a Tuesday
const JOINED = DAY0 - 2 * DAY;
const q = (id: number, title: string): EsmiraQuestionnaire => ({ internalId: id, title, pages: [] });

function study(schedule: ScheduleConfig): EsmiraStudy {
  return { id: 4001, title: 'Demo', questionnaires: [q(1, 'Morning'), q(2, 'Daytime 1'), q(3, 'Daytime 2'), q(4, 'Evening')], schedule } as EsmiraStudy;
}

/** Four split-day check-ins, the evening with a 3 h window; wake-up 10:00 by default, from the morning answer. */
function splitDay(extra: ScheduleConfig = {}): EsmiraStudy {
  return study({
    responseMinutes: 60, wakeDefault: 10 * H, wakeFromCheckin: true,
    checkins: [
      { questionnaireId: 1, startSegment: 0, endSegment: 1 },
      { questionnaireId: 2, startSegment: 1, endSegment: 2 },
      { questionnaireId: 3, startSegment: 2, endSegment: 3 },
      { questionnaireId: 4, startSegment: 3, endSegment: 4, responseOverride: true, responseMinutes: 180 },
    ],
    ...extra,
  });
}

function states(s: EsmiraStudy, user: string, now: number): Record<string, string> {
  const availability = studyAvailability(s, user, JOINED, now, loadCompletions(s.id, user));
  return Object.fromEntries(s.questionnaires.map((x) => [x.title, availability(x).state]));
}

function complete(s: EsmiraStudy, user: string, index: number, now: number): void {
  const av = studyAvailability(s, user, JOINED, now, loadCompletions(s.id, user))(s.questionnaires[index]);
  assert.equal(av.state, 'available');
  recordCompletion(s.id, user, s.questionnaires[index].internalId, now, av.occKey);
}

test('one check-in at a time, each once', () => {
  const s = splitDay({ wakeFromCheckin: false });
  // Wake-up 10:00 (default), bedtime 23:00: prompts at 10:00, 13:15, 16:30 and 19:45.
  assert.deepEqual(states(s, 'u1', DAY0 + 9 * H), { Morning: 'locked', 'Daytime 1': 'locked', 'Daytime 2': 'locked', Evening: 'locked' });
  assert.deepEqual(states(s, 'u1', DAY0 + 10 * H + 5 * M), { Morning: 'available', 'Daytime 1': 'locked', 'Daytime 2': 'locked', Evening: 'locked' });
  const av = studyAvailability(s, 'u1', JOINED, DAY0 + 10 * H + 5 * M, loadCompletions(s.id, 'u1'))(s.questionnaires[0]);
  assert.equal(av.occKey, `${localDate(DAY0)}:0`);
  recordCompletion(s.id, 'u1', 1, DAY0 + 10 * H + 10 * M, av.occKey);
  assert.equal(states(s, 'u1', DAY0 + 10 * H + 11 * M).Morning, 'locked');
  assert.equal(states(s, 'u1', DAY0 + 13 * H + 20 * M)['Daytime 1'], 'available');
  assert.equal(states(s, 'u1', DAY0 + 22 * H + 40 * M).Evening, 'available'); // its own 3 h window
  assert.equal(states(s, 'u1', DAY0 + 22 * H + 50 * M).Evening, 'locked');
});

test('offline: answering the morning check-in re-plans the rest of the day', () => {
  const s = splitDay();
  // Before wake-up is known, the morning check-in is open from 05:00 (the wake-up window) without a prompt.
  assert.deepEqual(states(s, 'u2', DAY0 + 6 * H), { Morning: 'available', 'Daytime 1': 'locked', 'Daytime 2': 'locked', Evening: 'locked' });
  complete(s, 'u2', 0, DAY0 + 7 * H);
  // Wake-up 07:00, bedtime 23:00: Daytime 1 at 11:00, not 13:15 as with the 10:00 fallback.
  assert.equal(states(s, 'u2', DAY0 + 10 * H + 59 * M)['Daytime 1'], 'locked');
  assert.equal(states(s, 'u2', DAY0 + 11 * H)['Daytime 1'], 'available');
  assert.equal(states(s, 'u2', DAY0 + 11 * H).Morning, 'locked');
});

test('usual sleep times and wearable events from the cached server answer', () => {
  const s = splitDay({ wakeFromCheckin: false, wakeVariable: 'habitual_wake_time', bedVariable: 'habitual_bedtime' });
  saveServerInputs(s.id, 'u3', { variables: { habitual_wake_time: { v: '08:00', t: 1 }, habitual_bedtime: '00:00' } });
  // 08:00-24:00 in four parts: Daytime 1 at 12:00.
  assert.equal(states(s, 'u3', DAY0 + 8 * H).Morning, 'available');
  assert.equal(states(s, 'u3', DAY0 + 12 * H)['Daytime 1'], 'available');
  // Getting out of bed at 06:00, as the wearable told the server: 06:00-24:00 in four parts puts Daytime 1 at 10:30.
  const sensor = splitDay({ wakeFromCheckin: false, wakeSensor: true, wakeVariable: 'habitual_wake_time', bedVariable: 'habitual_bedtime' });
  saveServerInputs(sensor.id, 'u3s', { variables: { habitual_wake_time: '08:00', habitual_bedtime: '00:00' }, events: [{ t: DAY0 + 6 * H, provider: 'withings', kind: 'bed_out' }] });
  assert.equal(states(sensor, 'u3s', DAY0 + 10 * H + 29 * M)['Daytime 1'], 'locked');
  assert.equal(states(sensor, 'u3s', DAY0 + 10 * H + 30 * M)['Daytime 1'], 'available');
});

test('weekend wake-up and Friday and Saturday night bedtimes from the cached answers', () => {
  const s = study({ wakeVariable: 'w', wakeVariableWeekend: 'ww', bedVariable: 'b', bedVariableWeekend: 'bw', checkins: [
    { questionnaireId: 1, startType: 'usualWake', endType: 'usualWake', endOffsetMin: 120, available: 'range', prompt: 'none' },
    { questionnaireId: 4, startType: 'bed', startOffsetMin: -60, endType: 'bed', endOffsetMin: 60, available: 'range', prompt: 'none' },
  ] });
  saveServerInputs(s.id, 'u4', { variables: { w: '07:00', ww: '09:30', b: '22:30', bw: '00:30' } });
  const friday = new Date(2026, 9, 30).getTime(), saturday = new Date(2026, 9, 31).getTime(), sunday = new Date(2026, 10, 1).getTime();
  assert.equal(states(s, 'u4', friday + 7 * H).Morning, 'available');
  assert.equal(states(s, 'u4', friday + 21 * H + 30 * M).Evening, 'locked'); // Friday night: 23:30-01:30
  assert.equal(states(s, 'u4', friday + 25 * H + 29 * M).Evening, 'available');
  assert.equal(states(s, 'u4', saturday + 7 * H).Morning, 'locked'); // up at 09:30 at weekends
  assert.equal(states(s, 'u4', saturday + 9 * H + 30 * M).Morning, 'available');
  assert.equal(states(s, 'u4', sunday + 21 * H + 30 * M).Evening, 'available'); // Sunday night: a weeknight
});

test('why a check-in is not open: when it opens, after getting up, not yet, done', () => {
  const s = study({ wakeFromCheckin: true, wakeDefault: 8 * H, checkins: [
    { questionnaireId: 1, startType: 'wake', endType: 'wake', endOffsetMin: 180, available: 'range', prompt: 'wake' },
    { questionnaireId: 2, startType: 'fixed', startTime: 14 * H, endType: 'fixed', endTime: 15 * H },
    { questionnaireId: 3, parallel: true, startType: 'fixed', startTime: 10 * H, endType: 'fixed', endTime: 11 * H, dayFrom: 5 },
    { questionnaireId: 4, parallel: true, startType: 'fixed', startTime: 0, endType: 'fixed', endTime: 0, endNextDay: true, available: 'range', prompt: 'none', limit: 'once' },
  ] });
  const at = (now: number) => studyAvailability(s, 'u5', JOINED, now, loadCompletions(s.id, 'u5'));
  assert.equal(at(DAY0 + 4 * H)(s.questionnaires[0]).reason, `Opens at ${new Date(DAY0 + 5 * H).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`);
  assert.equal(at(DAY0 + 12 * H)(s.questionnaires[1]).state, 'locked');
  assert.equal(at(DAY0 + 12 * H)(s.questionnaires[1]).opensAt, DAY0 + 14 * H);
  assert.equal(at(DAY0 + 12 * H)(s.questionnaires[2]).state, 'upcoming'); // from day 5
  assert.equal(at(DAY0 + 12 * H)(s.questionnaires[2]).opensAt, new Date(2026, 9, 30, 10).getTime());
  complete(s, 'u5', 3, DAY0 + 12 * H);
  assert.deepEqual([at(DAY0 + 13 * H)(s.questionnaires[3]).state, at(DAY0 + DAY + 13 * H)(s.questionnaires[3]).state], ['completed', 'completed']);
});

test('a window into the next day closes when that day\'s first check-in opens', () => {
  const s = study({ responseMinutes: 0, checkins: [
    { questionnaireId: 1, startType: 'fixed', startTime: 7 * H, endType: 'fixed', endTime: 9 * H },
    { questionnaireId: 4, startType: 'fixed', startTime: 21 * H, endType: 'fixed', endTime: 8 * H, endNextDay: true },
  ] });
  assert.equal(states(s, 'u6', DAY0 + DAY + 6 * H + 59 * M).Evening, 'available');
  const at7 = states(s, 'u6', DAY0 + DAY + 7 * H);
  assert.deepEqual([at7.Morning, at7.Evening], ['available', 'locked']);
});

/** SSRC-style windows: answerable 06:00-10:00 around the usual 08:00 wake-up, prompted at wake-up (else 08:30),
 *  then fixed daytime ranges and an evening answerable 19:00-04:00, with an hour's cool-down. */
function windowStudy(): EsmiraStudy {
  return study({ wakeDefault: 8 * H, cooldownMinutes: 60, checkins: [
    { questionnaireId: 1, startType: 'usualWake', startOffsetMin: -120, endType: 'usualWake', endOffsetMin: 120, available: 'range', prompt: 'wake', promptGraceMin: 30 },
    { questionnaireId: 2, startType: 'fixed', startTime: 10 * H, endType: 'fixed', endTime: 11 * H },
    { questionnaireId: 3, startType: 'fixed', startTime: 11 * H + 30 * M, endType: 'fixed', endTime: 14 * H },
    { questionnaireId: 4, startType: 'fixed', startTime: 19 * H, endType: 'fixed', endTime: 4 * H, available: 'range', prompt: 'none' },
  ] as ScheduleCheckin[] });
}

test('a check-in answerable throughout its range opens before its prompt', () => {
  const s = windowStudy();
  const morning = studyAvailability(s, 'u8', JOINED, DAY0 + 5 * H, loadCompletions(s.id, 'u8'))(s.questionnaires[0]);
  assert.deepEqual([morning.state, morning.opensAt], ['locked', DAY0 + 6 * H]);
  assert.equal(states(s, 'u8', DAY0 + 6 * H + 15 * M).Morning, 'available'); // the prompt comes at 08:30
  assert.equal(states(s, 'u8', DAY0 + 10 * H).Morning, 'locked');
  assert.equal(states(s, 'u8', DAY0 + DAY + 3 * H + 59 * M).Evening, 'available'); // until 04:00
  assert.equal(states(s, 'u8', DAY0 + DAY + 4 * H).Evening, 'locked');
});

test('the cool-down locks the next check-in for an hour after one is completed', () => {
  const s = windowStudy();
  complete(s, 'u9', 1, DAY0 + 10 * H + 50 * M);
  const daytime2 = studyAvailability(s, 'u9', JOINED, DAY0 + 11 * H + 40 * M, loadCompletions(s.id, 'u9'))(s.questionnaires[2]);
  assert.deepEqual([daytime2.state, daytime2.opensAt], ['locked', DAY0 + 11 * H + 50 * M]);
  assert.equal(states(s, 'u9', DAY0 + 11 * H + 50 * M)['Daytime 2'], 'available');
});

test('Android: planned ahead, re-planned when the morning check-in is answered, prompts left out when answered', () => {
  const s = windowStudy();
  const at = (now: number) => planReminders(s, JOINED, now, loadCompletions(s.id, 'u10'), { userId: 'u10', horizonDays: 0 })
    .filter((r) => r.at < DAY0 + DAY).map((r) => [r.questionnaireId, (r.at - DAY0) / M]);
  // No wearable: the morning at the usual wake-up + 30 min; the evening has no prompt.
  assert.deepEqual(at(DAY0 + 5 * H), [[1, 510], [2, 600], [3, 690]]);
  complete(s, 'u10', 0, DAY0 + 6 * H + 30 * M);
  // Answered at 06:30: no 08:30 prompt, and nothing opens within the hour after it.
  assert.deepEqual(at(DAY0 + 6 * H + 31 * M), [[2, 600], [3, 690]]);
  // With the wake-up from a wearable, the morning check-in is the server's to prompt.
  const sensor = study({ ...windowStudy().schedule, wakeSensor: true });
  assert.deepEqual(planReminders(sensor, JOINED, DAY0 + 5 * H, {}, { userId: 'u11', horizonDays: 0 }).filter((r) => r.at < DAY0 + DAY).map((r) => r.questionnaireId), [2, 3]);
});

test('upgraded signal times of one questionnaire: a completion counts for the window it was made in', () => {
  const s = study({ checkins: [
    { questionnaireId: 1, parallel: true, startType: 'fixed', startTime: 10 * H, endType: 'fixed', endTime: 10 * H, available: 'day' },
    { questionnaireId: 1, parallel: true, startType: 'fixed', startTime: 15 * H, endType: 'fixed', endTime: 15 * H, available: 'day' },
  ] });
  complete(s, 'u12', 0, DAY0 + 16 * H); // both windows are open; the earlier one gets it
  assert.equal(states(s, 'u12', DAY0 + 16 * H + M).Morning, 'available');
  complete(s, 'u12', 0, DAY0 + 16 * H + 2 * M);
  assert.equal(states(s, 'u12', DAY0 + 16 * H + 3 * M).Morning, 'locked');
});

test('study helpers: auto-start, length, prompts', () => {
  const s = study({ checkins: [
    { questionnaireId: 1, autoStart: true, prompt: 'none', limit: 'once', available: 'range', parallel: true },
    { questionnaireId: 2, dayUntil: 13 },
    { questionnaireId: 3, dayUntil: 6, prompt: 'none' },
  ] });
  assert.deepEqual([...autoStartIds(s)], [1]);
  assert.deepEqual(studyPeriod(s), { first: 0, last: 13 }); // the unprompted one-off is not a study day
  assert.equal(hasPrompts(s), true);
  assert.equal(hasPrompts(study({ checkins: [{ questionnaireId: 1, prompt: 'none' }] })), false);
});

/** SSRC: day-zero sleep times open any time, then four check-ins on days 1-3 (Morning on getting up, two random
 *  daytime ones, Evening 2 h before the usual bedtime). */
function ssrc(): EsmiraStudy {
  const days = { dayFrom: 1, dayUntil: 3 };
  return {
    id: 4001, title: 'Demo',
    questionnaires: [q(1, 'Morning'), q(2, 'Daytime 1'), q(3, 'Daytime 2'), q(4, 'Evening'), q(5, 'Sleep times')],
    schedule: {
      segments: 4, wakeSensor: true, wakeFromCheckin: true, wakeDefault: 8 * H, bedDefault: 23 * H,
      checkins: [
        { questionnaireId: 1, ...days, startType: 'wake', endType: 'wake', endOffsetMin: 180, available: 'range', prompt: 'wake', promptGraceMin: 30 },
        { questionnaireId: 2, ...days, startSegment: 1, endSegment: 2, prompt: 'random' },
        { questionnaireId: 3, ...days, startSegment: 2, endSegment: 3, prompt: 'random' },
        { questionnaireId: 4, ...days, startType: 'bed', startOffsetMin: -120, endType: 'bed', endOffsetMin: 60, available: 'range' },
        { questionnaireId: 5, parallel: true, startType: 'fixed', startTime: 0, endType: 'fixed', endTime: 0, endNextDay: true,
          prompt: 'none', available: 'range', limit: 'once', autoStart: true },
      ],
    },
  } as EsmiraStudy;
}

test('before the study starts: the time when the answers fix it, else the date', () => {
  const s = ssrc();
  const joined = DAY0 + 14 * H; // enrolled at 14:00, day 1 is tomorrow
  const availability = studyAvailability(s, 'u20', joined, joined + 45 * M, loadCompletions(s.id, 'u20'));
  const reason = (i: number) => availability(s.questionnaires[i]);
  const tomorrow = new Date(DAY0 + DAY).toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
  const at = (h: number) => new Date(DAY0 + h * H).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  assert.equal(reason(0).state, 'upcoming');
  assert.equal(reason(0).reason, `Opens ${tomorrow}, after you get up`); // the mat or the morning answer tells
  assert.equal(reason(1).reason, `Available on ${tomorrow}`); // a random prompt: not given away
  assert.equal(reason(2).reason, `Available on ${tomorrow}`);
  assert.equal(reason(3).reason, `Opens ${tomorrow}, ${at(21)}`); // 2 h before the usual 23:00 bedtime
  assert.equal(reason(3).opensAt, DAY0 + DAY + 21 * H);
});

test('the header counts study days, the enrolment day as day 0', () => {
  const s = ssrc();
  const joined = DAY0 + 14 * H;
  const tomorrow = new Date(DAY0 + DAY).toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
  assert.equal(studyDayLabel(s, joined, joined + M), `Day 0 · Starts ${tomorrow}`);
  assert.equal(studyDayLabel(s, joined, DAY0 + DAY + 6 * H), 'Day 1 of 3'); // a calendar day, not 24 h after enrolling
  assert.equal(studyDayLabel(s, joined, DAY0 + 3 * DAY + 22 * H), 'Day 3 of 3');
  assert.equal(studyDayLabel(s, joined, DAY0 + 4 * DAY + H), null); // over
  // A study that starts on the enrolment day and runs days 0-13 has 14 days.
  const now = study({ checkins: [{ questionnaireId: 1, dayUntil: 13 }] });
  assert.equal(studyDayLabel(now, joined, joined + M), 'Day 1 of 14');
  assert.equal(studyDayLabel(now, joined, DAY0 + 13 * DAY + H), 'Day 14 of 14');
  assert.equal(studyDayLabel(study({ checkins: [{ questionnaireId: 1 }] }), joined, DAY0 + 20 * DAY), 'Day 21');
  // Everything still to come (e.g. a start date): the first opening.
  assert.equal(studyDayLabel(now, joined, DAY0 + 2 * DAY, DAY0 + 5 * DAY), `Starts ${new Date(DAY0 + 5 * DAY).toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' })}`);
});
