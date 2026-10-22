import { test } from 'node:test';
import assert from 'node:assert/strict';
import { configureServerNudges, cancelServerNudges } from './serverNudges.ts';

test('no-op outside the native app', async () => {
  delete (globalThis as Record<string, unknown>).Capacitor;
  assert.equal(await configureServerNudges('https://x/esmira/', 1, 'u'), false);
  await cancelServerNudges(); // must not throw
});

test('native: passes the target through, omits an unknown joinedAt, and survives plugin errors', async () => {
  const calls: unknown[] = [];
  let fail = false;
  (globalThis as Record<string, unknown>).Capacitor = {
    isNativePlatform: () => true,
    Plugins: { ServerNudges: {
      configure: async (o: unknown) => { calls.push(o); if (fail) throw new Error('boom'); },
      cancel: async () => { calls.push('cancel'); },
      pollNow: async () => {},
    } },
  };
  assert.equal(await configureServerNudges('https://x/esmira/', 7, 'u1'), true);
  assert.equal(await configureServerNudges('https://x/esmira/', 7, 'u1', 1234), true);
  assert.deepEqual(calls[0], { server: 'https://x/esmira/', studyId: 7, userId: 'u1' });
  assert.deepEqual(calls[1], { server: 'https://x/esmira/', studyId: 7, userId: 'u1', joinedAt: 1234 });
  fail = true;
  assert.equal(await configureServerNudges('https://x/esmira/', 7, 'u1'), false);
  await cancelServerNudges();
  assert.equal(calls.at(-1), 'cancel');
  delete (globalThis as Record<string, unknown>).Capacitor;
});
