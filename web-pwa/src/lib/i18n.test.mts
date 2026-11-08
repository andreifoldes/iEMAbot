import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { getContentLang, loadDictionary, matchLang, pickInterfaceLang, pickLang, setContentLang, setDictionaries, t } from './i18n.ts';

const here = path.dirname(fileURLToPath(import.meta.url));
const dashboardLocalesDir = path.resolve(here, '..', '..', '..', 'src', 'locales');
const localesDir = path.join(dashboardLocalesDir, 'pwa'); // the participant app's own strings
const en = JSON.parse(readFileSync(path.join(localesDir, 'en.json'), 'utf-8')) as Record<string, string>;

test('t() uses the selected language, then English, then the key', () => {
  setDictionaries({ a: 'Hello', b: 'Only English' }, { a: 'Hallo' });
  assert.equal(t('a'), 'Hallo');
  assert.equal(t('b'), 'Only English');
  assert.equal(t('missing'), 'missing');
});

test('English is the fallback: an empty or missing value in the selected language shows English', () => {
  setDictionaries({ a: 'Hello', b: 'Bye' }, { a: '', b: 'Tschüss', c: 'only here' });
  assert.equal(t('a'), 'Hello');          // blank in the language file (left untranslated)
  assert.equal(t('b'), 'Tschüss');
  assert.equal(t('c'), 'only here');
  assert.equal(t('constructor'), 'constructor'); // inherited object members are not strings
  assert.equal(t('nope'), 'nope');
});

test('loadDictionary ends in English when the language is unknown, fails to load or is malformed', async () => {
  const errors: unknown[] = [];
  const loaders = {
    de: async () => ({ a: 'Hallo' }),
    fr: async () => { throw new TypeError('Failed to fetch dynamically imported module'); },
    it: async () => null as unknown as Record<string, string>,
  };
  assert.deepEqual(await loadDictionary('de', loaders), { code: 'de', dict: { a: 'Hallo' } });
  assert.deepEqual(await loadDictionary('en', loaders), { code: 'en', dict: {} });
  assert.deepEqual(await loadDictionary('xx', loaders), { code: 'en', dict: {} });
  assert.deepEqual(await loadDictionary('constructor', loaders), { code: 'en', dict: {} });
  assert.deepEqual(await loadDictionary('fr', loaders, (e) => errors.push(e)), { code: 'en', dict: {} });
  assert.equal(errors.length, 1);
  assert.deepEqual(await loadDictionary('it', loaders), { code: 'en', dict: {} });
  // and the result still reads as English through t()
  setDictionaries({ a: 'Hello' }, (await loadDictionary('fr', loaders)).dict);
  assert.equal(t('a'), 'Hello');
});

test('every copy of the module shares one dictionary (test loaders and hot reload evaluate it twice)', async () => {
  const copy = './i18n.ts?second-copy'; // a query string makes Node evaluate the file again
  const other = await import(copy) as typeof import('./i18n.ts');
  setDictionaries({ shared: 'English' }, { shared: 'Deutsch' });
  assert.equal(other.t('shared'), 'Deutsch');
  other.setContentLang('de');
  assert.equal(getContentLang(), 'de');
  setContentLang(null);
  assert.equal(other.getContentLang(), null);
});

test('t() fills %s and %d in order', () => {
  setDictionaries({ n: '%d of %d done by %s' }, {});
  assert.equal(t('n', 2, 5, 'Sam'), '2 of 5 done by Sam');
});

test('matchLang maps browser and study codes to locale file names', () => {
  const have = ['en', 'de', 'nb_NO', 'zh_Hans', 'pt'];
  assert.equal(matchLang('de-AT', have), 'de');
  assert.equal(matchLang('DE', have), 'de');
  assert.equal(matchLang('nb', have), 'nb_NO');
  assert.equal(matchLang('no', have), 'nb_NO');
  assert.equal(matchLang('zh-CN', have), 'zh_Hans');
  assert.equal(matchLang('zh-TW', have), null);
  assert.equal(matchLang('pt-BR', have), 'pt');
  assert.equal(matchLang('xx', have), null);
  assert.equal(matchLang('', have), null);
});

test('pickLang takes the first candidate that is available, else English', () => {
  assert.equal(pickLang([null, 'sv', 'de'], ['en', 'de']), 'de');
  assert.equal(pickLang(['sv'], ['en', 'de']), 'en');
});

test('the researcher dashboard offers exactly the locale files', () => {
  const src = readFileSync(path.resolve(here, '..', '..', '..', 'src', 'frontend', 'ts', 'data', 'webLanguages.ts'), 'utf-8');
  const list = src.slice(src.indexOf('WEB_LANGUAGES = ['), src.indexOf('] as const'));
  const listed = [...list.matchAll(/"([A-Za-z_]+)"/g)].map((m) => m[1]).sort();
  const files = readdirSync(dashboardLocalesDir).filter((f) => f.endsWith('.json')).map((f) => f.slice(0, -5)).sort();
  assert.deepEqual(listed, files);
});

function sourceFiles(dir: string, ext: RegExp = /\.tsx?$/): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) return sourceFiles(p, ext);
    return ext.test(e.name) && !/\.test\./.test(e.name) ? [p] : [];
  });
}

const repoSrc = path.resolve(here, '..', '..', '..', 'src');
/** The app's code and the server code that words notifications from the same files. */
const codeFiles = [
  ...sourceFiles(path.resolve(here, '..')),
  ...sourceFiles(path.join(repoSrc, 'backend'), /\.php$/),
  ...sourceFiles(path.join(repoSrc, 'api'), /\.php$/),
];

