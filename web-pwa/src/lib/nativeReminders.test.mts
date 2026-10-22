import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { PlannedReminder } from './notificationPlan.ts';
import { diffSchedule, syncReminders, requestReminderPermission, clearReminders } from './nativeReminders.ts';

const r = (id: number): PlannedReminder => ({ id, at: id * 1000, title: 't', body: 'b', questionnaireId: 1, occurrenceKey: `k${id}` });

test('diffSchedule cancels stale ids and adds only new ones', () => {
  assert.deepEqual(diffSchedule([1, 2, 3], [r(2), r(3), r(4)]), { cancel: [1], add: [r(4)] });
  assert.deepEqual(diffSchedule([], [r(1)]), { cancel: [], add: [r(1)] });
  assert.deepEqual(diffSchedule([1], []), { cancel: [1], add: [] });
});

test('everything is a no-op outside the native app', async () => {
  assert.equal(await syncReminders([r(1)], 7), null);
  assert.equal(await requestReminderPermission(), 'unsupported');
  await clearReminders(); // must not throw
});

function fakeNative(display: string, pending: number[] = []) {
  const calls: { cancel: number[][]; schedule: unknown[][]; requested: number } = { cancel: [], schedule: [], requested: 0 };
  (globalThis as Record<string, unknown>).Capacitor = {
    isNativePlatform: () => true,
    Plugins: {
      LocalNotifications: {
        checkPermissions: async () => ({ display }),
        requestPermissions: async () => { calls.requested++; return { display: 'granted' }; },
        getPending: async () => ({ notifications: pending.map((id) => ({ id })) }),
        cancel: async (o: { notifications: { id: number }[] }) => { calls.cancel.push(o.notifications.map((n) => n.id)); },
        schedule: async (o: { notifications: unknown[] }) => { calls.schedule.push(o.notifications); },
        createChannel: async () => {},
      },
    },
  };
  return calls;
}

test('native: schedules the diff when permission is granted', async () => {
  const calls = fakeNative('granted', [1, 9]);
  const res = await syncReminders([r(1), r(2)], 7);
  assert.deepEqual(res, { added: 1, cancelled: 1 });
  assert.deepEqual(calls.cancel, [[9]]);
  const sent = calls.schedule[0] as { id: number; channelId: string; schedule: { at: Date; allowWhileIdle: boolean } }[];
  assert.equal(sent.length, 1);
  assert.equal(sent[0].id, 2);
  assert.equal(sent[0].channelId, 'reminders');
  assert.equal(sent[0].schedule.allowWhileIdle, true);
  assert.ok(sent[0].schedule.at instanceof Date);
});

test('native: does not schedule without permission, and asks only when undecided', async () => {
  const denied = fakeNative('denied');
  assert.equal(await syncReminders([r(1)], 7), null);
  assert.equal(denied.schedule.length, 0);
  assert.equal(await requestReminderPermission(), 'denied');
  assert.equal(denied.requested, 0);
  const prompt = fakeNative('prompt');
  assert.equal(await requestReminderPermission(), 'granted');
  assert.equal(prompt.requested, 1);
  delete (globalThis as Record<string, unknown>).Capacitor;
});
