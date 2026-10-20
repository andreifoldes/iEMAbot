/**
 * Tests for lib/pwaInstall.ts: iOS version / browser gating (Home-Screen install +
 * Web Push floors), in-app web-view detection, the desktop-Safari / Chromium /
 * Android UA classifiers, the live-environment detectors (run against a faked
 * `window` / `navigator`), platform classification, and the early
 * `beforeinstallprompt` capture.
 *
 * Run with `npm test` (Node's built-in runner + native TS type-stripping).
 */
import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
  classifyInstallPlatform,
  findAppHintOS,
  getIOSBrowser,
  getIOSBrowserVersion,
  getIOSSupport,
  getIOSVersion,
  isAndroid,
  isChromium,
  isInAppBrowser,
  isInAppBrowserUA,
  isIOS,
  isMacSafari,
  isMobileOrTablet,
  isPhone,
  isStandalone,
  resolveIOSSupport,
} from './pwaInstall.ts';
import { fakeInstallPromptEvent, installFakeBrowser, UA, type FakeBrowser } from './fakeBrowser.mts';

type PwaInstallModule = typeof import('./pwaInstall.ts');

/** A fresh copy of the module, so its module-level capture state starts empty. */
let freshCount = 0;
async function freshPwaInstall(): Promise<PwaInstallModule> {
  freshCount += 1;
  return (await import(`./pwaInstall.ts?fresh=${freshCount}`)) as PwaInstallModule;
}

let browser: FakeBrowser | null = null;
afterEach(() => {
  browser?.restore();
  browser = null;
});

describe('getIOSVersion', () => {
  it('parses iPhone OS version', () => {
    assert.deepEqual(getIOSVersion(UA.iphoneSafari164), { major: 16, minor: 4 });
    assert.deepEqual(getIOSVersion(UA.iphoneSafari156), { major: 15, minor: 6 });
    assert.deepEqual(getIOSVersion(UA.iphoneChrome113), { major: 16, minor: 5 });
  });

  it('parses iPad OS version ("CPU OS X_Y")', () => {
    assert.deepEqual(getIOSVersion(UA.ipadSafari164), { major: 16, minor: 4 });
  });

  it('returns null for non-iOS UAs (and never matches "Mac OS X")', () => {
    assert.equal(getIOSVersion(UA.androidChrome), null);
    assert.equal(getIOSVersion(UA.desktopChrome), null);
  });
});

describe('getIOSBrowser', () => {
  it('detects Safari and Chrome as first-class', () => {
    assert.equal(getIOSBrowser(UA.iphoneSafari164), 'safari');
    assert.equal(getIOSBrowser(UA.iphoneChrome113), 'chrome');
  });

  it('folds Firefox/Edge/other iOS browsers into "other"', () => {
    assert.equal(getIOSBrowser(UA.iphoneFirefox), 'other');
    assert.equal(getIOSBrowser(UA.iphoneEdge), 'other');
  });
});

describe('getIOSBrowserVersion', () => {
  it('reads the CriOS major version, else null', () => {
    assert.equal(getIOSBrowserVersion(UA.iphoneChrome113), 113);
    assert.equal(getIOSBrowserVersion(UA.iphoneChrome112), 112);
    assert.equal(getIOSBrowserVersion(UA.iphoneSafari164), null);
  });
});

