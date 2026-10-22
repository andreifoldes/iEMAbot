/**
 * Native Android reminders: hands the plan from notificationPlan.ts to the OS alarm service via
 * @capacitor/local-notifications. Everything here is a no-op outside the native app, so the PWA
 * bundle can import it freely. The plugin is reached through the injected `Capacitor` global
 * (same approach as joinLink.ts) so the web build never imports Capacitor packages.
 *
 * Re-syncing is idempotent: ids are stable per occurrence, so only the difference between what
 * the OS already has pending and the fresh plan is cancelled / scheduled.
 */

import { isNativeApp } from './platform.ts';
import type { PlannedReminder } from './notificationPlan.ts';

export const REMINDER_CHANNEL_ID = 'reminders';

export type ReminderPermission = 'granted' | 'denied' | 'prompt' | 'unsupported';

interface LocalNotificationsPlugin {
  checkPermissions(): Promise<{ display: string }>;
  requestPermissions(): Promise<{ display: string }>;
  getPending(): Promise<{ notifications: { id: number }[] }>;
  cancel(opts: { notifications: { id: number }[] }): Promise<void>;
  schedule(opts: { notifications: unknown[] }): Promise<unknown>;
  createChannel?(opts: Record<string, unknown>): Promise<void>;
}

function plugin(): LocalNotificationsPlugin | null {
  if (!isNativeApp()) return null;
  const p = (globalThis as { Capacitor?: { Plugins?: { LocalNotifications?: LocalNotificationsPlugin } } })
    .Capacitor?.Plugins?.LocalNotifications;
  return p ?? null;
}

function toPermission(display: string | undefined): ReminderPermission {
  if (display === 'granted') return 'granted';
  if (display === 'denied') return 'denied';
  return 'prompt';
}

/** What to cancel and what to schedule so the OS ends up holding exactly `plan`. */
export function diffSchedule(pendingIds: number[], plan: PlannedReminder[]): { cancel: number[]; add: PlannedReminder[] } {
  const wanted = new Set(plan.map((r) => r.id));
  const have = new Set(pendingIds);
  return {
    cancel: pendingIds.filter((id) => !wanted.has(id)),
    add: plan.filter((r) => !have.has(r.id)),
  };
}

export async function reminderPermission(): Promise<ReminderPermission> {
  const p = plugin();
  if (!p) return 'unsupported';
  try { return toPermission((await p.checkPermissions()).display); } catch { return 'unsupported'; }
}

/** Shows the Android 13+ permission prompt if it has not been decided yet. */
export async function requestReminderPermission(): Promise<ReminderPermission> {
  const p = plugin();
  if (!p) return 'unsupported';
  try {
    const cur = toPermission((await p.checkPermissions()).display);
    if (cur !== 'prompt') return cur;
    return toPermission((await p.requestPermissions()).display);
  } catch {
    return 'unsupported';
  }
}

async function ensureChannel(p: LocalNotificationsPlugin): Promise<void> {
  try {
    await p.createChannel?.({
      id: REMINDER_CHANNEL_ID,
      name: 'Questionnaire reminders',
      description: 'Reminders when a questionnaire is ready',
      importance: 4,
      visibility: 1,
      vibration: true,
    });
  } catch { /* an existing channel is fine */ }
}

/** Bring the OS schedule in line with `plan`. Returns how many reminders were added / cancelled. */
export async function syncReminders(plan: PlannedReminder[], studyId: number): Promise<{ added: number; cancelled: number } | null> {
  const p = plugin();
  if (!p) return null;
  if ((await reminderPermission()) !== 'granted') return null;
  try {
    await ensureChannel(p);
    const pending = (await p.getPending()).notifications.map((n) => n.id);
    const { cancel, add } = diffSchedule(pending, plan);
    if (cancel.length) await p.cancel({ notifications: cancel.map((id) => ({ id })) });
    if (add.length) {
      await p.schedule({
        notifications: add.map((r) => ({
          id: r.id,
          title: r.title,
          body: r.body,
          channelId: REMINDER_CHANNEL_ID,
          schedule: { at: new Date(r.at), allowWhileIdle: true },
          extra: { studyId, questionnaireId: r.questionnaireId, occurrenceKey: r.occurrenceKey },
        })),
      });
    }
    return { added: add.length, cancelled: cancel.length };
  } catch {
    return null; // never let scheduling break the questionnaire flow
  }
}

/** Remove every pending reminder (sign-out / leaving the study). */
export async function clearReminders(): Promise<void> {
  const p = plugin();
  if (!p) return;
  try {
    const pending = (await p.getPending()).notifications;
    if (pending.length) await p.cancel({ notifications: pending.map((n) => ({ id: n.id })) });
  } catch { /* best effort */ }
}
