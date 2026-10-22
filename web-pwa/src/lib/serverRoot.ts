/**
 * Single source of truth for the ESMira backend root (the PHP app + api/).
 *
 * Web: same-origin `/esmira/` (overridable at build time via VITE_API_ROOT).
 * Native app: one generic APK serves every server, so the root is a runtime setting taken
 * from the invite link / QR / manual entry and persisted in localStorage.
 */

const STORE_KEY = 'esmira_server_base';
const DEFAULT_PATH = '/esmira/';

function buildDefault(): string {
  const env = (import.meta as { env?: Record<string, string | undefined> }).env;
  return env?.VITE_API_ROOT || DEFAULT_PATH;
}

/**
 * Build-time suggestion for the native app's server field (VITE_DEFAULT_SERVER). It only pre-fills
 * the first-run form; nothing is used until the participant confirms it, and it can be edited.
 */
export function getDefaultServer(): string {
  const env = (import.meta as { env?: Record<string, string | undefined> }).env;
  return env?.VITE_DEFAULT_SERVER?.trim() || '';
}

/**
 * Normalise user/invite input into an absolute http(s) root ending in "/".
 * A bare origin ("https://host") gets the default "/esmira/" path appended.
 * Returns null for anything that is not a usable http(s) URL.
 */
export function normalizeServerBase(input: string): string | null {
  const raw = input.trim();
  if (!raw) return null;
  let url: URL;
  try {
    url = new URL(/^[a-z][a-z0-9+.-]*:\/\//i.test(raw) ? raw : `https://${raw}`);
  } catch {
    return null;
  }
  if (url.protocol !== 'https:' && url.protocol !== 'http:') return null;
  url.hash = '';
  url.search = '';
  let path = url.pathname;
  if (path === '' || path === '/') path = DEFAULT_PATH;
  if (!path.endsWith('/')) path += '/';
  return `${url.origin}${path}`;
}

export function getStoredServerBase(): string | null {
  try {
    return localStorage.getItem(STORE_KEY);
  } catch {
    return null;
  }
}

/** Persist a server root; returns the normalised value, or null if the input was rejected. */
export function setServerBase(input: string): string | null {
  const normalized = normalizeServerBase(input);
  if (!normalized) return null;
  try {
    localStorage.setItem(STORE_KEY, normalized);
  } catch {
    /* storage unavailable: the value is simply not remembered */
  }
  return normalized;
}

export function clearServerBase(): void {
  try {
    localStorage.removeItem(STORE_KEY);
  } catch {
    /* ignore */
  }
}

/** Current API root, always ending in "/". Stored runtime value wins over the build default. */
export function getApiRoot(): string {
  return getStoredServerBase() || buildDefault();
}
