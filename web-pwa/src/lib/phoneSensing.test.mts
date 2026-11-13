import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  sensingSettings, missingPermissions, configurePhoneSensing, recordPromptEvent, cancelPhoneSensing,
  participantCanChooseNetwork, uploadNetworkFor, chooseUploadNetwork, type PhoneSensingStatus,
} from './phoneSensing.ts';
import { getOrCreateDeviceToken } from './deviceBinding.ts';
import type { EsmiraStudy } from '../types';

const study = (extra: Partial<EsmiraStudy> = {}): EsmiraStudy => ({ id: 7, questionnaires: [], ...extra } as EsmiraStudy);

const status = (extra: Partial<PhoneSensingStatus> = {}): PhoneSensingStatus => ({
  configured: true, lightSensor: true, accelerometer: true, playServices: true,
  activityPermission: 'prompt', usageAccess: false, pendingBatches: 0, ...extra,
});

test('sensingSettings: null when the study senses nothing, defaults and clamping otherwise', () => {
  assert.equal(sensingSettings(null), null);
  assert.equal(sensingSettings(study()), null);
  assert.equal(sensingSettings(study({ phoneSensingUsage: 'bogus' })), null);
  assert.equal(sensingSettings(study({ phoneSensingLight: true, webLocalOnly: true })), null);
  assert.deepEqual(sensingSettings(study({ phoneSensingLight: true })), {
    light: true, activity: false, accelerometer: false, accelBeforeMin: 10, accelAfterMin: 10, accelHz: 20, usage: '',
    uploadNetwork: 'wifi_preferred', wifiFallbackHours: 24, participantNetwork: true,
  });
  const s = sensingSettings(study({ phoneSensingAccelerometer: true, phoneSensingAccelBeforeMin: 90, phoneSensingAccelAfterMin: -3, phoneSensingAccelHz: 0, phoneSensingUsage: 'both' }));
  assert.equal(s?.accelBeforeMin, 60);
  assert.equal(s?.accelAfterMin, 0);
  assert.equal(s?.accelHz, 1);
  assert.equal(s?.usage, 'both');
});

test('missingPermissions: only what the study needs and the device can provide', () => {
  const both = sensingSettings(study({ phoneSensingActivity: true, phoneSensingUsage: 'daily' }));
  assert.deepEqual(missingPermissions(both, status()), { activity: true, usage: true });
  assert.deepEqual(missingPermissions(both, status({ activityPermission: 'granted', usageAccess: true })), { activity: false, usage: false });
  // Without Play services activity recognition cannot work, so there is nothing to ask for.
  assert.deepEqual(missingPermissions(both, status({ playServices: false })), { activity: false, usage: true });
  const light = sensingSettings(study({ phoneSensingLight: true }));
  assert.deepEqual(missingPermissions(light, status()), { activity: false, usage: false });
  assert.deepEqual(missingPermissions(null, null), { activity: false, usage: false });
});

test('no-op outside the native app', async () => {
  delete (globalThis as Record<string, unknown>).Capacitor;
  assert.equal(await configurePhoneSensing({ server: 's', study: study({ phoneSensingLight: true }), userId: 'u', accessKey: '', promptTimes: [] }), null);
  await recordPromptEvent(study({ phoneSensingLight: true }), 'opened', 1, 'Q');
  await cancelPhoneSensing();
});

