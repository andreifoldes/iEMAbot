/**
 * Tests for the persisted display preferences: OS-seeded defaults, round-tripping,
 * and tolerance of corrupt or unavailable storage.
 *
 * Run with `npm test`.
 */
import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { A11Y_PREFS_KEY, loadA11yPrefs, saveA11yPrefs } from './a11yPrefs.ts';

const realLocalStorage = Object.getOwnPropertyDescriptor(globalThis, 'localStorage');
const realWindow = Object.getOwnPropertyDescriptor(globalThis, 'window');

function fakeStorage(initial: Record<string, string> = {}): Map<string, string> {
  const data = new Map(Object.entries(initial));
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (k: string) => data.get(k) ?? null,
      setItem: (k: string, v: string) => { data.set(k, v); },
    },
  });
  return data;
}

function fakeOs(matching: string[]): void {
  Object.defineProperty(globalThis, 'window', {
    configurable: true,
    value: { matchMedia: (q: string) => ({ matches: matching.includes(q) }) },
  });
}

afterEach(() => {
  if (realLocalStorage) Object.defineProperty(globalThis, 'localStorage', realLocalStorage);
  else delete (globalThis as Record<string, unknown>).localStorage;
  if (realWindow) Object.defineProperty(globalThis, 'window', realWindow);
  else delete (globalThis as Record<string, unknown>).window;
});

describe('loadA11yPrefs', () => {
  it('seeds first-run defaults from the OS settings', () => {
    fakeStorage();
    fakeOs(['(prefers-color-scheme: dark)', '(prefers-contrast: more)', '(prefers-reduced-motion: reduce)']);
    assert.deepEqual(loadA11yPrefs(), { dark: true, highContrast: true, reduceMotion: true, textSize: 'normal' });
  });

  it('falls back to plain defaults when matchMedia is unavailable', () => {
    fakeStorage();
    assert.deepEqual(loadA11yPrefs(), { dark: false, highContrast: false, reduceMotion: false, textSize: 'normal' });
  });

  it('lets stored choices override the OS (explicit light mode on a dark phone)', () => {
    fakeStorage();
    fakeOs(['(prefers-color-scheme: dark)']);
    saveA11yPrefs({ dark: false, highContrast: false, reduceMotion: false, textSize: 'xlarge' });
    assert.deepEqual(loadA11yPrefs(), { dark: false, highContrast: false, reduceMotion: false, textSize: 'xlarge' });
  });

  it('ignores corrupt JSON and invalid field values', () => {
    const data = fakeStorage({ [A11Y_PREFS_KEY]: '{not json' });
    assert.equal(loadA11yPrefs().textSize, 'normal');
    data.set(A11Y_PREFS_KEY, JSON.stringify({ dark: 'yes', textSize: 'huge', highContrast: true }));
    assert.deepEqual(loadA11yPrefs(), { dark: false, highContrast: true, reduceMotion: false, textSize: 'normal' });
  });

  it('survives storage that throws', () => {
    Object.defineProperty(globalThis, 'localStorage', {
      configurable: true,
      get() { throw new Error('blocked'); },
    });
    assert.equal(loadA11yPrefs().textSize, 'normal');
    assert.doesNotThrow(() => saveA11yPrefs({ dark: true, highContrast: false, reduceMotion: false, textSize: 'large' }));
  });
});
