import test from 'node:test';
import assert from 'node:assert/strict';
import { joinNeedsConfirmation, parseJoinLink } from './joinLink.ts';

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

test('a remote server must be https; plain http is only for the machine itself (development)', () => {
  assert.equal(parseJoinLink('esmira://join?server=http%3A%2F%2Fexample.org&key=abc'), null);
  assert.equal(parseJoinLink('esmira://join?server=http%3A%2F%2F192.168.1.20&key=abc'), null);
  assert.deepEqual(parseJoinLink('esmira://join?server=http%3A%2F%2F10.0.2.2%3A8080&key=abc'), { server: 'http://10.0.2.2:8080/esmira/', key: 'abc', pid: '' });
  assert.deepEqual(parseJoinLink('esmira://join?server=http%3A%2F%2Flocalhost&key=abc'), { server: 'http://localhost/esmira/', key: 'abc', pid: '' });
  assert.ok(parseJoinLink('esmira://join?server=https%3A%2F%2Fexample.org&key=abc'));
});

test('a link needs the participant\'s yes unless it names the server the app already uses (or none)', () => {
  const link = (server: string | null) => ({ server, key: 'k', pid: '' });
  assert.equal(joinNeedsConfirmation(link(null), null), 'none', 'no server in the link');
  assert.equal(joinNeedsConfirmation(link(null), 'https://a.example/esmira/'), 'none');
  assert.equal(joinNeedsConfirmation(link('https://a.example/esmira/'), 'https://a.example/esmira/'), 'none', 'same server');
  assert.equal(joinNeedsConfirmation(link('https://a.example/esmira/'), null), 'new', 'first run');
  assert.equal(joinNeedsConfirmation(link('https://evil.example/esmira/'), 'https://a.example/esmira/'), 'switch');
});