test('native: configure passes the settings, prompt times only for the accelerometer; prompt events only when useful', async () => {
  const calls: unknown[] = [];
  (globalThis as Record<string, unknown>).Capacitor = {
    isNativePlatform: () => true,
    Plugins: { PhoneSensing: {
      configure: async (o: unknown) => { calls.push(o); return status(); },
      promptEvent: async (o: unknown) => { calls.push(o); return {}; },
      cancel: async () => { calls.push('cancel'); },
    } },
  };
  await configurePhoneSensing({ server: 'https://x/', study: study({ phoneSensingLight: true }), userId: 'u', accessKey: 'k', promptTimes: [5] });
  assert.deepEqual(calls[0], {
    server: 'https://x/', studyId: 7, userId: 'u', accessKey: 'k', deviceToken: getOrCreateDeviceToken(), light: true, activity: false, accelerometer: false,
    accelBeforeMin: 10, accelAfterMin: 10, accelHz: 20, usage: '', uploadNetwork: 'wifi_preferred', wifiFallbackHours: 24,
    promptTimes: [],
  });
  await configurePhoneSensing({ server: 'https://x/', study: study({ phoneSensingAccelerometer: true }), userId: 'u', accessKey: '', promptTimes: [5, 6] });
  assert.deepEqual((calls[1] as { promptTimes: number[] }).promptTimes, [5, 6]);
  // A study without sensing still configures, which stops whatever ran before.
  await configurePhoneSensing({ server: 'https://x/', study: study(), userId: 'u', accessKey: '', promptTimes: [5] });
  assert.equal((calls[2] as { light: boolean }).light, false);

  await recordPromptEvent(study({ phoneSensingUsage: 'daily' }), 'opened', 1, 'Q'); // usage alone needs no prompt rows
  assert.equal(calls.length, 3);
  await recordPromptEvent(study({ phoneSensingActivity: true }), 'submitted', 1, 'Q');
  assert.deepEqual(calls[3], { event: 'submitted', questionnaireId: 1, questionnaire: 'Q' });
  await cancelPhoneSensing();
  assert.equal(calls.at(-1), 'cancel');
  delete (globalThis as Record<string, unknown>).Capacitor;
});

test('upload network: study rule, clamping, and the participant\'s choice where allowed', async () => {
  const store = new Map<string, string>();
  (globalThis as Record<string, unknown>).localStorage = {
    getItem: (k: string) => store.get(k) ?? null,
    setItem: (k: string, v: string) => void store.set(k, v),
    removeItem: (k: string) => void store.delete(k),
  };
  const bogus = sensingSettings(study({ phoneSensingLight: true, phoneSensingUploadNetwork: 'carrier-pigeon', phoneSensingWifiFallbackHours: 0 }))!;
  assert.equal(bogus.uploadNetwork, 'wifi_preferred');
  assert.equal(bogus.wifiFallbackHours, 1);

  const any = sensingSettings(study({ phoneSensingLight: true, phoneSensingUploadNetwork: 'any' }))!;
  assert.equal(participantCanChooseNetwork(any), true);
  assert.equal(uploadNetworkFor(any, 7, 'u'), 'any'); // nothing chosen yet: the study's rule

  const calls: unknown[] = [];
  (globalThis as Record<string, unknown>).Capacitor = {
    isNativePlatform: () => true,
    Plugins: { PhoneSensing: { setUploadNetwork: async (o: unknown) => { calls.push(o); return status(); } } },
  };
  await chooseUploadNetwork(any, 7, 'u', true);
  assert.equal(uploadNetworkFor(any, 7, 'u'), 'wifi_preferred');
  assert.deepEqual(calls[0], { uploadNetwork: 'wifi_preferred', wifiFallbackHours: 24 });
  assert.equal(uploadNetworkFor(any, 7, 'someone-else'), 'any'); // per participant

  // Wi-Fi only, or no choice offered: the study's rule wins over anything stored, and nothing is sent.
  const wifiOnly = sensingSettings(study({ phoneSensingLight: true, phoneSensingUploadNetwork: 'wifi_only' }))!;
  assert.equal(participantCanChooseNetwork(wifiOnly), false);
  assert.equal(uploadNetworkFor(wifiOnly, 7, 'u'), 'wifi_only');
  const fixed = sensingSettings(study({ phoneSensingLight: true, phoneSensingUploadNetwork: 'any', phoneSensingParticipantNetwork: false }))!;
  assert.equal(uploadNetworkFor(fixed, 7, 'u'), 'any');
  assert.equal(await chooseUploadNetwork(fixed, 7, 'u', true), null);
  assert.equal(calls.length, 1);

  delete (globalThis as Record<string, unknown>).Capacitor;
  delete (globalThis as Record<string, unknown>).localStorage;
});
