#!/usr/bin/env node
/**
 * Keep the participant app's translations in step with Crowdin.
 *
 *   node scripts/crowdin-sync.mjs push [--translations] [--dry-run]   upload src/locales/pwa/en.json (the source)
 *   node scripts/crowdin-sync.mjs pull [--dry-run]                    download translations into src/locales/pwa/<code>.json
 *   node scripts/crowdin-sync.mjs machine <code>... [--dry-run]       machine-translate languages that have no human
 *                                                                     translation (da, fi, nb_NO, ...) and mark them
 *
 * `push --translations` also uploads the language files that are already in the repository (use it once, to
 * seed a language, or after a hand edit). `pull` skips strings nobody has translated yet, so English shows
 * through, and drops any string whose placeholders differ from English.
 *
 * `machine` sends the English source through Crowdin's machine-translation engine, keeps only the strings whose
 * placeholders survived (the rest stay English), writes src/locales/pwa/<code>.json and lists the language in
 * src/locales/meta/pwa-machine-translated.json. The app and the dashboard label such languages, and the app does
 * not pick them from the browser's language. When people have reviewed a language, remove it from that list.
 * Run `push --translations` afterwards to put the result into Crowdin (as unapproved translations).
 *
 * Needs CROWDIN_API_KEY (a personal access token) in the environment or in a `.env` file at the repository
 * root; CROWDIN_PROJECT_ID overrides the project (default: the iEMAbot participant app project). The token is
 * only sent as an Authorization header, never printed. Crowdin never writes to our repositories: translations
 * come back through this script as a normal pull request.
 */
import { existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chunk, cleanTranslation, crowdinIdForFile, fileCodeForCrowdin, formatLocale, withMachineLang } from './translation-sync-lib.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const outDir = path.join(root, 'src', 'locales', 'pwa');
const machineList = path.join(root, 'src', 'locales', 'meta', 'pwa-machine-translated.json');
const API = 'https://api.crowdin.com/api/v2';
const SOURCE_NAME = 'en.json';

