/**
 * Native Android app: phone sensing for studies that enable it (study fields phoneSensing*, set in the
 * admin's Phone sensing panel). The PhoneSensing plugin in android-app does the work: ambient light and
 * the current activity at each opened / submitted questionnaire, Google activity recognition, raw
 * accelerometer windows around the scheduled prompts, app usage, and the upload to api/phone_sensing.php.
 * This module hands it the study settings and prompt times and reports questionnaires. No-op in the
 * browser, which has none of these sensors.
 */

import { isNativeApp } from './platform.ts';
import { getOrCreateDeviceToken } from './deviceBinding.ts';
import type { EsmiraStudy } from '../types';

export type UsageMode = '' | 'daily' | 'events' | 'both';
/** When sensed data is uploaded: any connection, Wi-Fi with mobile data after waiting, or Wi-Fi only. */
export type UploadNetwork = 'any' | 'wifi_preferred' | 'wifi_only';

export interface PhoneSensingSettings {
  light: boolean;
  activity: boolean;
  accelerometer: boolean;
  accelBeforeMin: number;
  accelAfterMin: number;
  accelHz: number;
  usage: UsageMode;
  /** The study's rule; the participant's choice may change it (see uploadNetworkFor). */
  uploadNetwork: UploadNetwork;
  /** With 'wifi_preferred': hours data waits for Wi-Fi before mobile data is used. */
  wifiFallbackHours: number;
  /** The participant may switch between 'any' and 'wifi_preferred' (never with 'wifi_only'). */
  participantNetwork: boolean;
}

export interface PhoneSensingStatus {
  configured: boolean;
  lightSensor: boolean;
  accelerometer: boolean;
  playServices: boolean;
  activityPermission: 'granted' | 'denied' | 'prompt';
  usageAccess: boolean;
  pendingBatches: number;
  /** Last time the server accepted a batch (epoch ms), 0 if never. */
  lastUpload?: number;
  /** The upload rule the phone is following and the fallback hours (see uploadNetworkFor). */
  uploadNetwork?: UploadNetwork;
  wifiFallbackHours?: number;
}

interface PhoneSensingPlugin {
  configure(opts: Record<string, unknown>): Promise<PhoneSensingStatus>;
  status(): Promise<PhoneSensingStatus>;
  requestActivityPermission(): Promise<PhoneSensingStatus>;
  openUsageAccessSettings(): Promise<void>;
  setUploadNetwork(opts: { uploadNetwork: UploadNetwork; wifiFallbackHours: number }): Promise<PhoneSensingStatus>;
  promptEvent(opts: { event: PromptEvent; questionnaireId: number; questionnaire: string }): Promise<{ lightLux?: number; activity?: string }>;
  cancel(): Promise<void>;
}

/** `opened` when a questionnaire starts; `submitted` / `closed` (left or parked without submitting) when it ends. */
export type PromptEvent = 'opened' | 'submitted' | 'closed';

function plugin(): PhoneSensingPlugin | null {
  if (!isNativeApp()) return null;
  return (globalThis as { Capacitor?: { Plugins?: { PhoneSensing?: PhoneSensingPlugin } } }).Capacitor?.Plugins?.PhoneSensing ?? null;
}

function intIn(v: unknown, min: number, max: number, fallback: number): number {
  const n = typeof v === 'number' && Number.isFinite(v) ? Math.round(v) : fallback;
  return Math.min(max, Math.max(min, n));
}

/**
 * The study's sensing settings, or null when it senses nothing (or sends nothing to the server at all,
 * webLocalOnly). Defaults match data/study/Study.ts.
 */
export function sensingSettings(study: EsmiraStudy | null | undefined): PhoneSensingSettings | null {
  if (!study || study.webLocalOnly) return null;
  const usage: UsageMode = (['daily', 'events', 'both'] as const).find((m) => m === study.phoneSensingUsage) ?? '';
  const s: PhoneSensingSettings = {
    light: study.phoneSensingLight === true,
    activity: study.phoneSensingActivity === true,
    accelerometer: study.phoneSensingAccelerometer === true,
    accelBeforeMin: intIn(study.phoneSensingAccelBeforeMin, 0, 60, 10),
    accelAfterMin: intIn(study.phoneSensingAccelAfterMin, 0, 60, 10),
    accelHz: intIn(study.phoneSensingAccelHz, 1, 100, 20),
    usage,
    uploadNetwork: (['any', 'wifi_preferred', 'wifi_only'] as const).find((n) => n === study.phoneSensingUploadNetwork) ?? 'wifi_preferred',
    wifiFallbackHours: intIn(study.phoneSensingWifiFallbackHours, 1, 168, 24),
    participantNetwork: study.phoneSensingParticipantNetwork !== false,
  };
  return s.light || s.activity || s.accelerometer || s.usage ? s : null;
}

// The participant's own choice (Phone sensing panel), per study and participant, kept on the device.
const NETWORK_CHOICE_PREFIX = 'esmira_sensing_network_';