test('every pwa_ key used in the app or the server code exists in pwa/en.json', () => {
  const used = new Map<string, string>();
  for (const file of codeFiles) {
    for (const m of readFileSync(file, 'utf-8').matchAll(/['"`](pwa_[a-z0-9_]+)['"`]/g)) used.set(m[1], path.basename(file));
  }
  assert.ok(used.size > 0);
  const missing = [...used].filter(([k]) => !(k in en)).map(([k, f]) => `${k} (${f})`);
  assert.deepEqual(missing, []);
});

test('every key in pwa/en.json is used by the app or the server code', () => {
  const all = codeFiles.map((f) => readFileSync(f, 'utf-8')).join('\n');
  const unused = Object.keys(en).filter((k) => !all.includes(`'${k}'`) && !all.includes(`"${k}"`));
  assert.deepEqual(unused, []);
});

/** Placeholders (%s, %d, {slot}) and bold markers of a string, in order. */
function shape(text: string): string[] {
  return [...text.matchAll(/%[sd]|\{[a-z]+\}|\*\*/g)].map((m) => m[0]);
}

test('the participant-app files hold only pwa_ keys, and translations only of existing ones', () => {
  const problems: string[] = [];
  for (const file of readdirSync(localesDir).filter((f) => f.endsWith('.json'))) {
    const lang = JSON.parse(readFileSync(path.join(localesDir, file), 'utf-8')) as Record<string, string>;
    for (const key of Object.keys(lang)) {
      if (!key.startsWith('pwa_')) problems.push(`${file}: ${key} does not start with pwa_`);
      else if (!(key in en)) problems.push(`${file}: ${key} is not an English key`);
    }
  }
  assert.deepEqual(problems, []);
});

test('every translated string keeps the English placeholders, in the same order', () => {
  const problems: string[] = [];
  for (const file of readdirSync(localesDir).filter((f) => f.endsWith('.json') && f !== 'en.json')) {
    const lang = JSON.parse(readFileSync(path.join(localesDir, file), 'utf-8')) as Record<string, string>;
    for (const [key, value] of Object.entries(lang)) {
      if (typeof value !== 'string' || value === '' || !(key in en)) continue; // blank = untranslated, English shows
      if (shape(value).join(' ') !== shape(en[key]).join(' ')) problems.push(`${file}: ${key} placeholders differ (${JSON.stringify(value)})`);
    }
  }
  assert.deepEqual(problems, []);
});

// --- machine-translated languages: labelled, and never picked from the browser's language -----------------

const machineListFile = path.join(dashboardLocalesDir, 'meta', 'pwa-machine-translated.json');
const AVAILABLE = ['en', 'da', 'de', 'fi', 'sv'];
const MACHINE = ['da', 'fi', 'sv'];

test('the researcher can choose a machine-translated language on purpose', () => {
  assert.equal(pickInterfaceLang({ setting: 'da', browser: ['de'] }, AVAILABLE, MACHINE), 'da');
});

test('the study language can pick a machine-translated language too (the researcher wrote the study in it)', () => {
  assert.equal(pickInterfaceLang({ studyLang: 'fi', browser: ['de'] }, AVAILABLE, MACHINE), 'fi');
});

test('the browser language never picks a machine-translated language', () => {
  assert.equal(pickInterfaceLang({ browser: ['sv-SE', 'da'] }, AVAILABLE, MACHINE), 'en');
  assert.equal(pickInterfaceLang({ browser: ['sv-SE', 'de-AT'] }, AVAILABLE, MACHINE), 'de'); // skips to a reviewed one
});

test('with nothing machine-translated the order is unchanged: setting, study language, browser, English', () => {
  assert.equal(pickInterfaceLang({ setting: 'de', studyLang: 'fi', browser: ['sv'] }, AVAILABLE, []), 'de');
  assert.equal(pickInterfaceLang({ studyLang: 'fi', browser: ['de'] }, AVAILABLE, []), 'fi');
  assert.equal(pickInterfaceLang({ browser: ['sv-SE'] }, AVAILABLE, []), 'sv');
  assert.equal(pickInterfaceLang({ browser: ['xx'] }, AVAILABLE, []), 'en');
  assert.equal(pickInterfaceLang({ setting: 'xx', studyLang: 'de', browser: ['de'] }, AVAILABLE, []), 'en'); // a chosen but untranslated language shows English
});

test('the machine-translated list is sorted, has no duplicates, and names only languages that have a file', () => {
  const list = JSON.parse(readFileSync(machineListFile, 'utf-8')) as string[];
  assert.ok(Array.isArray(list));
  assert.deepEqual(list, [...new Set(list)].sort());
  assert.ok(!list.includes('en'));
  for (const code of list) assert.ok(readdirSync(localesDir).includes(`${code}.json`), `${code} is listed but has no file`);
});

test('every machine-translated language carries the note that says so', () => {
  const list = JSON.parse(readFileSync(machineListFile, 'utf-8')) as string[];
  for (const code of list) {
    const lang = JSON.parse(readFileSync(path.join(localesDir, `${code}.json`), 'utf-8')) as Record<string, string>;
    assert.ok(lang.pwa_machine_translated_note, `${code} has no pwa_machine_translated_note`);
  }
});

test('the dashboard has a label for machine-translated languages', () => {
  const dashboardEn = JSON.parse(readFileSync(path.join(dashboardLocalesDir, 'en.json'), 'utf-8')) as Record<string, string>;
  assert.ok(dashboardEn.web_language_machine);
});
