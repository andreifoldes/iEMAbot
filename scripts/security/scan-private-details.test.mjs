// Verifies each rule fires on a positive sample and stays quiet on a negative one.
// Samples are assembled at runtime so this file does not trip the scanner itself.
import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const config = JSON.parse(readFileSync(join(here, 'private-patterns.json'), 'utf8'));
const compile = (src) => (src.startsWith('(?i)') ? new RegExp(src.slice(4), 'i') : new RegExp(src));
const rule = (id) => compile(config.contentRules.find((r) => r.id === id).regex);
const pathRule = (id) => new RegExp(config.forbiddenPathPatterns.find((r) => r.id === id).regex);
const j = (...p) => p.join('');

const CASES = {
  'tailscale-cgnat-ip': {
    yes: [j('100', '.', '100', '.', '1', '.', '1'), j('100.64', '.0.9'), j('100.127', '.255.255')],
    no: [j('100.63', '.0.1'), j('100.128', '.0.1'), '1.100.100.100x'],
  },
  'private-ipv4': {
    yes: [j('10', '.0.0.5'), j('192.168', '.1.20'), j('172.16', '.0.1'), j('172.31', '.9.9')],
    no: [j('172.32', '.0.1'), j('8.8', '.8.8'), j('172.15', '.0.1')],
  },
  'tailnet-hostname': {
    yes: [j('host.tail1234', '.ts', '.net'), j('box', '.ts', '.net')],
    no: ['routes.net', 'ts.network'],
  },
  'unix-home-path': {
    yes: [j('/Users/', 'alice', '/Dev/x'), j('/home/', 'bob', '/app/run.sh')],
    no: [j('/home/', 'runner', '/work'), j('/Users/', 'username', '/x'), j('/home/', 'node', '/y'), '/usr/local/bin'],
  },
  'windows-home-path': {
    yes: [j('C:', '\\Users\\', 'alice', '\\Desktop\\')],
    no: [j('C:', '\\Users\\', 'Public', '\\x\\')],
  },
  'personal-email': {
    yes: [j('jane.doe', '@', 'gmail.com'), j('someone', '@', 'surrey.ac.uk')],
    no: [j('a', '@', 'example.com'), j('12345+bot', '@', 'users.noreply.github.com')],
  },
  'password-note': {
    yes: [j('sudo ', 'password: in the notes'), j('Root ', 'password in vault')],
    no: ['sudo apt update', 'password reset flow'],
  },
  'ssh-user-at-host': {
    yes: [j('ssh ', 'deploy', '@', 'server.example.org uptime'), j('ssh -p 22 ', 'bob', '@', 'host')],
    no: ['ssh iema-alias', 'ssh-keygen -t ed25519'],
  },
  'private-key-block': {
    yes: [j('-----BEGIN ', 'OPENSSH PRIVATE KEY', '-----')],
    no: [j('-----BEGIN ', 'PUBLIC KEY', '-----')],
  },
  'token-shape': {
    yes: [j('ghp_', 'a'.repeat(36)), j('AKIA', 'ABCDEFGHIJKLMNOP'), j('sk-ant-', 'x'.repeat(30))],
    no: ['ghp_short', 'sk-short'],
  },
};

for (const [id, {yes, no}] of Object.entries(CASES)) {
  test(`content rule ${id}`, () => {
    const re = rule(id);
    for (const s of yes) assert.ok(re.test(s), `${id} should match a positive sample`);
    for (const s of no) assert.ok(!re.test(s), `${id} should NOT match a negative sample: ${s}`);
  });
}

test('every content rule has a test case', () => {
  for (const r of config.contentRules) assert.ok(CASES[r.id], `missing test for ${r.id}`);
});

test('forbidden paths', () => {
  assert.ok(pathRule('ai-session-log').test('.claude/logs/session-1.md'));
  assert.ok(pathRule('ai-session-log').test('web-pwa/.claude/logs/x.md'));
  assert.ok(pathRule('dotenv').test('.env'));
  assert.ok(pathRule('dotenv').test('app/.env.production'));
  assert.ok(!pathRule('dotenv').test('docs/environment.md'));
  assert.ok(pathRule('key-material').test('certs/server.pem'));
  assert.ok(pathRule('named-secret').test('config/api_token.txt'));
  assert.ok(pathRule('mcp-config').test('.mcp.json'));
  assert.ok(pathRule('esmira-data').test('esmira_data/studies/1/x.csv'));
  assert.ok(!pathRule('credentials-file').test('src/credentials-form.ts'));
});