describe('resolveIOSSupport', () => {
  it('is not iOS → no block, not ready', () => {
    const s = resolveIOSSupport(false, null, 'other', null);
    assert.equal(s.isIOS, false);
    assert.equal(s.ready, false);
    assert.equal(s.blockReason, null);
  });

  it('Safari on iOS 16.4 → ready', () => {
    const s = resolveIOSSupport(true, { major: 16, minor: 4 }, 'safari', null);
    assert.equal(s.ready, true);
    assert.equal(s.blockReason, null);
  });

  it('iOS below 16.4 → os-outdated (even in Safari)', () => {
    const s = resolveIOSSupport(true, { major: 15, minor: 6 }, 'safari', null);
    assert.equal(s.ready, false);
    assert.equal(s.blockReason, 'os-outdated');
  });

  it('iOS 16.3 → os-outdated (minor gate)', () => {
    assert.equal(
      resolveIOSSupport(true, { major: 16, minor: 3 }, 'safari', null).blockReason,
      'os-outdated',
    );
  });

  it('Chrome 113 on iOS 16.5 → ready', () => {
    const s = resolveIOSSupport(true, { major: 16, minor: 5 }, 'chrome', 113);
    assert.equal(s.ready, true);
    assert.equal(s.blockReason, null);
  });

  it('Chrome 112 → browser-outdated', () => {
    const s = resolveIOSSupport(true, { major: 16, minor: 5 }, 'chrome', 112);
    assert.equal(s.ready, false);
    assert.equal(s.blockReason, 'browser-outdated');
  });

  it('non-Safari/Chrome browser → unsupported-browser', () => {
    const s = resolveIOSSupport(true, { major: 16, minor: 5 }, 'other', null);
    assert.equal(s.ready, false);
    assert.equal(s.blockReason, 'unsupported-browser');
  });

  it('fails open when the OS version is unreadable', () => {
    assert.equal(resolveIOSSupport(true, null, 'safari', null).ready, true);
  });

  it('fails open when the Chrome version is unreadable', () => {
    assert.equal(resolveIOSSupport(true, { major: 16, minor: 5 }, 'chrome', null).ready, true);
  });

  it('newer major iOS passes the floor', () => {
    assert.equal(resolveIOSSupport(true, { major: 17, minor: 0 }, 'safari', null).ready, true);
  });
});

describe('isInAppBrowserUA', () => {
  it('flags social / mail web-views', () => {
    assert.equal(isInAppBrowserUA(UA.iphoneWhatsApp), true);
    assert.equal(isInAppBrowserUA(UA.iphoneInstagram), true);
    assert.equal(isInAppBrowserUA(UA.androidFacebook), true);
  });

  it('flags a bare iOS WKWebView (no Safari/ token) and Android System WebView', () => {
    assert.equal(isInAppBrowserUA(UA.iphoneBareWebView), true);
    assert.equal(isInAppBrowserUA(UA.androidWebView), true);
  });

  it('does not flag real browsers', () => {
    for (const ua of [
      UA.iphoneSafari164,
      UA.iphoneChrome113,
      UA.iphoneFirefox,
      UA.iphoneEdge,
      UA.ipadSafari164,
      UA.androidChrome,
      UA.desktopChrome,
      UA.desktopSafari,
      UA.desktopFirefox,
    ]) {
      assert.equal(isInAppBrowserUA(ua), false, ua);
    }
  });
});

describe('desktop / Android classifiers', () => {
  it('isMacSafari matches desktop Safari only', () => {
    assert.equal(isMacSafari(UA.desktopSafari), true);
    assert.equal(isMacSafari(UA.desktopChrome), false);
    assert.equal(isMacSafari(UA.desktopFirefox), false);
  });

  it('isChromium matches Chrome-family UAs', () => {
    assert.equal(isChromium(UA.desktopChrome), true);
    assert.equal(isChromium(UA.desktopSafari), false);
    assert.equal(isChromium(UA.desktopFirefox), false);
  });

  it('isAndroid matches Android UAs only', () => {
    assert.equal(isAndroid(UA.androidChrome), true);
    assert.equal(isAndroid(UA.iphoneSafari164), false);
  });
});

