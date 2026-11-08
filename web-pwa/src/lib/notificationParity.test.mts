/**
 * Parity with web push: the server's NotificationSpec (PHP) and this app's notificationSpec (used by the Android
 * app's alarms) word every notification the same. Both run test/fixtures/channels/notifications.json; PHP side:
 * test/backend/notifications/NotificationParityTest.php. When notifications are sent is held together by the
 * schedule's fixture (schedule.test.mts).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { coalesce, hash32, notificationTitle, withDeadline } from './notificationSpec.ts';
import './testI18n.mts';

const fx = JSON.parse(readFileSync(new URL('../../../test/fixtures/channels/notifications.json', import.meta.url), 'utf8'));

test('notification content matches the shared fixtures', () => {
  const c = fx.content;
  for (const k of c.title) assert.equal(notificationTitle(k.q, k.study, k.override ?? ''), k.expected);
  for (const k of c.withDeadline) assert.equal(withDeadline(k.body, k.tod), k.expected);
  for (const k of c.coalesce) assert.deepEqual(coalesce(k.items, k.study), k.expected);
  for (const k of c.hash32) assert.equal(hash32(k.s), k.expected, k.s);
});
