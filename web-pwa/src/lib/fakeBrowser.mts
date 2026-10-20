/**
 * Test helper: stand-in `window` / `navigator` globals so the browser-facing code
 * in pwaInstall.ts (and the components that read it) can run under Node's test
 * runner. Not a test file itself — imported by the *.test.mts suites.
 */

export interface FakeBrowserOptions {
  ua: string;
  /** `navigator.platform` (iPadOS 13+ reports 'MacIntel'). */
  platform?: string;
  maxTouchPoints?: number;
  /** `(display-mode: standalone)` matches — installed PWA on Android / desktop. */
  displayModeStandalone?: boolean;
  /** iOS' non-standard `navigator.standalone` flag. */
  iosStandalone?: boolean;
  /** `(pointer: coarse)` matches — a finger-first device. */
  coarsePointer?: boolean;
  /** `navigator.userAgentData.mobile` (Chromium UA Client Hints). */
  uaDataMobile?: boolean;
  /** `screen.width` / `screen.height` in CSS px (physical screen, independent of orientation). */
  screen?: { width: number; height: number };
  href?: string;
}

export interface FakeBrowser {
  window: EventTarget & Record<string, unknown>;
  /** Put the original globals back. */
  restore: () => void;
}

const KEYS = ['window', 'navigator', 'screen'] as const;

/** Install fake `window` + `navigator` globals; call `restore()` when done. */
export function installFakeBrowser(options: FakeBrowserOptions): FakeBrowser {
  const originals = KEYS.map((k) => [k, Object.getOwnPropertyDescriptor(globalThis, k)] as const);

  const navigator: Record<string, unknown> = {
    userAgent: options.ua,
    platform: options.platform ?? '',
    maxTouchPoints: options.maxTouchPoints ?? 0,
    clipboard: { writeText: async () => undefined },
  };
  if (options.iosStandalone !== undefined) navigator.standalone = options.iosStandalone;
  if (options.uaDataMobile !== undefined) navigator.userAgentData = { mobile: options.uaDataMobile };

  const win = new EventTarget() as EventTarget & Record<string, unknown>;
  win.navigator = navigator;
  win.location = { href: options.href ?? 'https://example.test/pwa/' };
  win.matchMedia = (query: string) => ({
    matches:
      (query.includes('display-mode: standalone') && options.displayModeStandalone === true) ||
      (query.includes('pointer: coarse') && options.coarsePointer === true),
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
  });

  Object.defineProperty(globalThis, 'window', { value: win, configurable: true, writable: true });
  Object.defineProperty(globalThis, 'navigator', { value: navigator, configurable: true, writable: true });
  if (options.screen) {
    win.screen = options.screen;
    Object.defineProperty(globalThis, 'screen', { value: options.screen, configurable: true, writable: true });
  }

  return {
    window: win,
    restore: () => {
      for (const [key, descriptor] of originals) {
        if (descriptor) Object.defineProperty(globalThis, key, descriptor);
        else delete (globalThis as Record<string, unknown>)[key];
      }
    },
  };
}

/** A `beforeinstallprompt` stand-in whose `prompt()` / `userChoice` the test controls. */
export function fakeInstallPromptEvent(outcome: 'accepted' | 'dismissed' | 'throws'): Event & {
  promptCalls: number;
} {
  const event = new Event('beforeinstallprompt', { cancelable: true }) as Event & {
    promptCalls: number;
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
  };
  event.promptCalls = 0;
  event.prompt = async () => {
    event.promptCalls += 1;
    if (outcome === 'throws') throw new Error('prompt() already used');
  };
  event.userChoice = Promise.resolve({ outcome: outcome === 'accepted' ? 'accepted' : 'dismissed' });
  return event;
}

// Real-world User-Agent strings shared by the suites.
export const UA = {
  iphoneSafari164:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 16_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.4 Mobile/15E148 Safari/604.1',
  iphoneSafari156:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 15_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6 Mobile/15E148 Safari/604.1',
  iphoneChrome113:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/113.0.5672.69 Mobile/15E148 Safari/604.1',
  iphoneChrome112:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/112.0.5615.70 Mobile/15E148 Safari/604.1',
  iphoneFirefox:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/114.0 Mobile/15E148 Safari/605.1.15',
  iphoneEdge:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) EdgiOS/113.0.1774.50 Mobile/15E148 Safari/605.1.15',
  ipadSafari164:
    'Mozilla/5.0 (iPad; CPU OS 16_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.4 Mobile/15E148 Safari/604.1',
  // iPadOS 13+ "request desktop website" UA: indistinguishable from Mac Safari except for touch points.
  ipadAsMacSafari:
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
  iphoneWhatsApp:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/21A329 WhatsApp/23.20.79',
  iphoneInstagram:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/21A329 Instagram 300.0.0.29.110',
  iphoneBareWebView:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148',
  androidChrome:
    'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Mobile Safari/537.36',
  androidFirefox: 'Mozilla/5.0 (Android 13; Mobile; rv:120.0) Gecko/120.0 Firefox/120.0',
  androidWebView:
    'Mozilla/5.0 (Linux; Android 13; Pixel 7; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/113.0.0.0 Mobile Safari/537.36',
  androidFacebook:
    'Mozilla/5.0 (Linux; Android 13; Pixel 7; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/113.0.0.0 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/420.0.0.0;]',
  desktopChrome:
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
  desktopSafari:
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
  desktopFirefox:
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:120.0) Gecko/20100101 Firefox/120.0',
} as const;