describe('live environment detectors (faked window/navigator)', () => {
  it('isStandalone: display-mode media query', () => {
    browser = installFakeBrowser({ ua: UA.androidChrome, displayModeStandalone: true });
    assert.equal(isStandalone(), true);
  });

  it('isStandalone: iOS navigator.standalone flag', () => {
    browser = installFakeBrowser({ ua: UA.iphoneSafari164, iosStandalone: true });
    assert.equal(isStandalone(), true);
  });

  it('isStandalone: a plain browser tab is not standalone', () => {
    browser = installFakeBrowser({ ua: UA.androidChrome, iosStandalone: false });
    assert.equal(isStandalone(), false);
  });

  it('isIOS: iPhone, iPad, and iPadOS posing as a Mac (touch-capable)', () => {
    browser = installFakeBrowser({ ua: UA.iphoneSafari164 });
    assert.equal(isIOS(), true);
    browser.restore();
    browser = installFakeBrowser({ ua: UA.ipadSafari164 });
    assert.equal(isIOS(), true);
    browser.restore();
    browser = installFakeBrowser({ ua: UA.ipadAsMacSafari, platform: 'MacIntel', maxTouchPoints: 5 });
    assert.equal(isIOS(), true);
  });

  it('isIOS: a real Mac (no touch points) and Android are not iOS', () => {
    browser = installFakeBrowser({ ua: UA.desktopSafari, platform: 'MacIntel', maxTouchPoints: 0 });
    assert.equal(isIOS(), false);
    browser.restore();
    browser = installFakeBrowser({ ua: UA.androidChrome });
    assert.equal(isIOS(), false);
  });

  it('isMobileOrTablet: phones by UA, Chromium UA hint, iPadOS-as-Mac, coarse-pointer touch screens', () => {
    const mobile = [
      { ua: UA.androidChrome },
      { ua: UA.iphoneSafari164 },
      { ua: UA.desktopChrome, uaDataMobile: true },
      { ua: UA.ipadAsMacSafari, platform: 'MacIntel', maxTouchPoints: 5 },
      { ua: UA.desktopFirefox, maxTouchPoints: 10, coarsePointer: true },
    ];
    for (const options of mobile) {
      browser = installFakeBrowser(options);
      assert.equal(isMobileOrTablet(), true, JSON.stringify(options));
      browser.restore();
    }
    browser = null;
  });

  it('isMobileOrTablet: desktops are not (even with a touch-capable laptop on a fine pointer)', () => {
    for (const options of [
      { ua: UA.desktopChrome },
      { ua: UA.desktopSafari, platform: 'MacIntel', maxTouchPoints: 0 },
      { ua: UA.desktopFirefox, maxTouchPoints: 10, coarsePointer: false },
    ]) {
      browser = installFakeBrowser(options);
      assert.equal(isMobileOrTablet(), false, JSON.stringify(options));
      browser.restore();
    }
    browser = null;
  });

  it('isPhone: phones in either orientation, and a desktop browser emulating one', () => {
    const phones = [
      { ua: UA.androidChrome, screen: { width: 412, height: 915 } },
      { ua: UA.iphoneSafari164, screen: { width: 390, height: 844 } },
      { ua: UA.iphoneSafari164, screen: { width: 844, height: 390 } }, // landscape reports swapped edges
      { ua: UA.desktopChrome, uaDataMobile: true, screen: { width: 360, height: 800 } }, // DevTools device mode
      { ua: UA.androidChrome, screen: { width: 599, height: 960 } }, // just under the tablet cut-off
    ];
    for (const options of phones) {
      browser = installFakeBrowser(options);
      assert.equal(isPhone(), true, JSON.stringify(options));
      browser.restore();
    }
    browser = null;
  });

  it('isPhone: tablets, desktops and unreadable screens are not phones', () => {
    const androidTablet =
      'Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    const notPhones = [
      { ua: UA.ipadAsMacSafari, platform: 'MacIntel', maxTouchPoints: 5, screen: { width: 820, height: 1180 } },
      { ua: UA.ipadAsMacSafari, platform: 'MacIntel', maxTouchPoints: 5, screen: { width: 744, height: 1133 } }, // iPad mini
      { ua: androidTablet, maxTouchPoints: 5, coarsePointer: true, screen: { width: 800, height: 1280 } },
      { ua: UA.desktopChrome, screen: { width: 1440, height: 900 } },
      { ua: UA.desktopFirefox, maxTouchPoints: 10, coarsePointer: false, screen: { width: 1366, height: 768 } },
      { ua: UA.androidChrome, screen: { width: 600, height: 1024 } }, // 7-inch tablet: Android's sw600dp counts as a tablet
      { ua: UA.iphoneSafari164 }, // no screen at all: fail closed rather than guess
      { ua: UA.iphoneSafari164, screen: { width: 0, height: 0 } },
    ];
    for (const options of notPhones) {
      browser = installFakeBrowser(options);
      assert.equal(isPhone(), false, JSON.stringify(options));
      browser.restore();
    }
    browser = null;
  });

  it('isInAppBrowser: a web-view tab is flagged, the installed PWA never is', () => {
    browser = installFakeBrowser({ ua: UA.iphoneWhatsApp });
    assert.equal(isInAppBrowser(), true);
    browser.restore();
    browser = installFakeBrowser({ ua: UA.iphoneWhatsApp, iosStandalone: true });
    assert.equal(isInAppBrowser(), false);
  });

  it('getIOSSupport: reads the live UA end to end', () => {
    browser = installFakeBrowser({ ua: UA.iphoneSafari156 });
    assert.equal(getIOSSupport().blockReason, 'os-outdated');
    browser.restore();
    browser = installFakeBrowser({ ua: UA.iphoneChrome112 });
    assert.equal(getIOSSupport().blockReason, 'browser-outdated');
    browser.restore();
    browser = installFakeBrowser({ ua: UA.iphoneFirefox });
    assert.equal(getIOSSupport().blockReason, 'unsupported-browser');
    browser.restore();
    browser = installFakeBrowser({ ua: UA.iphoneChrome113 });
    const chrome = getIOSSupport();
    assert.equal(chrome.ready, true);
    assert.equal(chrome.browser, 'chrome');
    browser.restore();
    browser = installFakeBrowser({ ua: UA.androidChrome });
    assert.equal(getIOSSupport().isIOS, false);
  });
});

