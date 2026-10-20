/**
 * Shared PWA install detection + native-prompt capture.
 *
 * One source of truth for "is this the installed app?", "can we trigger the
 * native install prompt?" and "what can this browser actually do?", used by the
 * install funnel in App.tsx and by InstallPrompt.tsx.
 *
 * The detectors take the user-agent string as an argument (defaulting to the
 * live `navigator`) so they can be unit-tested with real-world UA strings.
 */
import { useEffect, useState } from 'react';

export interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

/** UA string, or '' when navigator is unavailable (SSR / tests without a DOM). */
function userAgent(): string {
  return typeof navigator === 'undefined' ? '' : navigator.userAgent || '';
}

/** True when running as an installed PWA (launched from the Home Screen / app list). */
export function isStandalone(): boolean {
  if (typeof window === 'undefined') return false;
  return (
    window.matchMedia?.('(display-mode: standalone)').matches === true ||
    // iOS Safari exposes this non-standard flag when launched from the home screen.
    (window.navigator as { standalone?: boolean }).standalone === true
  );
}

/** True on iOS/iPadOS, where install is a manual "Add to Home Screen". */
export function isIOS(): boolean {
  if (typeof navigator === 'undefined') return false;
  return (
    /iphone|ipad|ipod/i.test(navigator.userAgent) ||
    // iPadOS 13+ reports as a Mac; disambiguate via touch support.
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  );
}

export function isAndroid(ua: string = userAgent()): boolean {
  return /android/i.test(ua);
}

/** Desktop Safari (installs via File → "Add to Dock"). Excludes iOS and Chromium-based browsers. */
export function isMacSafari(ua: string = userAgent()): boolean {
  return /Macintosh/.test(ua) && /Safari/.test(ua) && !/Chrome|Chromium|CriOS|FxiOS|Edg|OPR/.test(ua);
}

/**
 * Desktop Chromium (Chrome/Edge/Brave/Opera). Only meaningful once iOS and
 * Android are ruled out: a Chromium UA at that point is a desktop browser that
 * can install PWAs even if it hasn't handed us a `beforeinstallprompt`.
 */
export function isChromium(ua: string = userAgent()): boolean {
  return /Chrome|Chromium/i.test(ua);
}

// ── iOS version + browser detection ──────────────────────────────────────────
// On iOS every browser is WebKit, but installing to the Home Screen and Web Push
// have hard floors: iOS/iPadOS 16.4 added Web Push for Home-Screen web apps, and
// Chrome for iOS gained "Add to Home Screen" in v113. Safari and Chrome are the
// two browsers we fully support; anything else is steered to one of them.

/** Minimum iOS/iPadOS with Web Push for Home-Screen web apps (16.4, Mar 2023). */
export const IOS_PUSH_MIN = { major: 16, minor: 4 } as const;
/** Minimum Chrome-for-iOS that can add a web app to the Home Screen (v113). */
export const CHROME_IOS_MIN = 113;

export interface OSVersion {
  major: number;
  minor: number;
}

/**
 * Which iOS browser the page is loaded in. Safari and Chrome are first-class
 * (both install on iOS 16.4+); every other iOS browser folds into 'other' and
 * is steered to Safari/Chrome.
 */
export type IOSBrowser = 'safari' | 'chrome' | 'other';

/** Why install / Web Push is unavailable on this iOS device; null when it works. */
export type IOSBlockReason =
  | 'os-outdated' // iOS < 16.4
  | 'browser-outdated' // Chrome for iOS < 113
  | 'unsupported-browser' // not Safari or Chrome
  | null;

export interface IOSSupport {
  /** On iOS/iPadOS at all. */
  isIOS: boolean;
  /** Parsed OS version, or null when unreadable (e.g. iPadOS posing as macOS). */
  version: OSVersion | null;
  /** Detected browser family. */
  browser: IOSBrowser;
  /** Chrome-for-iOS major version, when applicable. */
  browserVersion: number | null;
  /** OS + browser can install to the Home Screen and receive Web Push. */
  ready: boolean;
  /** Drives the alert copy; null when `ready`. */
  blockReason: IOSBlockReason;
}

/**
 * Parse the iOS/iPadOS version from the UA, e.g. "CPU iPhone OS 16_4" → {16, 4}.
 * Returns null when absent — notably iPadOS 13+, which reports as desktop Safari.
 * Callers must FAIL OPEN on null (never lock out a device we can't read).
 */
export function getIOSVersion(ua: string = userAgent()): OSVersion | null {
  const m = /(?:iPhone|CPU) OS (\d+)_(\d+)/.exec(ua);
  return m ? { major: Number(m[1]), minor: Number(m[2]) } : null;
}

