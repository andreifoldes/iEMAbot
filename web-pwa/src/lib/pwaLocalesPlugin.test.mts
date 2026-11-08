import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { pwaLocales } from '../../vite-plugin-pwa-locales.ts';

const dir = mkdtempSync(path.join(tmpdir(), 'pwa-locales-'));
const write = (code: string, data: object) => writeFileSync(path.join(dir, `${code}.json`), JSON.stringify(data));
write('en', { admin_only: 'Dashboard text', pwa_hello: 'Hello', pwa_bye: 'Bye' });
write('de', { admin_only: 'Dashboardtext', pwa_hello: 'Hallo', pwa_bye: '' });   // one blank: untranslated
write('fr', { admin_only: 'Texte' });                                              // no pwa_ keys at all
write('it', { pwa_hello: 12 });                                                    // malformed value

const plugin = pwaLocales(dir);
const ctx = { addWatchFile() {} } as never;
const load = (id: string) => (plugin.load as (this: unknown, id: string) => string | null).call(ctx, id);
const resolveId = (id: string) => (plugin.resolveId as (id: string) => string | null)(id);

test('only pwa_ keys reach the participant bundle, English in full', () => {
  const out = load('\0virtual:pwa-locales')!;
  assert.match(out, /"pwa_hello":"Hello"/);
  assert.match(out, /"pwa_bye":"Bye"/);
  assert.doesNotMatch(out, /admin_only/);
});

test('a language gets a chunk only when it has at least one usable pwa_ string', () => {
  const out = load('\0virtual:pwa-locales')!;
  assert.match(out, /"de": \(\) => import\("virtual:pwa-locale\/de"\)/);
  assert.doesNotMatch(out, /"fr"/);
  assert.doesNotMatch(out, /"it"/);
  assert.doesNotMatch(out, /"en": \(\)/);
});

test('blank translations are left out so English shows through', () => {
  assert.equal(load('\0virtual:pwa-locale/de'), 'export default {"pwa_hello":"Hallo"};\n');
  assert.equal(load('\0virtual:pwa-locale/it'), 'export default {};\n');
  assert.equal(load('\0virtual:pwa-locale/missing'), 'export default {};\n'); // no such file: empty, English
});

test('language codes cannot escape the locales folder', () => {
  assert.equal(load('\0virtual:pwa-locale/../../etc/passwd'), null);
  assert.equal(resolveId('./other'), null);
  assert.equal(resolveId('virtual:pwa-locales'), '\0virtual:pwa-locales');
});

const metaDir = mkdtempSync(path.join(tmpdir(), 'pwa-meta-')); // the list lives outside the language folder

test('the machine-translated list names only languages that have translations', () => {
  const file = path.join(metaDir, 'machine.json');
  writeFileSync(file, JSON.stringify(['de', 'fr', 'zz', '../x', 5]));
  const withList = pwaLocales(dir, file);
  const ctx2 = { addWatchFile() {} } as never;
  const out = (withList.load as (this: unknown, id: string) => string | null).call(ctx2, '\0virtual:pwa-locales')!;
  assert.match(out, /export const machine = \["de"\];/); // fr has no strings, zz no file, the rest is not a code
});

test('a missing or broken list means no language is flagged', () => {
  const none = pwaLocales(dir, path.join(metaDir, 'nope.json'));
  const broken = path.join(metaDir, 'broken.json');
  writeFileSync(broken, '{not json');
  const ctx2 = { addWatchFile() {} } as never;
  for (const plugin2 of [none, pwaLocales(dir, broken), pwaLocales(dir)]) {
    const out = (plugin2.load as (this: unknown, id: string) => string | null).call(ctx2, '\0virtual:pwa-locales')!;
    assert.match(out, /export const machine = \[\];/);
  }
});
