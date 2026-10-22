import test from 'node:test';
import assert from 'node:assert/strict';
import { parseJoinLink } from './joinLink.ts';

test('parses server, key and participant id', () => {
  assert.deepEqual(parseJoinLink('esmira://join?server=https%3A%2F%2Fexample.org&key=demo&pid=p1'), {
    server: 'https://example.org/esmira/', key: 'demo', pid: 'p1',
  });
});

test('server and pid are optional', () => {
  assert.deepEqual(parseJoinLink('esmira://join?key=abc'), { server: null, key: 'abc', pid: '' });
});

test('rejects other schemes/hosts, a missing key, and an unusable server', () => {
  assert.equal(parseJoinLink('https://example.org/?key=abc'), null);
  assert.equal(parseJoinLink('esmira://other?key=abc'), null);
  assert.equal(parseJoinLink('esmira://join?server=https://example.org'), null);
  assert.equal(parseJoinLink('esmira://join?key=abc&server=javascript://x'), null);
  assert.equal(parseJoinLink('not a url'), null);
});

test('does not depend on how URL splits host and path (Chromium vs Node)', () => {
  assert.deepEqual(parseJoinLink('esmira://join/?key=abc'), { server: null, key: 'abc', pid: '' });
  assert.deepEqual(parseJoinLink('ESMIRA://join?key=abc#frag'), { server: null, key: 'abc', pid: '' });
  assert.equal(parseJoinLink('esmira://joinx?key=abc'), null);
  assert.equal(parseJoinLink('esmira://join/extra?key=abc'), null);
});
