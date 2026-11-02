/**
 * Tests for the native IME timing bridge (nativeKeystrokes.ts): the clock conversion that puts
 * Android uptime stamps onto the DOM event clock, and the browser fallback (no plugin → null).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { startNativeKeystrokes, toDomClock } from './nativeKeystrokes.ts';

test('toDomClock uses the offset from the tighter round trip and drops unknown classes', () => {
  const rows = [
    { cls: 'letter', t: 50_100 },
    { cls: 'backspace', t: 50_250 },
    { cls: 'focus_lost', t: 50_300 }, // markers come from the DOM, never from native
    { cls: 'letter', t: Number.NaN },
  ];
  const out = toDomClock(rows, [
    { offset: 49_000, rtt: 12 },
    { offset: 49_002, rtt: 3 },
  ]);
  assert.deepEqual(out, [
    { cls: 'letter', timeStamp: 1_098 },
    { cls: 'backspace', timeStamp: 1_248 },
  ]);
});

test('startNativeKeystrokes is null in a browser (no Capacitor plugin)', () => {
  assert.equal(startNativeKeystrokes(), null);
});

test('a native session converts the stop() rows onto the DOM clock', async () => {
  const g = globalThis as { Capacitor?: unknown };
  // performance.now() advances a little across each fake call; offset ≈ 10_000.
  g.Capacitor = {
    Plugins: {
      KeystrokeTiming: {
        start: async () => ({ now: performance.now() + 10_000, session: 7 }),
        stop: async ({ session }: { session: number }) => ({
          now: performance.now() + 10_000,
          rows: session === 7 ? [{ cls: 'letter', t: 10_500 }] : [],
        }),
      },
    },
  };
  try {
    const session = startNativeKeystrokes();
    assert.ok(session);
    const rows = await session.finish();
    assert.equal(rows.length, 1);
    assert.equal(rows[0].cls, 'letter');
    assert.ok(Math.abs(rows[0].timeStamp - 500) < 5);
    assert.deepEqual(await session.finish(), []); // second finish is a no-op
  } finally {
    delete g.Capacitor;
  }
});
