import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { PlannedReminder } from './notificationPlan.ts';
import { diffSchedule, syncReminders, requestReminderPermission, clearReminders } from './nativeReminders.ts';

const r = (id: number): PlannedReminder => ({ id, at: id * 1000, deadline: id * 1000 + 3600_000, title: 't', body: 'b', questionnaireId: 1, occurrenceKey: `k${id}`, keys: [`availability:1:${id * 1000}`] });

const held = (...ids: number[]) => ids.map((id) => ({ id, at: id * 1000, deadline: id * 1000 + 3600_000 }));

test('diffSchedule cancels stale ids and adds only new ones', () => {
  assert.deepEqual(diffSchedule(held(1, 2, 3), [r(2), r(3), r(4)]), { cancel: [1], add: [r(4)] });
  assert.deepEqual(diffSchedule([], [r(1)]), { cancel: [], add: [r(1)] });
  assert.deepEqual(diffSchedule(held(1), []), { cancel: [1], add: [] });
});

test('diffSchedule re-schedules a held reminder whose deadline is missing (older build) or changed', () => {
  assert.deepEqual(diffSchedule([{ id: 2, at: 2000 }, ...held(3)], [r(2), r(3)]), { cancel: [], add: [r(2)] });
  assert.deepEqual(diffSchedule([{ id: 2, at: 2000, deadline: 1 }], [r(2)]), { cancel: [], add: [r(2)] });
});

test('everything is a no-op outside the native app', async () => {
  assert.equal(await syncReminders([r(1)], 7), null);
  assert.equal(await requestReminderPermission(), 'unsupported');
  await clearReminders(); // must not throw
});

function fakeNative(display: string, pending: number[] = [], legacy: number[] = [], fired = 0) {
  const calls = { cancel: [] as number[][], schedule: [] as unknown[][], legacyCancel: [] as number[][], cleared: 0, requested: 0 };
  (globalThis as Record<string, unknown>).Capacitor = {
    isNativePlatform: () => true,
    Plugins: {
      LocalNotifications: {
        checkPermissions: async () => ({ display }),
        requestPermissions: async () => { calls.requested++; return { display: 'granted' }; },
        getPending: async () => ({ notifications: legacy.map((id) => ({ id })) }),
        cancel: async (o: { notifications: { id: number }[] }) => { calls.legacyCancel.push(o.notifications.map((n) => n.id)); },
      },
      Reminders: {
        schedule: async (o: { notifications: unknown[] }) => { calls.schedule.push(o.notifications); },
        cancel: async (o: { ids: number[] }) => { calls.cancel.push(o.ids); },
        getPending: async () => ({ notifications: held(...pending) }),
        takeFired: async () => ({ count: fired }),
        clearShown: async () => { calls.cleared++; },
      },
    },
  };
  return calls;
}

test('native: schedules the diff with the Reminders plugin when permission is granted', async () => {
  const calls = fakeNative('granted', [1, 9], [], 3);
  const res = await syncReminders([r(1), r(2)], 7);
  assert.deepEqual(res, { added: 1, cancelled: 1, fired: 3 });
  assert.deepEqual(calls.cancel, [[9]]);
  // title/body only: the look and the one-at-a-time stacking are applied natively (ReminderNotifier)
  // with the deadline, at which the phone takes the notification down (ReminderNotifier.setTimeoutAfter)
  assert.deepEqual(calls.schedule[0], [{ id: 2, at: 2000, deadline: 3602000, title: 't', body: 'b', key: 'availability:1:2000' }]);
});

test('native: reminders an older build armed without a deadline are re-scheduled in place, not counted as new', async () => {
  const calls = fakeNative('granted', [], [], 0);
  const native = (globalThis as unknown as { Capacitor: { Plugins: { Reminders: { getPending: unknown } } } }).Capacitor.Plugins.Reminders;
  native.getPending = async () => ({ notifications: [{ id: 1, at: 1000 }] });
  const res = await syncReminders([r(1)], 7);
  assert.deepEqual(res, { added: 0, cancelled: 0, fired: 0 });
  assert.deepEqual(calls.schedule[0], [{ id: 1, at: 1000, deadline: 3601000, title: 't', body: 'b', key: 'availability:1:1000' }]);
});

test('native: alarms an older build planned with local-notifications are cancelled, as they would stack', async () => {
  const calls = fakeNative('granted', [], [41, 42]);
  await syncReminders([], 7);
  assert.deepEqual(calls.legacyCancel, [[41, 42]]);
});

test('native: clearReminders cancels everything and takes down the shown notification', async () => {
  const calls = fakeNative('granted', [5, 6]);
  await clearReminders();
  assert.deepEqual(calls.cancel, [[5, 6]]);
  assert.equal(calls.cleared, 1);
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