function loadDotEnv() {
  const file = path.join(root, '.env');
  if (!existsSync(file)) return;
  for (const line of readFileSync(file, 'utf-8').split('\n')) {
    const m = line.match(/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)=(.*)$/);
    if (m && process.env[m[1]] === undefined) process.env[m[1]] = m[2].trim().replace(/^(['"])(.*)\1$/, '$2');
  }
}

loadDotEnv();
const [command, ...rest] = process.argv.slice(2);
const dryRun = process.argv.includes('--dry-run');
const withTranslations = process.argv.includes('--translations');
const token = process.env.CROWDIN_API_KEY;
const projectId = process.env.CROWDIN_PROJECT_ID || '944553';
if (!['push', 'pull', 'machine'].includes(command)) {
  console.error('Usage: node scripts/crowdin-sync.mjs push [--translations] [--dry-run] | pull [--dry-run] | machine <code>... [--dry-run]');
  process.exit(1);
}
if (!token) { console.error('Set CROWDIN_API_KEY (environment or .env)'); process.exit(1); }
if (!/^\d+$/.test(projectId)) { console.error('CROWDIN_PROJECT_ID must be a number'); process.exit(1); }

async function call(method, route, body) {
  const resp = await fetch(`${API}${route}`, {
    method,
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!resp.ok) {
    let detail = '';
    try { detail = (await resp.json())?.error?.message ?? ''; } catch { /* no JSON body */ }
    throw new Error(`${method} ${route} returned ${resp.status}${detail ? `: ${detail}` : ''}`);
  }
  return resp.json();
}

/** Crowdin takes uploads in two steps: park the bytes in storage, then refer to the storage id. */
async function store(name, text) {
  const resp = await fetch(`${API}/storages`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', 'Crowdin-API-FileName': name },
    body: text,
  });
  if (!resp.ok) throw new Error(`POST /storages returned ${resp.status}`);
  return (await resp.json()).data.id;
}

async function sourceFileId() {
  const files = (await call('GET', `/projects/${projectId}/files?limit=100`)).data.map((f) => f.data);
  return files.find((f) => f.name === SOURCE_NAME)?.id ?? null;
}

async function push() {
  const en = readFileSync(path.join(outDir, SOURCE_NAME), 'utf-8');
  const count = Object.keys(JSON.parse(en)).length;
  let fileId = await sourceFileId();
  console.log(`${SOURCE_NAME}: ${count} source strings${fileId ? ' (updating the file on Crowdin)' : ' (new file on Crowdin)'}`);
  if (dryRun) return;
  const storageId = await store(SOURCE_NAME, en);
  if (fileId) await call('PUT', `/projects/${projectId}/files/${fileId}`, { storageId });
  else fileId = (await call('POST', `/projects/${projectId}/files`, { storageId, name: SOURCE_NAME, type: 'json' })).data.id;

  if (!withTranslations) return;
  const targets = (await call('GET', `/projects/${projectId}`)).data.targetLanguageIds;
  for (const file of readdirSync(outDir).filter((f) => f.endsWith('.json') && f !== SOURCE_NAME)) {
    const code = file.replace(/\.json$/, '');
    const id = crowdinIdForFile(code);
    if (!id || !targets.includes(id)) { console.warn(`${code}: not a target language of the Crowdin project, skipped`); continue; }
    const text = readFileSync(path.join(outDir, file), 'utf-8');
    const storage = await store(file, text);
    const result = await call('POST', `/projects/${projectId}/translations/${id}`, { storageId: storage, fileId, importEqSuggestions: true, autoApproveImported: false });
    console.log(`${code}: ${result.data.info.imported.strings} strings imported`);
  }
}

/** Machine-translate the English source into the given languages with Crowdin's own engine. */
async function machine() {
  const codes = rest.filter((a) => !a.startsWith('--'));
  if (codes.length === 0) throw new Error('Name at least one language code, for example: machine da fi nb_NO');
  const en = JSON.parse(readFileSync(path.join(outDir, SOURCE_NAME), 'utf-8'));
  const engines = (await call('GET', '/mts?limit=100')).data.map((m) => m.data);
  const engine = engines.find((m) => m.type === 'crowdin');
  if (!engine) throw new Error('No Crowdin machine-translation engine on this account');
  const keys = Object.keys(en);
  mkdirSync(outDir, { recursive: true });
  let list = existsSync(machineList) ? JSON.parse(readFileSync(machineList, 'utf-8')) : [];
  for (const code of codes) {
    const id = crowdinIdForFile(code);
    if (!id) { console.error(`${code}: no Crowdin language for this code, skipped`); continue; }
    const target = path.join(outDir, `${code}.json`);
    if (existsSync(target) && !list.includes(code) && Object.keys(JSON.parse(readFileSync(target, 'utf-8'))).length > 0) {
      console.error(`${code}: already has a translation that is not machine-made, skipped (delete the file to replace it)`);
      continue;
    }
    const translated = {};
    for (const batch of chunk(keys, 100)) {
      const result = await call('POST', `/mts/${engine.id}/translations`, {
        targetLanguageId: id, sourceLanguageId: 'en', strings: batch.map((k) => en[k]),
      });
      batch.forEach((k, i) => { translated[k] = result.data.translations[i]; });
    }
    const { clean, problems } = cleanTranslation(en, translated);
    problems.forEach((p) => console.warn(`${code}: ${p}`));
    console.log(`${code}: ${Object.keys(clean).length} of ${keys.length} strings machine-translated; the rest stay English`);
    if (dryRun) continue;
    writeFileSync(target, formatLocale(clean));
    list = withMachineLang(list, code);
  }
  if (!dryRun) {
    mkdirSync(path.dirname(machineList), { recursive: true });
    writeFileSync(machineList, `${JSON.stringify(list)}\n`);
  }
}

async function pull() {
  const en = JSON.parse(readFileSync(path.join(outDir, SOURCE_NAME), 'utf-8'));
  const fileId = await sourceFileId();
  if (!fileId) throw new Error('No en.json on Crowdin yet: run "push" first');
  const targets = (await call('GET', `/projects/${projectId}`)).data.targetLanguageIds;
  mkdirSync(outDir, { recursive: true });
  let changed = 0;
  for (const id of targets) {
    const code = fileCodeForCrowdin(id);
    if (!code) { console.warn(`${id}: no file code mapped for this language, skipped (see scripts/translation-sync-lib.mjs)`); continue; }
    const build = await call('POST', `/projects/${projectId}/translations/builds/files/${fileId}`, { targetLanguageId: id, skipUntranslatedStrings: true });
    const resp = await fetch(build.data.url);
    if (!resp.ok) throw new Error(`${id}: download returned ${resp.status}`);
    let parsed;
    try { parsed = JSON.parse(await resp.text()); } catch { console.error(`${id}: not valid JSON, skipped`); continue; }
    const { clean, problems } = cleanTranslation(en, parsed);
    problems.forEach((p) => console.warn(`${code}: ${p}`));
    const target = path.join(outDir, `${code}.json`);
    const next = formatLocale(clean);
    const before = existsSync(target) ? readFileSync(target, 'utf-8') : '';
    if (next === before) continue;
    changed += 1;
    console.log(`${code}: ${Object.keys(clean).length} strings${before ? '' : ' (new file)'}`);
    if (!dryRun && Object.keys(clean).length > 0) writeFileSync(target, next);
  }
  console.log(changed === 0 ? 'Already up to date.' : `${changed} language file(s) ${dryRun ? 'would change' : 'updated'}.`);
}

({ push, pull, machine }[command])().catch((e) => {
  console.error(`Crowdin sync failed: ${e.message}`);
  process.exit(1);
});