describe('classifyInstallPlatform', () => {
  const none = { canPrompt: false, inAppBrowser: false, ios: false };

  it('a captured install prompt wins over everything', () => {
    assert.equal(classifyInstallPlatform({ ...none, canPrompt: true, ios: true }, UA.iphoneSafari164), 'native');
    assert.equal(classifyInstallPlatform({ ...none, canPrompt: true }, UA.desktopChrome), 'native');
  });

  it('an in-app web-view beats iOS/Android (it cannot install either way)', () => {
    assert.equal(
      classifyInstallPlatform({ ...none, inAppBrowser: true, ios: true }, UA.iphoneWhatsApp),
      'in-app',
    );
    assert.equal(
      classifyInstallPlatform({ ...none, inAppBrowser: true }, UA.androidFacebook),
      'in-app',
    );
  });

  it('iOS (Safari, Chrome, anything) → ios', () => {
    for (const ua of [UA.iphoneSafari164, UA.iphoneChrome113, UA.iphoneFirefox, UA.ipadSafari164]) {
      assert.equal(classifyInstallPlatform({ ...none, ios: true }, ua), 'ios', ua);
    }
  });

  it('desktop Safari → macos-safari; Android without a prompt → android', () => {
    assert.equal(classifyInstallPlatform(none, UA.desktopSafari), 'macos-safari');
    assert.equal(classifyInstallPlatform(none, UA.androidFirefox), 'android');
    assert.equal(classifyInstallPlatform(none, UA.androidChrome), 'android');
  });

  it('Chromium without a prompt → desktop-chromium (not a dead end)', () => {
    assert.equal(classifyInstallPlatform(none, UA.desktopChrome), 'desktop-chromium');
  });

  it('desktop Firefox → unsupported', () => {
    assert.equal(classifyInstallPlatform(none, UA.desktopFirefox), 'unsupported');
  });
});

describe('findAppHintOS', () => {
  it('ios → ios; Android UA → android; anything else → unknown', () => {
    assert.equal(findAppHintOS(true, UA.iphoneSafari164), 'ios');
    assert.equal(findAppHintOS(false, UA.androidChrome), 'android');
    assert.equal(findAppHintOS(false, UA.desktopChrome), 'unknown');
  });
});

