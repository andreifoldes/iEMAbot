/**
 * Android app updates. The app and the server always ship together: every deploy builds the APK from the same
 * commit and the server serves it at `app/iemabot.apk`, described by `api/app_version.php` (see deploy.yml). When the
 * server holds a newer build than the installed app, the app says so: a banner with an Update button (opens the
 * download; Android asks to confirm the install, which keeps the app's data) and one notification per new build.
 * It never blocks: an older app keeps working until the participant updates.
 *
 * Builds are compared by version code (minutes since 1970 at build time, so every build, from the deploy or the
 * manual Android workflow, is newer than the ones before). No-op outside the native app.
 */

import { isNativeApp } from './platform.ts';

/** `app/android.json` on the server, as `api/app_version.php` returns it. */
export interface AndroidRelease {
  versionCode: number;
  versionName: string;
  sha: string;
  /** Relative to the server root. */
  apk: string;
}

export interface AppUpdate {
  versionName: string;
  versionCode: number;
  url: string;
}

interface AppPlugin { getInfo(): Promise<{ build: string; version: string }> }
interface BrowserPlugin { open(opts: { url: string }): Promise<void> }
interface LocalNotificationsPlugin {
  schedule(opts: { notifications: { id: number; title: string; body: string; extra?: unknown }[] }): Promise<unknown>;
}
type Plugins = { App?: AppPlugin; Browser?: BrowserPlugin; LocalNotifications?: LocalNotificationsPlugin };

function plugins(): Plugins {
  if (!isNativeApp()) return {};
  return (globalThis as { Capacitor?: { Plugins?: Plugins } }).Capacitor?.Plugins ?? {};
}

/** The update to offer, or null: only a well-formed release newer than the installed build. */
export function updateFrom(release: unknown, installedCode: number, serverBase: string): AppUpdate | null {
  if (!release || typeof release !== 'object') return null;
  const r = release as Partial<AndroidRelease>;
  const code = Number(r.versionCode);
  if (!Number.isFinite(code) || code <= installedCode || typeof r.apk !== 'string' || !r.apk) return null;
  let url: string;
  try {
    url = new URL(r.apk, new URL(serverBase, globalThis.location?.href ?? 'https://localhost/')).href;
  } catch {
    return null;
  }
  return { versionName: String(r.versionName ?? ''), versionCode: code, url };
}

async function installedVersionCode(): Promise<number> {
  try {
    const info = await plugins().App?.getInfo();
    const code = Number(info?.build);
    return Number.isFinite(code) ? code : 0;
  } catch {
    return 0;
  }
}

/** Ask the server whether a newer app build is available. Null in the browser, offline, or when up to date. */
export async function checkAppUpdate(serverBase: string): Promise<AppUpdate | null> {
  if (!isNativeApp()) return null;
  try {
    const resp = await fetch(`${serverBase}api/app_version.php`, { cache: 'no-store' });
    if (!resp.ok) return null;
    const env = await resp.json() as { success?: boolean; dataset?: unknown };
    return env.success ? updateFrom(env.dataset, await installedVersionCode(), serverBase) : null;
  } catch {
    return null;
  }
}

const NOTIFIED_KEY = 'appUpdateNotified';
/** Fixed id, far from the reminder ids (notificationPlan.reminderId), so a newer notice replaces an older one. */
const NOTIFICATION_ID = 2147480001;

/** One notification per new build, so a participant who rarely opens the app hears about it too. */
export async function notifyAppUpdate(update: AppUpdate): Promise<void> {
  try {
    if (Number(localStorage.getItem(NOTIFIED_KEY)) >= update.versionCode) return;
    const p = plugins().LocalNotifications;
    if (!p) return;
    await p.schedule({ notifications: [{
      id: NOTIFICATION_ID,
      title: 'Update iEMAbot',
      body: 'A new version of the app is available. Open the app and tap Update.',
    }] });
    localStorage.setItem(NOTIFIED_KEY, String(update.versionCode));
  } catch { /* notifications off or unavailable: the banner still shows */ }
}

/** Open the download in the browser; Android then offers to install it over the current app. */
export async function openAppUpdate(update: AppUpdate): Promise<void> {
  const browser = plugins().Browser;
  try {
    if (browser) { await browser.open({ url: update.url }); return; }
  } catch { /* fall back below */ }
  window.open(update.url, '_blank', 'noopener');
}
