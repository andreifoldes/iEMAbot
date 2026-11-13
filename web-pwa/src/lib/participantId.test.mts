/**
 * Tests for the "who may join" study setting (pidMode / pidFromUrl): which participant id the app uses and when it
 * has to ask the server or the participant. Pure decisions; the server side is tested in PHPUnit.
 *
 * Run with `npm test`.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { acceptedPidKey, decidePid, participantIdKey, pidSettings, withoutPidParams } from './participantId.ts';

const OPEN = pidSettings({});
const LIST = pidSettings({ pidMode: 'list' });
const LIST_PROMPT_ONLY = pidSettings({ pidMode: 'list', pidFromUrl: false });

describe('pidSettings', () => {
  it('a study is open unless it says list', () => {
    assert.deepEqual(pidSettings({}), { mode: 'open', fromUrl: true });
    assert.equal(pidSettings({ pidMode: 'open' }).mode, 'open');
    assert.equal(pidSettings({ pidMode: 'everyone' }).mode, 'open');
    assert.equal(pidSettings({ pidMode: 'list' }).mode, 'list');
  });
  it('the id in the link is used unless the researcher turned that off', () => {
    assert.equal(pidSettings({ pidMode: 'list' }).fromUrl, true);
    assert.equal(pidSettings({ pidMode: 'list', pidFromUrl: true }).fromUrl, true);
    assert.equal(pidSettings({ pidMode: 'list', pidFromUrl: false }).fromUrl, false);
  });
  it('a local-only study sends nothing, so it is never restricted', () => {
    assert.equal(pidSettings({ pidMode: 'list', webLocalOnly: true }).mode, 'open');
  });
});

describe('decidePid in an open study', () => {
  it('leaves everything as it always was', () => {
    assert.deepEqual(decidePid(OPEN, 'P1', 'old', ''), { kind: 'open' });
    assert.deepEqual(decidePid(OPEN, '', '', ''), { kind: 'open' });
  });
});

describe('decidePid in a restricted study', () => {
  it('uses the id from the link and has the server check it', () => {
    assert.deepEqual(decidePid(LIST, 'P001', '', ''), { kind: 'use', pid: 'P001', source: 'url', check: true });
  });
  it('the link beats an id stored from before', () => {
    assert.deepEqual(decidePid(LIST, 'P001', 'P002', 'P002'), { kind: 'use', pid: 'P001', source: 'url', check: true });
  });
  it('does not ask again for an id the server already accepted on this device', () => {
    assert.deepEqual(decidePid(LIST, 'P001', 'P001', 'P001'), { kind: 'use', pid: 'P001', source: 'url', check: false });
    assert.deepEqual(decidePid(LIST, '', 'P001', 'P001'), { kind: 'use', pid: 'P001', source: 'stored', check: false });
  });
  it('an id stored by an earlier open study is checked once', () => {
    assert.deepEqual(decidePid(LIST, '', 'random-uuid', ''), { kind: 'use', pid: 'random-uuid', source: 'stored', check: true });
  });
  it('asks the participant when there is neither a link id nor a stored one', () => {
    assert.deepEqual(decidePid(LIST, '', '', ''), { kind: 'prompt' });
    assert.deepEqual(decidePid(LIST, '   ', '  ', ''), { kind: 'prompt' });
  });
  it('trims what it is given', () => {
    assert.deepEqual(decidePid(LIST, ' P001 ', '', ''), { kind: 'use', pid: 'P001', source: 'url', check: true });
  });
  it('ignores the id in the link when the researcher wants it typed', () => {
    assert.deepEqual(decidePid(LIST_PROMPT_ONLY, 'P001', '', ''), { kind: 'prompt' });
    assert.deepEqual(decidePid(LIST_PROMPT_ONLY, 'P001', 'P007', 'P007'), { kind: 'use', pid: 'P007', source: 'stored', check: false });
  });
});

describe('keys and links', () => {
  it('keeps the id where an open study keeps it, and what the server accepted beside it', () => {
    assert.equal(participantIdKey(42), 'esmira_userid_42');
    assert.equal(acceptedPidKey(42), 'esmira_pidok_42');
  });
  it('takes every spelling of the participant id out of the address and keeps the rest', () => {
    const out = new URL(withoutPidParams('https://x.example/pwa/?key=abc&id=7&pid=P1&uid=P1&user_id=P1&userId=P1#frag'));
    assert.equal(out.searchParams.get('key'), 'abc');
    assert.equal(out.searchParams.get('id'), '7');
    for (const name of ['pid', 'uid', 'user_id', 'userId']) assert.equal(out.searchParams.has(name), false, name);
    assert.equal(out.hash, '#frag');
  });
});
