/**
 * Tests for the researcher's "smartphone only" study setting (webPhoneOnly): the pure
 * gate decision, the per-study "I'm on my phone — continue here" override, and the link
 * handed to the phone.
 *
 * Run with `npm test`.
 */
import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { handoffUrl, isPhoneGated, phoneOverrideKey, readPhoneOverride, setPhoneOverride } from './phoneGate.ts';

const realLocalStorage = Object.getOwnPropertyDescriptor(globalThis, 'localStorage');
afterEach(() => {
  if (realLocalStorage) Object.defineProperty(globalThis, 'localStorage', realLocalStorage);
  else delete (globalThis as Record<string, unknown>).localStorage;
});

function fakeLocalStorage(store: Record<string, string> = {}) {
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (k: string) => (k in store ? store[k] : null),
      setItem: (k: string, v: string) => { store[k] = v; },
    },
  });
  return store;
}

describe('isPhoneGated', () => {
  it('gates a non-phone only when the study opts in', () => {
    assert.equal(isPhoneGated({ webPhoneOnly: true }, { phone: false }, false), true);
  });

  it('lets phones through', () => {
    assert.equal(isPhoneGated({ webPhoneOnly: true }, { phone: true }, false), false);
  });

  it('lets an exempt device through (already consented here, or "continue here")', () => {
    assert.equal(isPhoneGated({ webPhoneOnly: true }, { phone: false }, true), false);
  });

  it('is off by default: unset, false or non-boolean values never gate (existing studies are unchanged)', () => {
    for (const webPhoneOnly of [undefined, false, null, 0, 'true', 1]) {
      assert.equal(
        isPhoneGated({ webPhoneOnly } as { webPhoneOnly?: boolean }, { phone: false }, false),
        false,
        String(webPhoneOnly),
      );
    }
  });
});

describe('phone override', () => {
  it('is off until set, then reads back as set', () => {
    fakeLocalStorage();
    assert.equal(readPhoneOverride(7), false);
    setPhoneOverride(7);
    assert.equal(readPhoneOverride(7), true);
  });

  it('is remembered per study, on the device (localStorage), under a stable key', () => {
    const store = fakeLocalStorage();
    setPhoneOverride(7);
    assert.equal(store[phoneOverrideKey(7)], '1');
    assert.equal(phoneOverrideKey(7), 'esmira_phone_override_7');
    assert.equal(readPhoneOverride(8), false, 'another study is not exempt');
  });

  it('never throws when storage is unavailable (private mode, no window)', () => {
    Object.defineProperty(globalThis, 'localStorage', {
      configurable: true,
      get() { throw new Error('SecurityError'); },
    });
    assert.equal(readPhoneOverride(7), false);
    assert.doesNotThrow(() => setPhoneOverride(7));
  });
});

describe('handoffUrl', () => {
  it('keeps the invite code and participant ID and drops the hash', () => {
    assert.equal(
      handoffUrl('https://x.test/pwa/?key=demo&pid=1155#chat', 'demo'),
      'https://x.test/pwa/?key=demo&pid=1155',
    );
  });

  it('adds the remembered code when the page was opened without one (installed-app relaunch)', () => {
    assert.equal(handoffUrl('https://x.test/pwa/', 'ssrc'), 'https://x.test/pwa/?key=ssrc');
    assert.equal(handoffUrl('https://x.test/pwa/?pid=P7', 'ssrc'), 'https://x.test/pwa/?pid=P7&key=ssrc');
  });

  it('does not duplicate an access_key alias', () => {
    assert.equal(handoffUrl('https://x.test/pwa/?access_key=ssrc', 'ssrc'), 'https://x.test/pwa/?access_key=ssrc');
  });

  it('returns an unparseable href untouched instead of throwing', () => {
    assert.equal(handoffUrl('not a url', 'demo'), 'not a url');
  });
});
