import test from 'node:test';
import assert from 'node:assert/strict';
import { isNativeApp, clientAppType } from './platform.ts';

test('web by default', () => {
  delete (globalThis as any).Capacitor;
  assert.equal(isNativeApp(), false);
  assert.equal(clientAppType(), 'Web');
});

test('native when Capacitor reports a native platform', () => {
  (globalThis as any).Capacitor = { isNativePlatform: () => true };
  assert.equal(clientAppType(), 'AndroidApp');
  (globalThis as any).Capacitor = { isNativePlatform: () => false };
  assert.equal(clientAppType(), 'Web');
  delete (globalThis as any).Capacitor;
});

test('the native app counts as installed, so install prompts stand down', async () => {
  const { isStandalone, isInAppBrowser } = await import('./pwaInstall.ts');
  delete (globalThis as any).Capacitor;
  assert.equal(isStandalone(), false);
  (globalThis as any).Capacitor = { isNativePlatform: () => true };
  assert.equal(isStandalone(), true);
  assert.equal(isInAppBrowser(), false);
  delete (globalThis as any).Capacitor;
});
