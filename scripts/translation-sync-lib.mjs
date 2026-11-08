/**
 * Pure helpers for scripts/crowdin-sync.mjs: check a translation file downloaded from Crowdin before it is
 * written into src/locales/pwa/. Kept apart from the network code so it can be unit-tested.
 */

/** Placeholders (%s, %d, {slot}) and bold markers of a string, in order. */
export function shape(text) {
  return [...String(text).matchAll(/%[sd]|\{[a-z]+\}|\*\*/g)].map((m) => m[0]);
}

/**
 * Turn one downloaded language file into what is committed.
 *  - blank values (untranslated on the service) are dropped, so English shows through
 *  - a key that is not an English key, or not a pwa_ key, is dropped and reported
 *  - a string whose placeholders differ from English is dropped and reported (the app would show a broken sentence)
 * Keys come out in the English file's order, so diffs stay small.
 * @param {Record<string,string>} en the source language
 * @param {unknown} downloaded the parsed file
 * @returns {{clean: Record<string,string>, problems: string[]}}
 */
export function cleanTranslation(en, downloaded) {
  const problems = [];
  const clean = {};
  if (!downloaded || typeof downloaded !== 'object' || Array.isArray(downloaded)) {
    return { clean, problems: ['not a JSON object'] };
  }
  for (const key of Object.keys(downloaded)) {
    if (!key.startsWith('pwa_') || !(key in en)) problems.push(`${key}: not a participant-app key, dropped`);
  }
  for (const key of Object.keys(en)) {
    const value = downloaded[key];
    if (value === undefined || value === null || value === '') continue;
    if (typeof value !== 'string') { problems.push(`${key}: not a string, dropped`); continue; }
    if (shape(value).join(' ') !== shape(en[key]).join(' ')) {
      problems.push(`${key}: placeholders differ from English (${JSON.stringify(value)}), dropped`);
      continue;
    }
    clean[key] = value;
  }
  return { clean, problems };
}

/** File text in the repository's style: tab-indented, one entry per line. */
export function formatLocale(obj) {
  const body = Object.entries(obj).map(([k, v]) => `\t${JSON.stringify(k)}: ${JSON.stringify(v)}`).join(',\n');
  return body ? `{\n${body}\n}\n` : '{}\n';
}

/**
 * Crowdin names languages by locale (`sv-SE`, `es-ES`), our files by the short code the app looks up
 * (`sv`, `es`, `nb_NO`, `zh_Hans`). Only unambiguous ones are listed: a language Crowdin splits into regional
 * variants (Portuguese, Serbian) must be added here on purpose, so it is never guessed.
 */
const CROWDIN_FILE_CODES = {
  'sv-SE': 'sv',
  'es-ES': 'es',
  nb: 'nb_NO',
  'zh-CN': 'zh_Hans',
};

/** The file code (`src/locales/pwa/<code>.json`) for a Crowdin language id, or null when it is not mapped. */
export function fileCodeForCrowdin(id) {
  if (Object.hasOwn(CROWDIN_FILE_CODES, id)) return CROWDIN_FILE_CODES[id];
  return /^[a-z]{2,3}$/.test(id) && id !== 'en' && !Object.values(CROWDIN_FILE_CODES).includes(id) ? id : null;
}

/** The Crowdin language id for a file code, or null when there is none (the reverse of the above). */
export function crowdinIdForFile(code) {
  const mapped = Object.entries(CROWDIN_FILE_CODES).find(([, file]) => file === code);
  if (mapped) return mapped[0];
  return /^[a-z]{2,3}$/.test(code) && code !== 'en' && !Object.hasOwn(CROWDIN_FILE_CODES, code) ? code : null;
}

/** Split a list into runs of at most `size` (Crowdin's machine-translation call takes up to 100 strings). */
export function chunk(list, size) {
  const out = [];
  for (let i = 0; i < list.length; i += size) out.push(list.slice(i, i + size));
  return out;
}

/** The machine-translated language list with `code` added: sorted, no duplicates, `en` never. */
export function withMachineLang(list, code) {
  return [...new Set([...list, code])].filter((c) => c !== 'en').sort();
}
