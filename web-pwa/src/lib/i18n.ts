/**
 * Interface text for the participant app. Pure (no browser or bundler imports) so it can be
 * unit-tested; `i18nStore.ts` wires it to the translation files and the browser.
 *
 * Keys live in ESMira's shared `src/locales/<code>.json`, prefixed `pwa_`. English is the
 * fallback, so a key that a language has not translated yet shows in English.
 */
export type Dict = Record<string, string>;

/**
 * The state is kept on `globalThis` so every copy of this module shares it. Test loaders (Vite's SSR
 * loader) and hot reloading can evaluate the module more than once, and each copy would otherwise
 * see an empty dictionary.
 */
interface State { fallback: Dict; current: Dict; contentLang: string | null; lang: string }
const KEY = Symbol.for('iemabot.pwa.i18n');
const g = globalThis as unknown as Record<symbol, State | undefined>;
const state: State = (g[KEY] ??= { fallback: {}, current: {}, contentLang: null, lang: 'en' });

/**
 * Language of the study's own text (questions, descriptions), which can differ from the interface
 * language. Read-aloud uses it so a question is spoken in the language it is written in.
 */
export function setContentLang(lang: string | null | undefined): void {
  state.contentLang = lang || null;
}

export function getContentLang(): string | null {
  return state.contentLang;
}

/** Code of the interface language in use (a locale file name such as `de` or `nb_NO`; `en` until one loads). */
export function setCurrentLang(code: string): void {
  state.lang = code;
}

export function getCurrentLang(): string {
  return state.lang;
}

export function setDictionaries(en: Dict, selected: Dict): void {
  state.fallback = en;
  state.current = selected;
}

/**
 * Text for `key`. `%s` and `%d` are replaced in order by the extra arguments, the same
 * placeholders ESMira's own translations use. An unknown key returns the key itself.
 */
export function t(key: string, ...replacers: Array<string | number>): string {
  // Fallback chain: selected language, then English, then the key itself. An empty or non-string
  // value (an untranslated string left blank in a translation file) counts as missing.
  const has = (d: Dict) => typeof d[key] === 'string' && d[key] !== '' && Object.prototype.hasOwnProperty.call(d, key);
  let value = has(state.current) ? state.current[key] : has(state.fallback) ? state.fallback[key] : key;
  for (const r of replacers) value = value.replace(/%[sd]/, () => String(r));
  return value;
}

/**
 * Load one language's dictionary. Any problem (no such language, the chunk fails to load, e.g.
 * offline, or it is not an object) ends in English: an empty dictionary under code `en`, so `t()`
 * falls through to the English text.
 */
export async function loadDictionary(
  code: string,
  loaders: Record<string, () => Promise<Dict>>,
  onError: (e: unknown) => void = () => {},
): Promise<{ code: string; dict: Dict }> {
  if (code === 'en' || !Object.prototype.hasOwnProperty.call(loaders, code)) return { code: 'en', dict: {} };
  try {
    const dict = await loaders[code]();
    if (dict && typeof dict === 'object') return { code, dict };
  } catch (e) {
    onError(e);
  }
  return { code: 'en', dict: {} };
}

/** Locale files are named like `de`, `nb_NO` or `zh_Hans`; browsers say `de-AT`, `nb`, `zh-CN`. */
export function matchLang(candidate: string | null | undefined, available: readonly string[]): string | null {
  if (!candidate) return null;
  const norm = candidate.trim().replace('-', '_');
  if (!norm) return null;
  const byLower = new Map(available.map((c) => [c.toLowerCase(), c]));
  const full = byLower.get(norm.toLowerCase());
  if (full) return full;
  const base = norm.split('_')[0].toLowerCase();
  const direct = byLower.get(base);
  if (direct) return direct;
  if (base === 'no' || base === 'nn' || base === 'nb') return byLower.get('nb_no') ?? null;
  if (base === 'zh') return /hant|tw|hk|mo/i.test(norm) ? null : byLower.get('zh_hans') ?? null; // no Traditional file
  return [...byLower.entries()].find(([lower]) => lower.startsWith(`${base}_`))?.[1] ?? null;
}

/** First candidate (in order of preference) that one of the available languages covers. */
export function pickLang(candidates: ReadonlyArray<string | null | undefined>, available: readonly string[]): string {
  for (const c of candidates) {
    const hit = matchLang(c, available);
    if (hit) return hit;
  }
  return 'en';
}

/**
 * Which language the app speaks.
 *  1. The researcher's choice (`setting`) wins, even for a language with no translations yet (that shows English).
 *  2. Else the study's own language.
 *  3. Else the browser's languages, but only languages people have reviewed: a machine-translated language is
 *     never picked just because of the participant's phone settings, only when a researcher chooses it.
 *  4. Else English.
 */
export function pickInterfaceLang(
  opts: { setting?: string | null; studyLang?: string | null; browser: ReadonlyArray<string | null | undefined> },
  available: readonly string[],
  machine: readonly string[],
): string {
  if (opts.setting) return matchLang(opts.setting, available) ?? 'en';
  const fromStudy = matchLang(opts.studyLang, available);
  if (fromStudy) return fromStudy;
  return pickLang(opts.browser, available.filter((c) => !machine.includes(c)));
}
