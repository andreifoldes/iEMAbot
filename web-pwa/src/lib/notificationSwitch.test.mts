import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { EsmiraStudy } from '../types.ts';
import { channelEnabled, hasUnsyncedChoice, notificationsActive, participantOff, setParticipantOff } from './notificationSwitch.ts';

const mem = new Map<string, string>();
(globalThis as unknown as { localStorage: Storage }).localStorage = {
  getItem: (k: string) => mem.get(k) ?? null,
  setItem: (k: string, v: string) => { mem.set(k, v); },
  removeItem: (k: string) => { mem.delete(k); },
  clear: () => mem.clear(),
  key: () => null,
  length: 0,
} as Storage;

const study = (over: Partial<EsmiraStudy> = {}) => ({ id: 3, title: 'S', questionnaires: [], ...over } as unknown as EsmiraStudy);

test('the research team switches each channel per study', () => {
  assert.equal(channelEnabled(study(), false), false); // web push is opt-in
  assert.equal(channelEnabled(study({ webPushEnabled: true }), false), true);
  assert.equal(channelEnabled(study(), true), true); // the Android app is on unless switched off
  assert.equal(channelEnabled(study({ androidNotificationsEnabled: false }), true), false);
});

test('the participant switch is per study and participant, and remembers an unsent choice', () => {
  assert.equal(participantOff(3, 'u'), false);
  setParticipantOff(3, 'u', true, true);
  assert.equal(participantOff(3, 'u'), true);
  assert.equal(participantOff(4, 'u'), false);
  assert.equal(hasUnsyncedChoice(3, 'u'), true);
  assert.equal(notificationsActive(study(), 'u', true), false);
  setParticipantOff(3, 'u', true, false); // the server confirmed it
  assert.equal(hasUnsyncedChoice(3, 'u'), false);
  setParticipantOff(3, 'u', false, false);
  assert.equal(notificationsActive(study(), 'u', true), true);
  assert.equal(notificationsActive(study({ androidNotificationsEnabled: false }), 'u', true), false);
});
