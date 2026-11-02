/**
 * Daily reminder to turn notifications on, for participants who reached the study without them (declined
 * the browser's or Android's dialog, tapped "Not now" at onboarding, or blocked them later). Shown above the
 * questionnaire list at most once a day, until notifications are on. Studies can turn it off
 * (`notificationNudge: false`), and a participant who switched notifications off in Settings is never asked.
 *
 * What the card offers depends on what the system still allows:
 *  - `ask`: the permission is undecided (or Android may ask again), so a tap can show the system dialog;
 *  - `blocked`: the browser or Android no longer shows the dialog, so the participant has to allow
 *    notifications in the settings (the Android app opens its notification settings);
 *  - `install`: iOS Safari before the app is added to the Home Screen, where web push does not exist.
 */

import type { EsmiraStudy } from '../types';

export type NudgeKind = 'ask' | 'blocked' | 'install';

/** Notification permission as App.tsx tracks it (Android's 'prompt' is 'default'). */
export type NudgePermission = NotificationPermission | 'unsupported';

function shownKey(studyId: number, userId: string): string { return `esmira_notif_nudge_${studyId}_${userId}`; }

/** The local calendar day, e.g. 2026-10-29. */
export function localDay(now: Date): string {
  const p = (n: number) => String(n).padStart(2, '0');
  return `${now.getFullYear()}-${p(now.getMonth() + 1)}-${p(now.getDate())}`;
}

/** The research team has not turned the daily reminder off (absent = on). */
export function nudgeEnabled(study: Pick<EsmiraStudy, 'notificationNudge'>): boolean {
  return study.notificationNudge !== false;
}

/** The card was already shown today for this study and participant. */
export function nudgeShownToday(studyId: number, userId: string, now = new Date()): boolean {
  try { return localStorage.getItem(shownKey(studyId, userId)) === localDay(now); } catch { return false; }
}

export function markNudgeShown(studyId: number, userId: string, now = new Date()): void {
  try { localStorage.setItem(shownKey(studyId, userId), localDay(now)); } catch { /* storage unavailable: may show again */ }
}

/**
 * What to offer for this permission state, or null for no card: notifications are on, or nothing the
 * participant can do here would turn them on (a browser without web push, a phone the app cannot check).
 * `installable`: iOS Safari outside the installed app, where adding it to the Home Screen brings web push.
 */
export function nudgeKind(perm: NudgePermission, installable: boolean): NudgeKind | null {
  if (perm === 'granted') return null;
  if (perm === 'unsupported') return installable ? 'install' : null;
  return perm === 'denied' ? 'blocked' : 'ask';
}
