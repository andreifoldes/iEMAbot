/**
 * Native Android app: arms the background poll for server-driven nudges (wearable-triggered prompts and
 * follow-up reminders) implemented by the ServerNudges plugin in android-app (WorkManager, no Google push).
 * Scheduled signal times are planned on the device instead (notificationPlan.ts), so the poll only carries
 * what the device cannot know offline. No-op in the browser, which uses Web Push for all of it.
 */

import { isNativeApp } from './platform.ts';

interface ServerNudgesPlugin {
  configure(opts: { server: string; studyId: number; userId: string; joinedAt?: number }): Promise<void>;
  cancel(): Promise<void>;
  pollNow(): Promise<void>;
}

function plugin(): ServerNudgesPlugin | null {
  if (!isNativeApp()) return null;
  return (globalThis as { Capacitor?: { Plugins?: { ServerNudges?: ServerNudgesPlugin } } }).Capacitor?.Plugins?.ServerNudges ?? null;
}

/** Start (or re-arm) polling for this participant. The server answers `active:false` when a study has nothing to nudge about, which stops it. */
export async function configureServerNudges(server: string, studyId: number, userId: string, joinedAt?: number): Promise<boolean> {
  const p = plugin();
  if (!p) return false;
  try {
    await p.configure({ server, studyId, userId, ...(joinedAt ? { joinedAt } : {}) });
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