function networkChoiceKey(studyId: number, userId: string): string {
  return `${NETWORK_CHOICE_PREFIX}${studyId}_${userId}`;
}

/** Whether the participant can choose (the study allows it and does not require Wi-Fi only). */
export function participantCanChooseNetwork(settings: PhoneSensingSettings | null): boolean {
  return !!settings && settings.participantNetwork && settings.uploadNetwork !== 'wifi_only';
}

/** The rule this participant's phone follows: the study's, or their own choice where the study allows one. */
export function uploadNetworkFor(settings: PhoneSensingSettings, studyId: number, userId: string): UploadNetwork {
  if (!participantCanChooseNetwork(settings)) return settings.uploadNetwork;
  let choice: string | null = null;
  try { choice = localStorage.getItem(networkChoiceKey(studyId, userId)); } catch { /* storage unavailable */ }
  return choice === 'any' || choice === 'wifi_preferred' ? choice : settings.uploadNetwork;
}

/**
 * The participant switched "Wait for Wi-Fi" on or off: remember it and tell the phone right away.
 * Ignored when the study does not offer the choice. Returns the new status.
 */
export async function chooseUploadNetwork(settings: PhoneSensingSettings, studyId: number, userId: string, waitForWifi: boolean): Promise<PhoneSensingStatus | null> {
  if (!participantCanChooseNetwork(settings)) return null;
  try { localStorage.setItem(networkChoiceKey(studyId, userId), waitForWifi ? 'wifi_preferred' : 'any'); } catch { /* storage unavailable */ }
  try {
    return (await plugin()?.setUploadNetwork({ uploadNetwork: uploadNetworkFor(settings, studyId, userId), wifiFallbackHours: settings.wifiFallbackHours })) ?? null;
  } catch { return null; }
}

/** What the participant still has to allow for the study's sensors to work. */
export function missingPermissions(settings: PhoneSensingSettings | null, status: PhoneSensingStatus | null): { activity: boolean; usage: boolean } {
  if (!settings || !status) return { activity: false, usage: false };
  return {
    activity: settings.activity && status.playServices && status.activityPermission !== 'granted',
    usage: settings.usage !== '' && !status.usageAccess,
  };
}

/**
 * Hand the study's settings and upcoming prompt times to the device (every app start and whenever the
 * reminder plan changes). A study that senses nothing stops whatever was running. Returns the status.
 */
export async function configurePhoneSensing(args: {
  server: string;
  study: EsmiraStudy;
  userId: string;
  accessKey: string;
  promptTimes: number[];
}): Promise<PhoneSensingStatus | null> {
  const p = plugin();
  if (!p) return null;
  const s = sensingSettings(args.study);
  try {
    return await p.configure({
      server: args.server,
      studyId: args.study.id,
      userId: args.userId,
      accessKey: args.accessKey,
      deviceToken: getOrCreateDeviceToken(),
      light: s?.light ?? false,
      activity: s?.activity ?? false,
      accelerometer: s?.accelerometer ?? false,
      accelBeforeMin: s?.accelBeforeMin ?? 10,
      accelAfterMin: s?.accelAfterMin ?? 10,
      accelHz: s?.accelHz ?? 20,
      usage: s?.usage ?? '',
      uploadNetwork: s ? uploadNetworkFor(s, args.study.id, args.userId) : 'wifi_preferred',
      wifiFallbackHours: s?.wifiFallbackHours ?? 24,
      promptTimes: s?.accelerometer ? args.promptTimes : [],
    });
  } catch {
    return null; // never let sensing break the questionnaire flow
  }
}

export async function phoneSensingStatus(): Promise<PhoneSensingStatus | null> {
  try { return (await plugin()?.status()) ?? null; } catch { return null; }
}

/** Shows Android's Physical activity permission dialog (if it has not been decided yet). */
export async function requestActivityPermission(): Promise<PhoneSensingStatus | null> {
  try { return (await plugin()?.requestActivityPermission()) ?? null; } catch { return null; }
}

/** Opens Android's Usage access settings; re-read the status when the app is visible again. */
export async function openUsageAccessSettings(): Promise<boolean> {
  const p = plugin();
  if (!p) return false;
  try { await p.openUsageAccessSettings(); return true; } catch { return false; }
}

/** Report an opened / finished questionnaire (ambient light, current activity, accelerometer window). */
export async function recordPromptEvent(study: EsmiraStudy | null | undefined, event: PromptEvent, questionnaireId: number, questionnaire: string): Promise<void> {
  const s = sensingSettings(study);
  if (!s || !(s.light || s.activity || s.accelerometer)) return;
  try { await plugin()?.promptEvent({ event, questionnaireId, questionnaire }); } catch { /* best effort */ }
}

/** Sign-out / leaving the study: stop sensing and drop what has not been uploaded. */
export async function cancelPhoneSensing(): Promise<void> {
  try { await plugin()?.cancel(); } catch { /* best effort */ }
}
