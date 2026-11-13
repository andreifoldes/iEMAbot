/**
 * Tests for the "one device at a time" client side: the device token every request carries, and what the app does when the
 * server says another device holds the participant id. The server side (who holds an id, the 409) is tested in PHPUnit and
 * end to end by scripts/demo/restricted-ids-browser.py.
 *
 * Run with `npm test`.
 */
import { afterEach, beforeEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
  DEVICE_TOKEN_KEY, clearWrongDevice, deviceContext, deviceField, deviceQuery, getOrCreateDeviceToken, isWrongDevice, markWrongDevice,
  noteRefusal, onWrongDevice, scopeKey, singleDevice,
} from './deviceBinding.ts';

/** The rule api/pid_lock.php applies (ParticipantDeviceLock::cleanToken): a token outside it is not accepted at all. */
const SERVER_TOKEN = /^[A-Za-z0-9._:-]{8,128}$/;

/** A storage like the browser's, backed by a map, so the code under test reads and writes it as it does in a browser. */
function installStorage(opts: { blocked?: boolean } = {}): Map<string, string> {
  const data = new Map<string, string>();
  const refuse = () => { throw new Error('storage is blocked'); };
  (globalThis as { localStorage?: unknown }).localStorage = {
    getItem: (key: string) => (opts.blocked ? refuse() : data.get(key) ?? null),
    setItem: (key: string, value: string) => (opts.blocked ? refuse() : void data.set(key, value)),
    removeItem: (key: string) => void data.delete(key),
  };
  return data;
}

beforeEach(() => { clearWrongDevice(); });
afterEach(() => { delete (globalThis as { localStorage?: unknown }).localStorage; });

describe('the device token', () => {
  it('is made once, stored, and the same on every later request', () => {
    const data = installStorage();
    const first = getOrCreateDeviceToken();
    assert.equal(data.get(DEVICE_TOKEN_KEY), first);
    assert.equal(getOrCreateDeviceToken(), first);
    assert.deepEqual(deviceField(), { deviceToken: first });
  });
  it('is one the server accepts', () => {
    installStorage();
    assert.match(getOrCreateDeviceToken(), SERVER_TOKEN);
  });
  it('keeps a token an earlier version of the app stored', () => {
    const data = installStorage();
    data.set(DEVICE_TOKEN_KEY, 'dt-lq3x9k2a');
    assert.equal(getOrCreateDeviceToken(), 'dt-lq3x9k2a');
  });
  it('goes into a query string safely', () => {
    const data = installStorage();
    data.set(DEVICE_TOKEN_KEY, 'abc def&x=1');
    assert.equal(deviceQuery(), '&deviceToken=abc%20def%26x%3D1');
  });
});

describe('singleDevice', () => {
  it('is on only when the researcher chose one device at a time', () => {
    assert.equal(singleDevice({}), false);
    assert.equal(singleDevice({ pidDevices: 'multi' }), false);
    assert.equal(singleDevice({ pidDevices: 'single' }), true);
  });
  it('is off for a local-only study, which sends nothing', () => {
    assert.equal(singleDevice({ pidDevices: 'single', webLocalOnly: true }), false);
  });
});

describe('another device holds the id', () => {
  const refusal = { success: false, errorCode: 409, error: 'This participant ID is active on another device.' };
  const P1 = scopeKey(7, 'P1');

  it('is recognised from the server refusal, and only from that', () => {
    const ctx = deviceContext(7, 'P1');
    assert.equal(noteRefusal({ success: true, dataset: {} }, ctx), false);
    assert.equal(noteRefusal({ success: false, errorCode: 0, error: 'Missing data' }, ctx), false);
    assert.equal(noteRefusal({ success: false, errorCode: 403, error: 'not listed' }, ctx), false);
    assert.equal(noteRefusal({ success: true, errorCode: 409 }, ctx), false);
    assert.equal(noteRefusal(null, ctx), false);
    assert.equal(noteRefusal('409', ctx), false);
    assert.equal(isWrongDevice(P1), false);
    assert.equal(noteRefusal(refusal, ctx), true);
    assert.equal(isWrongDevice(P1), true);
  });
  it('belongs to one participant id in one study: other ids and studies carry on', () => {
    noteRefusal(refusal, deviceContext(7, 'P1'));
    assert.equal(isWrongDevice(scopeKey(7, 'P1')), true);
    assert.equal(isWrongDevice(scopeKey(7, 'P2')), false);
    assert.equal(isWrongDevice(scopeKey(8, 'P1')), false);
  });
  it('tells the listeners once per id, however many requests are refused, and again after a transfer', () => {
    const told: string[] = [];
    const stop = onWrongDevice((scope) => { told.push(scope); });
    noteRefusal(refusal, deviceContext(7, 'P1'));
    noteRefusal(refusal, deviceContext(7, 'P1'));
    markWrongDevice(deviceContext(7, 'P1'));
    assert.deepEqual(told, [P1]);
    noteRefusal(refusal, deviceContext(7, 'P2'));
    assert.deepEqual(told, [P1, scopeKey(7, 'P2')]);
    clearWrongDevice(P1);
    assert.equal(isWrongDevice(P1), false);
    assert.equal(isWrongDevice(scopeKey(7, 'P2')), true, 'taking one id over does not forgive another');
    noteRefusal(refusal, deviceContext(7, 'P1'));
    assert.equal(told.length, 3);
    stop();
    clearWrongDevice();
    noteRefusal(refusal, deviceContext(7, 'P1'));
    assert.equal(told.length, 3);
  });
  it('ignores a refusal to a request that began before the participant took the id over', () => {
    const inFlight = deviceContext(7, 'P1'); // sent while the other device still held the id
    noteRefusal(refusal, deviceContext(7, 'P1'));
    clearWrongDevice(P1); // the participant taps Transfer
    assert.equal(noteRefusal(refusal, inFlight), true, 'it is still a refusal');
    assert.equal(isWrongDevice(P1), false, 'but an out-of-date one');
    noteRefusal(refusal, deviceContext(7, 'P1')); // a request sent after the transfer that is refused is a real one
    assert.equal(isWrongDevice(P1), true);
  });
});
