/**
 * "Smartphone only" study setting.
 *
 * A researcher can tick *Only allow participation on a smartphone* in the study editor
 * (`webPhoneOnly`). On any other device the participant app shows an invite page with a
 * QR code instead of starting the study — before consent, before the participant ID is
 * claimed, and before anything is sent to the server, so scanning the code on a phone
 * starts from a clean slate.
 *
 * This is a UX guard for data quality, not access control: the check runs in the
 * participant's browser, which they control.
 */
import type { EsmiraStudy } from '../types';

/** localStorage flag, per study, set by "I'm on my phone — continue here" on this device. */
export const phoneOverrideKey = (studyId: number): string => `esmira_phone_override_${studyId}`;

/**
 * Whether the invite page should replace the study. Strictly opt-in: only an explicit
 * `true` from the study editor gates, so every existing study behaves as before.
 *
 * `exempt` is for a device that must not be turned away: one that already consented to
 * this study (so switching the setting on mid-study locks nobody out), or where the
 * participant chose to continue here.
 */
export function isPhoneGated(
  study: Pick<EsmiraStudy, 'webPhoneOnly'>,
  device: { phone: boolean },
  exempt: boolean,
): boolean {
  return study.webPhoneOnly === true && !device.phone && !exempt;
}

/** True once the participant chose to continue on this device for this study. Never throws. */
export function readPhoneOverride(studyId: number): boolean {
  try {
    return localStorage.getItem(phoneOverrideKey(studyId)) === '1';
  } catch {
    return false;
  }
}

/** Remember "continue here" for this study on this device. Best-effort: storage may be blocked. */
export function setPhoneOverride(studyId: number): void {
  try {
    localStorage.setItem(phoneOverrideKey(studyId), '1');
  } catch {
    /* storage blocked — the page falls back to asking again on reload */
  }
}

/**
 * The link to hand to the phone: the current page without its hash, with the invite code
 * added if this page was reached without one (an installed app relaunching from its
 * remembered code). Everything else — participant ID included — is kept as is.
 */
export function handoffUrl(href: string, code: string): string {
  try {
    const url = new URL(href);
    url.hash = '';
    if (code && !url.searchParams.has('key') && !url.searchParams.has('access_key')) {
      url.searchParams.set('key', code);
    }
    return url.toString();
  } catch {
    return href;
  }
}
