import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { EsmiraQuestionnaire } from '../types.ts';
import { micOnboardingWanted, microphonePermission, requestMicrophone, usesMicrophone } from './permissions.ts';

const q = (...types: string[]): EsmiraQuestionnaire =>
  ({ internalId: 1, title: 'Q', pages: [{ inputs: types.map((t, i) => ({ name: `i${i}`, responseType: t })) }] });

test('usesMicrophone finds a record_audio item on any page', () => {
  assert.equal(usesMicrophone([q('likert', 'text_input')]), false);
  assert.equal(usesMicrophone([q('likert'), q('text', 'record_audio')]), true);
  assert.equal(usesMicrophone([]), false);
});

test('micOnboardingWanted: on by default for studies with a voice item, off when the researcher disables it', () => {
  assert.equal(micOnboardingWanted({ questionnaires: [q('record_audio')] }), true);
  assert.equal(micOnboardingWanted({ questionnaires: [q('record_audio')], webMicOnboarding: true }), true);
  assert.equal(micOnboardingWanted({ questionnaires: [q('record_audio')], webMicOnboarding: false }), false);
  assert.equal(micOnboardingWanted({ questionnaires: [q('likert')], webMicOnboarding: true }), false);
});

test('requestMicrophone releases the microphone straight after the prompt', async () => {
  let stopped = 0;
  const media = { getUserMedia: async () => ({ getTracks: () => [{ stop: () => { stopped++; } }] }) as unknown as MediaStream };
  assert.equal(await requestMicrophone(media), 'granted');
  assert.equal(stopped, 1);
});

test('requestMicrophone maps refusals and missing support', async () => {
  const failWith = (name: string) => ({ getUserMedia: async () => { throw Object.assign(new Error(name), { name }); } });
  assert.equal(await requestMicrophone(failWith('NotAllowedError')), 'denied');
  assert.equal(await requestMicrophone(failWith('NotFoundError')), 'error');
  assert.equal(await requestMicrophone({} as Pick<MediaDevices, 'getUserMedia'>), 'unsupported');
});

test('microphonePermission reads the Permissions API and tolerates browsers without "microphone"', async () => {
  assert.equal(await microphonePermission({ query: async () => ({ state: 'granted' }) as PermissionStatus }), 'granted');
  assert.equal(await microphonePermission({ query: async () => { throw new TypeError('bad name'); } }), 'unknown');
  assert.equal(await microphonePermission({} as Pick<Permissions, 'query'>), 'unknown');
});
