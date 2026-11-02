/**
 * Whether this participant gets notifications in this app, on either channel (browser web push or the
 * native Android app). Two switches decide it:
 *  - the research team's, per study and channel: `webPushEnabled` (web app), `androidNotificationsEnabled`
 *    (Android app, on unless set to false);
 *  - the participant's own, in Settings → Notifications, kept here per study and participant and sent to
 *    the server (api/notification_prefs.php), which then stops sending to them on every channel.
 * A switch made on another device reaches this one through push_status.php (`notificationsOff`).
 */

import type { EsmiraStudy } from '../types';

function offKey(studyId: number, userId: string): string { return `esmira_notifications_off_${studyId}_${userId}`; }
function unsyncedKey(studyId: number, userId: string): string { return `esmira_notifications_off_unsynced_${studyId}_${userId}`; }

/** The research team sends notifications to this channel for this study. */
export function channelEnabled(study: Pick<EsmiraStudy, 'webPushEnabled' | 'androidNotificationsEnabled'>, native: boolean): boolean {
  return native ? study.androidNotificationsEnabled !== false : !!study.webPushEnabled;
}

/** The participant switched notifications off for this study. */
export function participantOff(studyId: number, userId: string): boolean {
  try { return localStorage.getItem(offKey(studyId, userId)) === '1'; } catch { return false; }
}

/** Record the participant's choice on this device; `unsynced` marks it for (re)sending to the server. */
export function setParticipantOff(studyId: number, userId: string, off: boolean, unsynced: boolean): void {
  try {
    if (off) localStorage.setItem(offKey(studyId, userId), '1'); else localStorage.removeItem(offKey(studyId, userId));
    if (unsynced) localStorage.setItem(unsyncedKey(studyId, userId), '1'); else localStorage.removeItem(unsyncedKey(studyId, userId));
  } catch { /* storage unavailable: the switch lasts for this session */ }
}

/** A choice the server has not confirmed yet (made offline, or the request failed). */
export function hasUnsyncedChoice(studyId: number, userId: string): boolean {
  try { return localStorage.getItem(unsyncedKey(studyId, userId)) === '1'; } catch { return false; }
}

/** Notifications should reach the participant here: the channel is on and they have not switched off. */
export function notificationsActive(study: EsmiraStudy, userId: string, native: boolean): boolean {
  return channelEnabled(study, native) && !participantOff(study.id, userId);
}
