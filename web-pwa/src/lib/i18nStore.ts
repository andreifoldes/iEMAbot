import { useSyncExternalStore } from 'react';
import { en, loaders, machine } from 'virtual:pwa-locales';
import { loadDictionary, pickInterfaceLang, setContentLang, setCurrentLang, setDictionaries } from './i18n.ts';

/**
 * Which language the participant app speaks, and a hook that re-renders when it changes.
 * The researcher sets it per study (`webLanguage`, in the study settings). Without it the study's
 * own language is used, then the browser's languages (reviewed ones only, see `pickInterfaceLang`), then English.
 */

/** True for a language whose text is machine-translated and has not been reviewed by people. */
export function isMachineTranslated(lang: string): boolean {
  return machine.includes(lang);
}

/** English plus every language that has translated at least one participant-app string. */
export const availableLangs: readonly string[] = ['en', ...Object.keys(loaders)];

// English is available from the first render, even while another language's chunk is still loading.
setDictionaries(en, {});

let setting: string | null = null;
let studyLang: string | null = null;
let code = 'en';
let version = 0;
let latest = 0; // the newest apply() call wins if loads finish out of order
const listeners = new Set<() => void>();

async function apply(wanted: string): Promise<void> {
  const mine = ++latest;
  const { code: next, dict } = await loadDictionary(wanted, loaders, (e) => console.error('Could not load language', wanted, e));
  if (mine !== latest) return;
  setDictionaries(en, dict);
  setCurrentLang(next);
  code = next;
  version += 1;
  try { document.documentElement.lang = next.replace('_', '-'); } catch { /* no document */ }
  listeners.forEach((l) => l());
}

function resolve(): string {
  const nav = typeof navigator !== 'undefined' ? [...(navigator.languages ?? []), navigator.language] : [];
  return pickInterfaceLang({ setting, studyLang, browser: nav }, availableLangs, machine);
}

/** Call once at start-up (before the study is known). */
export function initLang(): Promise<void> {
  return apply(resolve());
}

/** Call when a study loads: the researcher's `webLanguage`, else the study's own language. */
export function setStudyLang(study: { webLanguage?: string; lang?: string }): Promise<void> {
  setting = study.webLanguage || null;
  studyLang = study.lang ?? null;
  setContentLang(studyLang);
  return apply(resolve());
}

/** Re-renders the calling component whenever the language changes; returns the current code. */
export function useLang(): string {
  useSyncExternalStore(
    (cb) => { listeners.add(cb); return () => listeners.delete(cb); },
    () => version,
  );
  return code;
}
