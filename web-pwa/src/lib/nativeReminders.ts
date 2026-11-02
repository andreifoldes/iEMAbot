/**
 * Native Android reminders: hands the plan from notificationPlan.ts to the app's own `Reminders` plugin
 * (android-app RemindersPlugin / ReminderStore / ReminderNotifier), which arms exact alarms and posts each
 * reminder with the PWA's stacking rule: one notification on screen, each new one replacing the last.
 * @capacitor/local-notifications is still used for the notification permission, and to cancel alarms an
 * older app build planned with it. Everything here is a no-op outside the native app, so the PWA bundle can
 * import it freely. Plugins are reached through the injected `Capacitor` global (same approach as
 * joinLink.ts) so the web build never imports Capacitor packages.
 *
 * Re-syncing is idempotent: ids are stable per notification, so only the difference between what the
 * phone already has pending and the fresh plan is cancelled / scheduled.
 */

import { isNativeApp } from './platform.ts';
import type { PlannedReminder } from './notificationPlan.ts';

export type ReminderPermission = 'granted' | 'denied' | 'prompt' | 'unsupported';

/** @capacitor/local-notifications: permission prompt, and cleanup of alarms planned by older builds. */
interface LocalNotificationsPlugin {
  checkPermissions(): Promise<{ display: string }>;
  requestPermissions(): Promise<{ display: string }>;
  getPending(): Promise<{ notifications: { id: number }[] }>;
  cancel(opts: { notifications: { id: number }[] }): Promise<void>;
}

/** android-app RemindersPlugin. */
interface RemindersPlugin {
  schedule(opts: { notifications: { id: number; at: number; deadline: number; title: string; body: string; key: string }[] }): Promise<void>;
  cancel(opts: { ids: number[] }): Promise<void>;
  /** `deadline` is missing for a reminder an older app build planned (before notifications expired). */
  getPending(): Promise<{ notifications: PendingReminder[] }>;
  takeFired(): Promise<{ count: number }>;
  clearShown(): Promise<void>;
  openNotificationSettings(): Promise<void>;
}

/** A reminder the phone holds. */
export interface PendingReminder {
  id: number;
  at?: number;
  deadline?: number;
}

type Plugins = { LocalNotifications?: LocalNotificationsPlugin; Reminders?: RemindersPlugin };

function plugins(): Plugins {
  if (!isNativeApp()) return {};
  return (globalThis as { Capacitor?: { Plugins?: Plugins } }).Capacitor?.Plugins ?? {};
}

function toPermission(display: string | undefined): ReminderPermission {
  if (display === 'granted') return 'granted';
  if (display === 'denied') return 'denied';
  return 'prompt';
}

/**
 * What to cancel and what to schedule so the phone ends up holding exactly `plan`. A reminder the phone holds with
 * another deadline (or none, planned by an older build) is scheduled again: the same id replaces it in place.
 */
export function diffSchedule(pending: PendingReminder[], plan: PlannedReminder[]): { cancel: number[]; add: PlannedReminder[] } {
  const wanted = new Set(plan.map((r) => r.id));
  const have = new Map(pending.map((p) => [p.id, p]));
  return {
    cancel: pending.map((p) => p.id).filter((id) => !wanted.has(id)),
    add: plan.filter((r) => have.get(r.id)?.deadline !== r.deadline),
  };
}

export async function reminderPermission(): Promise<ReminderPermission> {
  const p = plugins().LocalNotifications;
  if (!p) return 'unsupported';
  try { return toPermission((await p.checkPermissions()).display); } catch { return 'unsupported'; }
}

/** Shows the Android 13+ permission prompt if it has not been decided yet. */
export async function requestReminderPermission(): Promise<ReminderPermission> {
  const p = plugins().LocalNotifications;
  if (!p) return 'unsupported';
  try {
    const cur = toPermission((await p.checkPermissions()).display);
    if (cur !== 'prompt') return cur;
    return toPermission((await p.requestPermissions()).display);
  } catch {
    return 'unsupported';
  }
}

/**
 * Opens the app's notification settings in Android, for when the permission dialog is no longer shown. False
 * outside the native app, or in an app build from before this method (the caller explains the way instead).
 */
export async function openNotificationSettings(): Promise<boolean> {
  const r = plugins().Reminders;
  if (!r) return false;
  try { await r.openNotificationSettings(); return true; } catch { return false; }
}

/** Alarms an app build before the Reminders plugin planned with local-notifications: they would stack. */
async function cancelLegacy(p: LocalNotificationsPlugin | undefined): Promise<void> {
  if (!p) return;
  try {
    const pending = (await p.getPending()).notifications;
    if (pending.length) await p.cancel({ notifications: pending.map((n) => ({ id: n.id })) });
  } catch { /* best effort */ }
}

/**
 * Bring the phone's schedule in line with `plan`. Returns how many reminders were added (new ones, not those
 * re-scheduled in place) / cancelled, and how many were shown since the last sync (for the researcher's funnel).
 */
export async function syncReminders(plan: PlannedReminder[], _studyId: number): Promise<{ added: number; cancelled: number; fired: number } | null> {
  const { Reminders: r, LocalNotifications: ln } = plugins();
  if (!r) return null;
  if ((await reminderPermission()) !== 'granted') return null;
  try {
    await cancelLegacy(ln);
    const fired = (await r.takeFired()).count;
    const pending = (await r.getPending()).notifications;
    const { cancel, add } = diffSchedule(pending, plan);
    if (cancel.length) await r.cancel({ ids: cancel });
    if (add.length) {
      await r.schedule({
        notifications: add.map((x) => ({ id: x.id, at: x.at, deadline: x.deadline, title: x.title, body: x.body, key: x.keys[0] ?? '' })),
      });
    }
    const had = new Set(pending.map((n) => n.id));
    return { added: add.filter((x) => !had.has(x.id)).length, cancelled: cancel.length, fired };
  } catch {
    return null; // never let scheduling break the questionnaire flow
  }
}

/** Remove every pending reminder and the one showing (sign-out, leaving the study, notifications switched off). */
export async function clearReminders(): Promise<void> {
  const { Reminders: r, LocalNotifications: ln } = plugins();
  await cancelLegacy(ln);
  if (!r) return;
  try {
    const pending = (await r.getPending()).notifications.map((n) => n.id);
    if (pending.length) await r.cancel({ ids: pending });
    await r.clearShown();
  } catch { /* best effort */ }
}