describe('install prompt capture', () => {
  it('initInstallCapture is a no-op without a window', async () => {
    const m = await freshPwaInstall();
    assert.doesNotThrow(() => m.initInstallCapture());
    assert.deepEqual(m.getInstallSnapshot(), { canPrompt: false, installed: false });
  });

  it('keeps a prompt that fired BEFORE any component subscribed (the early-event bug)', async () => {
    browser = installFakeBrowser({ ua: UA.androidChrome });
    const m = await freshPwaInstall();
    m.initInstallCapture(); // main.tsx, before React mounts
    browser.window.dispatchEvent(fakeInstallPromptEvent('accepted')); // Chrome fires it early
    // A component that mounts later still sees it.
    assert.deepEqual(m.getInstallSnapshot(), { canPrompt: true, installed: false });
  });

  it('suppresses the browser mini-infobar and notifies subscribers', async () => {
    browser = installFakeBrowser({ ua: UA.androidChrome });
    const m = await freshPwaInstall();
    m.initInstallCapture();
    let notified = 0;
    m.subscribeInstall(() => (notified += 1));
    const event = fakeInstallPromptEvent('accepted');
    browser.window.dispatchEvent(event);
    assert.equal(event.defaultPrevented, true);
    assert.equal(notified, 1);
  });

  it('initInstallCapture is idempotent (listeners are not doubled)', async () => {
    browser = installFakeBrowser({ ua: UA.androidChrome });
    const m = await freshPwaInstall();
    m.initInstallCapture();
    m.initInstallCapture();
    let notified = 0;
    m.subscribeInstall(() => (notified += 1));
    browser.window.dispatchEvent(fakeInstallPromptEvent('accepted'));
    assert.equal(notified, 1);
  });

  it('promptInstall: accepted → true, and the single-use event is cleared', async () => {
    browser = installFakeBrowser({ ua: UA.androidChrome });
    const m = await freshPwaInstall();
    m.initInstallCapture();
    const event = fakeInstallPromptEvent('accepted');
    browser.window.dispatchEvent(event);
    assert.equal(await m.promptInstall(), true);
    assert.equal(event.promptCalls, 1);
    assert.deepEqual(m.getInstallSnapshot(), { canPrompt: false, installed: true });
  });

  it('promptInstall: dismissed → false, and the event is still cleared', async () => {
    browser = installFakeBrowser({ ua: UA.androidChrome });
    const m = await freshPwaInstall();
    m.initInstallCapture();
    browser.window.dispatchEvent(fakeInstallPromptEvent('dismissed'));
    assert.equal(await m.promptInstall(), false);
    assert.equal(m.getInstallSnapshot().canPrompt, false);
  });

  it('promptInstall: a rejecting prompt() resolves false instead of throwing, and clears the event', async () => {
    browser = installFakeBrowser({ ua: UA.androidChrome });
    const m = await freshPwaInstall();
    m.initInstallCapture();
    browser.window.dispatchEvent(fakeInstallPromptEvent('throws'));
    assert.equal(await m.promptInstall(), false);
    assert.equal(m.getInstallSnapshot().canPrompt, false);
  });

  it('promptInstall without a captured event → false', async () => {
    browser = installFakeBrowser({ ua: UA.androidChrome });
    const m = await freshPwaInstall();
    m.initInstallCapture();
    assert.equal(await m.promptInstall(), false);
  });

  it('appinstalled: marks installed, drops the prompt, notifies', async () => {
    browser = installFakeBrowser({ ua: UA.androidChrome });
    const m = await freshPwaInstall();
    m.initInstallCapture();
    browser.window.dispatchEvent(fakeInstallPromptEvent('accepted'));
    let notified = 0;
    m.subscribeInstall(() => (notified += 1));
    browser.window.dispatchEvent(new Event('appinstalled'));
    assert.deepEqual(m.getInstallSnapshot(), { canPrompt: false, installed: true });
    assert.equal(notified, 1);
  });

  it('unsubscribe stops notifications', async () => {
    browser = installFakeBrowser({ ua: UA.androidChrome });
    const m = await freshPwaInstall();
    m.initInstallCapture();
    let notified = 0;
    const unsubscribe = m.subscribeInstall(() => (notified += 1));
    unsubscribe();
    browser.window.dispatchEvent(new Event('appinstalled'));
    assert.equal(notified, 0);
  });
});

describe('detectPreinstalled', () => {
  it('is true when the browser reports an installed related app', async () => {
    const { detectPreinstalled } = await freshPwaInstall();
    assert.equal(await detectPreinstalled({ getInstalledRelatedApps: async () => [{ platform: 'webapp' }] }), true);
  });

  it('is false for an empty result, a missing API, or a rejection', async () => {
    const { detectPreinstalled } = await freshPwaInstall();
    assert.equal(await detectPreinstalled({ getInstalledRelatedApps: async () => [] }), false);
    assert.equal(await detectPreinstalled({}), false);
    assert.equal(await detectPreinstalled(undefined), false);
    const reject = async () => {
      throw new Error('SecurityError');
    };
    assert.equal(await detectPreinstalled({ getInstalledRelatedApps: reject }), false);
  });
});
