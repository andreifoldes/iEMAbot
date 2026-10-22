import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeServerBase, setServerBase, getApiRoot, clearServerBase } from './serverRoot.ts';

test('normalizeServerBase appends /esmira/ to a bare origin', () => {
  assert.equal(normalizeServerBase('https://example.org'), 'https://example.org/esmira/');
  assert.equal(normalizeServerBase('example.org'), 'https://example.org/esmira/');
});

test('normalizeServerBase keeps a custom path and trailing slash', () => {
  assert.equal(normalizeServerBase('https://example.org/study'), 'https://example.org/study/');
  assert.equal(normalizeServerBase('https://example.org/esmira/?key=1#x'), 'https://example.org/esmira/');
});

test('normalizeServerBase rejects non-http(s) and empty input', () => {
  assert.equal(normalizeServerBase(''), null);
  assert.equal(normalizeServerBase('javascript://x'), null);
  assert.equal(normalizeServerBase('ftp://example.org'), null);
});

test('getApiRoot falls back to the default, stored value wins', () => {
  const store = new Map<string, string>();
  (globalThis as any).localStorage = {
    getItem: (k: string) => store.get(k) ?? null,
    setItem: (k: string, v: string) => void store.set(k, v),
    removeItem: (k: string) => void store.delete(k),
  };
  assert.equal(getApiRoot(), '/esmira/');
  assert.equal(setServerBase('https://example.org'), 'https://example.org/esmira/');
  assert.equal(getApiRoot(), 'https://example.org/esmira/');
  clearServerBase();
  assert.equal(getApiRoot(), '/esmira/');
});
