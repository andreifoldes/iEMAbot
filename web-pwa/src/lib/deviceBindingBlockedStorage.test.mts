/**
 * The device token when the browser refuses storage (a private window). A file of its own: the token is kept in memory of the
 * module, so another test creating one first would make this one pass without checking anything.
 *
 * Run with `npm test`.
 */
import { it } from 'node:test';
import assert from 'node:assert/strict';
import { getOrCreateDeviceToken } from './deviceBinding.ts';

it('still works when the browser blocks storage: one token for as long as the page is open', () => {
  const refuse = () => { throw new Error('storage is blocked'); };
  (globalThis as { localStorage?: unknown }).localStorage = { getItem: refuse, setItem: refuse, removeItem: refuse };
  const token = getOrCreateDeviceToken();
  assert.match(token, /^[A-Za-z0-9._:-]{8,128}$/);
  assert.equal(getOrCreateDeviceToken(), token);
});
