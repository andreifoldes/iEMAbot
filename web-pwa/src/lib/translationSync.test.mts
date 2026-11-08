import { test } from 'node:test';
import assert from 'node:assert/strict';
// @ts-expect-error plain JS module outside the app (scripts/)
import { chunk, cleanTranslation, crowdinIdForFile, fileCodeForCrowdin, formatLocale, shape, withMachineLang } from '../../../scripts/translation-sync-lib.mjs';

const en = { pwa_a: 'Hello', pwa_n: 'You have %d of %s', pwa_b: 'Tap **Add** in {share}' };

test('shape lists placeholders and bold markers in order', () => {
  assert.deepEqual(shape('x %d y %s **z** {share}'), ['%d', '%s', '**', '**', '{share}']);
});

test('blank and missing translations are dropped, so English shows through', () => {
  const { clean, problems } = cleanTranslation(en, { pwa_a: 'Hej', pwa_n: '', pwa_b: undefined });
  assert.deepEqual(clean, { pwa_a: 'Hej' });
  assert.deepEqual(problems, []);
});

test('a string with different placeholders is dropped and reported', () => {
  const { clean, problems } = cleanTranslation(en, { pwa_a: 'Hej', pwa_n: 'Du har %s av %d', pwa_b: 'Tryck **Lägg till**' });
  assert.deepEqual(clean, { pwa_a: 'Hej' });
  assert.equal(problems.length, 2);
  assert.match(problems[0], /pwa_n: placeholders differ/);
});

test('keys that are not participant-app keys are dropped; the English order is kept', () => {
  const { clean, problems } = cleanTranslation(en, { pwa_b: 'Tryck **Lägg till** i {share}', admin_key: 'x', pwa_unknown: 'y', pwa_a: 'Hej' });
  assert.deepEqual(Object.keys(clean), ['pwa_a', 'pwa_b']);
  assert.equal(problems.filter((p: string) => /not a participant-app key/.test(p)).length, 2);
});

test('anything that is not an object, and non-string values, are rejected', () => {
  assert.deepEqual(cleanTranslation(en, null).problems, ['not a JSON object']);
  assert.deepEqual(cleanTranslation(en, ['x']).problems, ['not a JSON object']);
  const { clean, problems } = cleanTranslation(en, { pwa_a: 5 });
  assert.deepEqual(clean, {});
  assert.match(problems[0], /not a string/);
});

test('files are written tab-indented, one entry per line, and round-trip', () => {
  const text = formatLocale({ pwa_a: 'Hej', pwa_b: 'Å "citat"' });
  assert.equal(text, '{\n\t"pwa_a": "Hej",\n\t"pwa_b": "Å \\"citat\\""\n}\n');
  assert.deepEqual(JSON.parse(text), { pwa_a: 'Hej', pwa_b: 'Å "citat"' });
  assert.equal(formatLocale({}), '{}\n');
});

test('Crowdin language ids map to the file codes the app looks up', () => {
  assert.equal(fileCodeForCrowdin('sv-SE'), 'sv');
  assert.equal(fileCodeForCrowdin('es-ES'), 'es');
  assert.equal(fileCodeForCrowdin('nb'), 'nb_NO');
  assert.equal(fileCodeForCrowdin('zh-CN'), 'zh_Hans');
  assert.equal(fileCodeForCrowdin('de'), 'de');
  assert.equal(fileCodeForCrowdin('fil'), 'fil');
});

test('ambiguous or unknown Crowdin languages are never guessed', () => {
  // 'sv' alone is a file code, not a Crowdin id: accepting it would shadow the mapped 'sv-SE'
  for (const id of ['pt-PT', 'pt-BR', 'sr-CS', 'zh-TW', 'de-AT', 'en', '', '../x', 'sv']) {
    assert.equal(fileCodeForCrowdin(id), null, id);
  }
});

test('file codes map back to Crowdin ids, and the two directions agree', () => {
  assert.equal(crowdinIdForFile('sv'), 'sv-SE');
  assert.equal(crowdinIdForFile('nb_NO'), 'nb');
  assert.equal(crowdinIdForFile('zh_Hans'), 'zh-CN');
  assert.equal(crowdinIdForFile('de'), 'de');
  assert.equal(crowdinIdForFile('en'), null);
  assert.equal(crowdinIdForFile('pt-BR'), null);
  for (const code of ['sv', 'es', 'nb_NO', 'zh_Hans', 'de', 'fr']) {
    assert.equal(fileCodeForCrowdin(crowdinIdForFile(code) as string), code);
  }
});

test('chunk splits a list into runs of at most the given size', () => {
  assert.deepEqual(chunk([1, 2, 3, 4, 5], 2), [[1, 2], [3, 4], [5]]);
  assert.deepEqual(chunk([], 100), []);
  assert.equal(chunk(Array.from({ length: 509 }, (_, i) => i), 100).length, 6);
});

test('a language is added to the machine-translated list once, sorted, and never English', () => {
  assert.deepEqual(withMachineLang(['sv'], 'da'), ['da', 'sv']);
  assert.deepEqual(withMachineLang(['da'], 'da'), ['da']);
  assert.deepEqual(withMachineLang([], 'en'), []);
});