/** Identify the iOS browser from its UA token (CriOS = Chrome for iOS). */
export function getIOSBrowser(ua: string = userAgent()): IOSBrowser {
  if (/CriOS\//.test(ua)) return 'chrome';
  // Real Safari carries "Safari/" and none of the third-party iOS tokens
  // (CriOS = Chrome, FxiOS = Firefox, EdgiOS = Edge, OPT = Opera Touch).
  if (/Safari\//.test(ua) && !/CriOS|FxiOS|EdgiOS|OPT\//.test(ua)) return 'safari';
  return 'other';
}

/** Major version of Chrome for iOS (CriOS/NNN), or null when not Chrome. */
export function getIOSBrowserVersion(ua: string = userAgent()): number | null {
  const m = /CriOS\/(\d+)/.exec(ua);
  return m ? Number(m[1]) : null;
}

/** True when `v` is at least `min` (major, then minor). */
function versionAtLeast(v: OSVersion, min: { major: number; minor: number }): boolean {
  return v.major > min.major || (v.major === min.major && v.minor >= min.minor);
}

/**
 * Pure decision core: given the detected facts, resolve whether iOS install +
 * Web Push work, and if not, why. Fails open on unreadable versions so a valid
 * device is never blocked by a parsing miss. Exported for unit testing.
 */
export function resolveIOSSupport(
  isIOSDevice: boolean,
  version: OSVersion | null,
  browser: IOSBrowser,
  browserVersion: number | null,
): IOSSupport {
  const base = { version, browser, browserVersion };
  if (!isIOSDevice) return { isIOS: false, ...base, ready: false, blockReason: null };
  if (version !== null && !versionAtLeast(version, IOS_PUSH_MIN)) {
    return { isIOS: true, ...base, ready: false, blockReason: 'os-outdated' };
  }
  if (browser === 'safari') {
    return { isIOS: true, ...base, ready: true, blockReason: null };
  }
  if (browser === 'chrome') {
    const ok = browserVersion === null || browserVersion >= CHROME_IOS_MIN;
    return { isIOS: true, ...base, ready: ok, blockReason: ok ? null : 'browser-outdated' };
  }
  return { isIOS: true, ...base, ready: false, blockReason: 'unsupported-browser' };
}

/** Resolve iOS install/push support for the current device+browser. */
export function getIOSSupport(): IOSSupport {
  const ua = userAgent();
  return resolveIOSSupport(isIOS(), getIOSVersion(ua), getIOSBrowser(ua), getIOSBrowserVersion(ua));
}

/**
 * True on a phone or tablet — a touch-first device that can add the app to a
 * Home Screen. Used to offer desktop/laptop users a "continue on your phone" QR
 * code (reminders and the diary are phone-first).
 *
 * Prefers the browser-native UA Client Hints signal (`navigator.userAgentData`,
 * Chromium) and falls back to UA sniffing + touch/pointer feature detection, so
 * we avoid a brittle third-party device-detection dependency.
 */
export function isMobileOrTablet(): boolean {
  if (typeof navigator === 'undefined') return false;

  // Structured, native signal (Chromium): trustworthy when it reports mobile.
  const uaData = (navigator as { userAgentData?: { mobile?: boolean } }).userAgentData;
  if (uaData?.mobile === true) return true;

  const ua = navigator.userAgent || '';
  if (/android|iphone|ipod|iemobile|blackberry|opera mini|mobile|tablet|silk|kindle|playbook/i.test(ua)) {
    return true;
  }
  // iPadOS 13+ masquerades as desktop Safari but is a touch tablet.
  if (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1) return true;
  // Generic touch screen whose primary pointer is coarse (finger) — Android tablets.
  if (
    navigator.maxTouchPoints > 0 &&
    typeof window !== 'undefined' &&
    window.matchMedia?.('(pointer: coarse)').matches
  ) {
    return true;
  }
  return false;
}

/**
 * True on a phone specifically (not a tablet). "Phone" = a touch device whose
 * smaller physical screen edge is under 600px: phones top out around 430px logical
 * width, while 7-inch tablets (600px, Android's `sw600dp` cut-off) and the iPad mini
 * (~744px) are tablets. `screen.*`
 * (physical) is used rather than the viewport so orientation and window size don't
 * matter, and a phone in iOS "Request Desktop Website" mode is still a phone.
 *
 * Fails closed when the screen can't be read; callers that gate on this should offer a
 * "continue here" escape for touch devices (see lib/phoneGate.ts). An unfolded
 * foldable's inner screen (~670px) counts as a tablet.
 */
export function isPhone(): boolean {
  if (typeof navigator === 'undefined' || typeof screen === 'undefined') return false;
  if (!isMobileOrTablet()) return false;
  const minEdge = Math.min(screen.width || 0, screen.height || 0);
  return minEdge > 0 && minEdge < 600;
}

/** Known in-app web-view signatures (social / mail apps embedding a browser). */
const IN_APP_UA =
  /FBAN|FBAV|FB_IAB|Instagram|Line\/|Twitter|Snapchat|Pinterest|LinkedInApp|WhatsApp|MicroMessenger|WeChat|GSA\/|Outlook-|YMail/i;

/**
 * True when the page is loaded inside another app's embedded browser
 * (WhatsApp, Instagram, Facebook, Gmail, Outlook, LINE, etc.). These
 * web-views cannot "Add to Home Screen", so the flow should steer the
 * participant into a real browser first. Deliberately conservative — matching
 * generic version tokens (e.g. Chrome's frozen "0.0.0") would false-positive
 * on ordinary Chrome/Android, so we only match known web-view signatures.
 */
export function isInAppBrowserUA(ua: string): boolean {
  if (IN_APP_UA.test(ua)) return true;
  // Android System WebView embedded in a native app reports a "; wv)" token.
  if (/;\s?wv\)/.test(ua)) return true;
  // iOS WKWebView inside another app: an iOS UA that lacks the "Safari/" token
  // (real Safari and CriOS/FxiOS/EdgiOS all include it).
  return /iPhone|iPod|iPad/.test(ua) && !/Safari\//.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua);
}

/** In-app browser check for the live page; the installed PWA is never one. */
export function isInAppBrowser(): boolean {
  if (typeof navigator === 'undefined' || isStandalone()) return false;
  return isInAppBrowserUA(navigator.userAgent || '');
}

/** How this browser can (or can't) install the app; drives InstallPrompt's guidance. */
export type InstallPlatform =
  | 'native' // Chromium handed us a `beforeinstallprompt` — a real Install button
  | 'in-app' // WhatsApp / Instagram / Mail… web-view: reopen in a real browser first
  | 'ios' // manual Share → Add to Home Screen (or a version/browser alert)
  | 'macos-safari' // File → Add to Dock
  | 'android' // browser menu → Install app (no captured prompt, e.g. Firefox)
  | 'desktop-chromium' // address-bar install icon (no captured prompt yet)
  | 'unsupported'; // e.g. desktop Firefox — use a phone or another browser

/**
 * Classify the environment. A captured `beforeinstallprompt` always wins (the
 * browser can install directly); an in-app web-view is checked before iOS /
 * Android because a web-view can't install whatever the OS is.
 */
export function classifyInstallPlatform(
  facts: { canPrompt: boolean; inAppBrowser: boolean; ios: boolean },
  ua: string = userAgent(),
): InstallPlatform {
  if (facts.canPrompt) return 'native';
  if (facts.inAppBrowser) return 'in-app';
  if (facts.ios) return 'ios';
  if (isMacSafari(ua)) return 'macos-safari';
  if (isAndroid(ua)) return 'android';
  if (isChromium(ua)) return 'desktop-chromium';
  return 'unsupported';
}

/** Which OS's "can't find the app?" wording applies (see FindAppHint). */
export type HintOS = 'ios' | 'android' | 'unknown';

export function findAppHintOS(ios: boolean, ua: string = userAgent()): HintOS {
  if (ios) return 'ios';
  return isAndroid(ua) ? 'android' : 'unknown';
}

export interface PwaInstall {
  /** Native install available (Chromium captured `beforeinstallprompt`). */
  canPrompt: boolean;
  /** Running inside the installed PWA (Home Screen / app-window launch). */
  standalone: boolean;
  /**
   * The app is installed on this device — `appinstalled` fired this session, or the
   * browser reports it was installed earlier — but we may still be in the browser tab — installing does NOT switch the current tab into
   * standalone mode. Use this to tell the user to open the installed app; do not
   * treat it as "step 1 done and we're in the app" (that's `standalone`).
   */
  installed: boolean;
  /** iOS/iPadOS — needs the manual "Add to Home Screen" steps. */
  ios: boolean;
  /** iOS install/push capability + version gating (browser, versions, blockReason). */
  iosSupport: IOSSupport;
  /** Desktop/laptop (not a phone or tablet). */
  desktop: boolean;
  /** Inside an in-app browser (WhatsApp, Instagram, etc.) that can't install PWAs. */
  inAppBrowser: boolean;
  /** Fire the native install prompt; resolves true if the user accepted. */
  promptInstall: () => Promise<boolean>;
}

/**
 * Module-level capture of the native install prompt. Chrome/Edge fire
 * `beforeinstallprompt` very early — often BEFORE React mounts, and the funnel
 * screens mount late — so a listener added inside a component effect misses it
 * and the install button never appears. We capture it here as soon as
 * `initInstallCapture()` runs (call it from `main.tsx` before render) and let
 * hooks subscribe for updates.
 */
let deferredPrompt: BeforeInstallPromptEvent | null = null;
let didInstall = false;
/** The app was installed on this device before this page load (see `detectPreinstalled`). */
let preinstalled = false;
let captureStarted = false;
const installListeners = new Set<() => void>();

function emitInstallChange(): void {
  installListeners.forEach((notify) => notify());
}

/** `getInstalledRelatedApps` is Chromium-only and absent from lib.dom.d.ts. */
interface InstalledAppsNavigator {
  getInstalledRelatedApps?: () => Promise<unknown[]>;
}

/**
 * Ask the browser whether this origin's PWA is already installed on the device
 * (Chromium `getInstalledRelatedApps()`; recent versions answer for same-scope web
 * apps without a manifest `related_applications` entry). An installed app never
 * fires `beforeinstallprompt`, so without this a returning desktop user would be told
 * to find an install icon that Chrome has replaced with "Open in app". Resolves false
 * when the API is missing or throws — callers then fall back to the generic guidance.
 */
export async function detectPreinstalled(
  nav: InstalledAppsNavigator | undefined = typeof navigator === 'undefined'
    ? undefined
    : (navigator as InstalledAppsNavigator),
): Promise<boolean> {
  if (!nav?.getInstalledRelatedApps) return false;
  try {
    return (await nav.getInstalledRelatedApps()).length > 0;
  } catch {
    return false;
  }
}

/** Attach the global install listeners once. Idempotent; call at boot. */
export function initInstallCapture(): void {
  if (captureStarted || typeof window === 'undefined') return;
  captureStarted = true;
  void detectPreinstalled().then((found) => {
    if (!found) return;
    preinstalled = true;
    emitInstallChange();
  });
  window.addEventListener('beforeinstallprompt', (e) => {
    // Suppress Chrome's default mini-infobar so we can present our own button.
    e.preventDefault();
    deferredPrompt = e as BeforeInstallPromptEvent;
    emitInstallChange();
  });
  window.addEventListener('appinstalled', () => {
    // Installed — but this tab does NOT flip to standalone; only isStandalone()
    // / the display-mode media query decides `standalone`.
    deferredPrompt = null;
    didInstall = true;
    emitInstallChange();
  });
}

/** What the capture has seen so far. */
export function getInstallSnapshot(): { canPrompt: boolean; installed: boolean } {
  return { canPrompt: deferredPrompt !== null, installed: didInstall || preinstalled };
}

/** Be told when the captured prompt / installed state changes. Returns an unsubscribe. */
export function subscribeInstall(listener: () => void): () => void {
  installListeners.add(listener);
  return () => {
    installListeners.delete(listener);
  };
}

/** Fire the captured native prompt; resolves true only if the user accepted. */
export async function promptInstall(): Promise<boolean> {
  const prompt = deferredPrompt;
  if (!prompt) return false;
  try {
    await prompt.prompt();
    const choice = await prompt.userChoice;
    // Accepting is enough to advance the funnel now; `appinstalled` can lag the dialog
    // (or never reach this tab), which left step 2 un-highlighted after a successful install.
    if (choice.outcome === 'accepted') didInstall = true;
    return choice.outcome === 'accepted';
  } catch {
    // prompt() rejects if the event was already used / the page isn't eligible.
    return false;
  } finally {
    // A prompt event can only be used once, whatever the outcome.
    deferredPrompt = null;
    emitInstallChange();
  }
}

/** Track install state for the current device/session. */
export function usePwaInstall(): PwaInstall {
  // Defensive: ensure capture is running even if boot didn't start it.
  initInstallCapture();

  const [, forceRender] = useState(0);
  const [standalone, setStandalone] = useState<boolean>(() => isStandalone());

  useEffect(() => {
    const rerender = () => forceRender((n) => n + 1);
    const unsubscribe = subscribeInstall(rerender);
    const mq = window.matchMedia?.('(display-mode: standalone)');
    const onDisplayModeChange = () => setStandalone(isStandalone());
    mq?.addEventListener?.('change', onDisplayModeChange);
    // Sync any event that fired between first render and this effect running.
    rerender();
    return () => {
      unsubscribe();
      mq?.removeEventListener?.('change', onDisplayModeChange);
    };
  }, []);

  const { canPrompt, installed } = getInstallSnapshot();
  return {
    canPrompt,
    standalone,
    installed,
    ios: isIOS(),
    iosSupport: getIOSSupport(),
    desktop: !isMobileOrTablet(),
    inAppBrowser: isInAppBrowser(),
    promptInstall,
  };
}
