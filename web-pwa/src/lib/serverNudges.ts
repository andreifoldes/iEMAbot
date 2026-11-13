/**
 * Native Android app: arms the background poll for server-driven nudges (check-ins that depend on wearable events,
 * and their reminders) implemented by the ServerNudges plugin in android-app (WorkManager, no Google push). All other
 * prompts and reminders of the study's schedule are planned on the device (notificationPlan.ts).
 * No-op in the browser, which uses Web Push for all of it.
 */

import { isNativeApp } from './platform.ts';
import { getOrCreateDeviceToken } from './deviceBinding.ts';

interface ServerNudgesPlugin {
  configure(opts: { server: string; studyId: number; userId: string; deviceToken: string; joinedAt?: number; lang?: string }): Promise<void>;
  cancel(): Promise<void>;
  pollNow(): Promise<void>;
}

function plugin(): ServerNudgesPlugin | null {
  if (!isNativeApp()) return null;
  return (globalThis as { Capacitor?: { Plugins?: { ServerNudges?: ServerNudgesPlugin } } }).Capacitor?.Plugins?.ServerNudges ?? null;
}

/** Start (or re-arm) polling for this participant. The server answers `active:false` when a study has nothing to nudge about, which stops it. */
export async function configureServerNudges(server: string, studyId: number, userId: string, joinedAt?: number, lang?: string): Promise<boolean> {
  const p = plugin();
  if (!p) return false;
  try {
    // lang: the study language the app shows, so server-sent texts match the ones planned on the device.
    // deviceToken: the background poll runs without the page, so it needs this device's identity handed over (a study that allows an id on one device only asks for it).
    await p.configure({ server, studyId, userId, deviceToken: getOrCreateDeviceToken(), ...(joinedAt ? { joinedAt } : {}), ...(lang ? { lang } : {}) });
    return true;
  } catch {
    return false; // never let this break the questionnaire flow
  }
}

export async function cancelServerNudges(): Promise<void> {
  try { await plugin()?.cancel(); } catch { /* best effort */ }
}

/** Run one poll immediately (diagnostics and tests). */
export async function pollServerNudgesNow(): Promise<void> {
  try { await plugin()?.pollNow(); } catch { /* best effort */ }
}
