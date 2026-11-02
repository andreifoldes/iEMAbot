import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { EsmiraStudy } from '../types.ts';
import { localDay, markNudgeShown, nudgeEnabled, nudgeKind, nudgeShownToday } from './notificationNudge.ts';

const mem = new Map<string, string>();
(globalThis as unknown as { localStorage: Storage }).localStorage = {
  getItem: (k: string) => mem.get(k) ?? null,
  setItem: (k: string, v: string) => { mem.set(k, v); },
  removeItem: (k: string) => { mem.delete(k); },
  clear: () => mem.clear(),
  key: () => null,
  length: 0,
} as Storage;

test('on unless the research team turned it off', () => {
  assert.equal(nudgeEnabled({} as EsmiraStudy), true);
  assert.equal(nudgeEnabled({ notificationNudge: true } as EsmiraStudy), true);
  assert.equal(nudgeEnabled({ notificationNudge: false } as EsmiraStudy), false);
});

test('shown at most once per local day, per study and participant', () => {
  const morning = new Date(2026, 9, 29, 8, 0);
  const evening = new Date(2026, 9, 29, 23, 59);
  const nextDay = new Date(2026, 9, 30, 0, 1);
  assert.equal(localDay(morning), '2026-10-29');
  assert.equal(nudgeShownToday(7, 'u', morning), false);
  markNudgeShown(7, 'u', morning);
  assert.equal(nudgeShownToday(7, 'u', evening), true);
  assert.equal(nudgeShownToday(7, 'u', nextDay), false);
  assert.equal(nudgeShownToday(8, 'u', evening), false);
  assert.equal(nudgeShownToday(7, 'v', evening), false);
});

test('offers the system dialog while it can still be shown, the settings once it cannot', () => {
  assert.equal(nudgeKind('granted', false), null);
  assert.equal(nudgeKind('default', false), 'ask');
  assert.equal(nudgeKind('denied', false), 'blocked');
  // iOS Safari before the app is on the Home Screen: no web push until it is installed
  assert.equal(nudgeKind('unsupported', true), 'install');
  // a browser without web push, or an Android app that cannot check: nothing to offer
  assert.equal(nudgeKind('unsupported', false), null);
});
