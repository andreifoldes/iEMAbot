/**
 * The one definition of what a participant's notification says and looks like: a line-by-line port of
 * src/backend/notifications/NotificationSpec.php (web push on the server); the native Android app uses this copy
 * for the alarms it plans on the device. When a notification is sent, and its title, text and reminders, come from
 * the study's schedule (lib/schedule.ts).
 */

import type { EsmiraQuestionnaire } from '../types';
import { t } from './i18n.ts';

export const APP_NAME = 'iEMAbot';
/** Resolved when used, not at load: the interface language is set after start-up. */
export const defaultBody = (): string => t('pwa_push_default_body');

/** Look of the notification (see NotificationSpec.php for where the Android copies live). */
export const NOTIFICATION_ICON = '/pwa/pwa-192x192.png';
export const NOTIFICATION_BADGE = '/pwa/badge-96x96.png';
export const NOTIFICATION_ACCENT = '#075E54';
// The Android app posts with copies of the same two images (res/drawable/ic_stat_esmira.xml,
// res/drawable-nodpi/ic_notification_large.png) and the same accent, in ReminderNotifier.java, which also
// applies the PWA's stacking rule (one notification on screen, each new one replacing the last).

interface TitledStudy { title?: string }

export function studyTitle(study: TitledStudy): string {
  const title = typeof study.title === 'string' ? study.title.trim() : '';
  return title !== '' ? title : APP_NAME;
}

/** Title of a single-questionnaire notification: the check-in's own title, else the questionnaire's, else the study's. */
export function notificationTitle(q: Pick<EsmiraQuestionnaire, 'title'>, study: TitledStudy, override = ''): string {
  const o = override.trim();
  if (o !== '') return o;
  const title = typeof q.title === 'string' ? q.title.trim() : '';
  return title !== '' ? title : studyTitle(study);
}

/** `body` plus " Complete by HH:MM." (24-hour, end of day shown as 23:59). */
export function withDeadline(body: string, tod: number): string {
  const mins = Math.min(Math.floor(tod / 60000), 1439);
  const hhmm = `${String(Math.floor(mins / 60)).padStart(2, '0')}:${String(mins % 60).padStart(2, '0')}`;
  return `${body} ${t('pwa_push_complete_by', hhmm)}`;
}

/** Title and body for notifications due at the same moment (one keeps its own, several share a count). */
export function coalesce(items: { title: string; body: string }[], study: TitledStudy): [string, string] {
  if (items.length === 1) return [items[0].title, items[0].body];
  return [studyTitle(study), t('pwa_push_coalesced_body', items.length)];
}

/** FNV-1a 32-bit over the UTF-8 bytes, unsigned (matches NotificationSpec::hash32). */
export function hash32(s: string): number {
  let h = 0x811c9dc5;
  for (const byte of new TextEncoder().encode(s)) {
    h ^= byte;
    h = Math.imul(h, 0x01000193) >>> 0;
  }
  return h >>> 0;
}
