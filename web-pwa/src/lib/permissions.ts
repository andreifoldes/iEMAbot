/**
 * Device permissions a study needs, worked out from its items, and the onboarding step
 * that asks for the microphone. Onboarding explains why each permission is needed and
 * only then triggers the browser's (or Android's) own prompt, so the system dialog never
 * appears without context.
 *
 * - Notifications: web-push studies (`webPushEnabled`) — the notifications step in App.tsx.
 * - Microphone: studies with a `record_audio` item. Asked during onboarding unless the
 *   researcher turned that off (`webMicOnboarding: false`); the browser then asks the first
 *   time the voice recorder opens instead.
 */

import type { EsmiraQuestionnaire, EsmiraStudy } from '../types';

/** Does any questionnaire contain a voice-recording item? */
export function usesMicrophone(qs: EsmiraQuestionnaire[]): boolean {
  return qs.some((q) => (q.pages ?? []).some((p) => (p.inputs ?? []).some((i) => i.responseType === 'record_audio')));
}

/** Should onboarding include the microphone step for this study? */
export function micOnboardingWanted(study: Pick<EsmiraStudy, 'webMicOnboarding' | 'questionnaires'>): boolean {
  return study.webMicOnboarding !== false && usesMicrophone(study.questionnaires);
}

/** localStorage flag: the microphone step was completed (allowed or skipped) for this study. */
export function micOnboardedKey(studyId: number): string {
  return `esmira_mic_onboarded_${studyId}`;
}

export type MicPermission = 'granted' | 'denied' | 'prompt' | 'unknown';

/**
 * The microphone permission state without prompting. 'unknown' where the browser cannot
 * report it (the Permissions API has no "microphone" name in some browsers).
 */
export async function microphonePermission(
  permissions: Pick<Permissions, 'query'> | undefined = typeof navigator !== 'undefined' ? navigator.permissions : undefined,
): Promise<MicPermission> {
  if (!permissions?.query) return 'unknown';
  try {
    const status = await permissions.query({ name: 'microphone' as PermissionName });
    return status.state;
  } catch {
    return 'unknown';
  }
}

export type MicRequestResult = 'granted' | 'denied' | 'unsupported' | 'error';

/**
 * Shows the system microphone prompt by opening the microphone once, then releases it at
 * once (nothing is recorded). 'unsupported' = no microphone API here (e.g. an insecure
 * page); 'error' = no microphone found or the device refused to open it.
 */
export async function requestMicrophone(
  media: Pick<MediaDevices, 'getUserMedia'> | undefined = typeof navigator !== 'undefined' ? navigator.mediaDevices : undefined,
): Promise<MicRequestResult> {
  if (!media?.getUserMedia) return 'unsupported';
  try {
    const stream = await media.getUserMedia({ audio: true });
    stream.getTracks().forEach((t) => t.stop());
    return 'granted';
  } catch (e) {
    const name = (e as { name?: string })?.name;
    return name === 'NotAllowedError' || name === 'SecurityError' ? 'denied' : 'error';
  }
}
