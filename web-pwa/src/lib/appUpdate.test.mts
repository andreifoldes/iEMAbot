/** Android app updates (appUpdate.ts): which server build is offered to the installed app. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkAppUpdate, updateFrom } from './appUpdate.ts';

const SERVER = 'https://iemabot.example.org/';
const release = { versionCode: 29_900_000, versionName: '3.7.2', sha: 'abc1234', apk: 'app/iemabot.apk' };

test('offers a newer build, with the download on the server', () => {
  assert.deepEqual(updateFrom(release, 45, SERVER), { versionName: '3.7.2', versionCode: 29_900_000, url: 'https://iemabot.example.org/app/iemabot.apk' });
});

test('nothing when the installed app is the same build or newer', () => {
  assert.equal(updateFrom(release, 29_900_000, SERVER), null);
  assert.equal(updateFrom(release, 29_900_001, SERVER), null);
});

test('nothing for a server without an app build or a malformed answer', () => {
  assert.equal(updateFrom(null, 45, SERVER), null);
  assert.equal(updateFrom({ versionCode: 'soon', apk: 'app/iemabot.apk' }, 45, SERVER), null);
  assert.equal(updateFrom({ versionCode: 29_900_000 }, 45, SERVER), null);
});

test('outside the native app there is nothing to check', async () => {
  assert.equal(await checkAppUpdate(SERVER), null);
});
