import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setCurrentLang } from './i18n.ts';
import { pushSubscribePayload } from './pushSubscribePayload.ts';

const args = {
  study: { id: 7, lang: 'de' },
  serverVersion: 12,
  userId: 'p1',
  subscription: { endpoint: 'https://fcm.googleapis.com/x', keys: { p256dh: 'a', auth: 'b' } },
};

test('subscribing tells the server the app language and the language the study arrived in', () => {
  setCurrentLang('nb_NO');
  const body = pushSubscribePayload(args);
  assert.equal(body.uiLang, 'nb_NO');
  assert.equal(body.studyLang, 'de');
  assert.equal(body.userId, 'p1');
  assert.equal(body.studyId, 7);
  assert.equal(typeof body.tzOffset, 'number');
  setCurrentLang('en');
});

test('the app language is English until another has loaded', () => {
  setCurrentLang('en');
  assert.equal(pushSubscribePayload(args).uiLang, 'en');
  assert.equal(pushSubscribePayload({ ...args, study: { id: 7, lang: undefined } }).studyLang, undefined);
});
