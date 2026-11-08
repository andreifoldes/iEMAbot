/**
 * Study-definition fetch with an offline fallback (see fetchStudy). Kept apart from esmiraApi.ts so
 * it can be unit-tested under plain node.
 */
import type { EsmiraStudy, StudiesEnvelope } from '../types.ts';
import { getApiRoot } from './serverRoot.ts';
import { rewriteBundledAssets } from './bundledAssets.ts';
import { t } from './i18n.ts';

declare const __BUNDLED_PUBLIC__: string[] | undefined;
const bundled = typeof __BUNDLED_PUBLIC__ === 'undefined' ? [] : __BUNDLED_PUBLIC__;

export interface FetchedStudy {
  study: EsmiraStudy;
  serverVersion: number;
  /** Server VAPID public key for web push (only when a study has push enabled). */
  vapidPublicKey?: string;
  /** Wearable providers with credentials configured on the server. */
  wearableProviders?: string[];
  /** The server also passes error reports on to developers on GitHub (shown to the participant). */
  errorReportsToGithub?: boolean;
  /** True when the server could not be reached and this is the copy saved on the device. */
  fromCache?: boolean;
}

const STUDY_ENV_PREFIX = 'esmira_study_env:';

function studyEnvKey(accessKey: string): string {
  return `${STUDY_ENV_PREFIX}${getApiRoot()}:${accessKey}`;
}

function pickStudy(env: StudiesEnvelope, studyId?: number): FetchedStudy {
  if (!env.success) throw new Error(env.error || t('pwa_api_study_fetch_failed'));
  if (!env.dataset?.length) throw new Error(t('pwa_api_no_study'));
  const study = studyId != null
    ? env.dataset.find(s => s.id === studyId) ?? env.dataset[0]
    : env.dataset[0];
  return {
    study: rewriteBundledAssets(study, bundled),
    serverVersion: env.serverVersion,
    vapidPublicKey: env.vapidPublicKey,
    wearableProviders: env.wearableProviders,
    errorReportsToGithub: env.errorReportsToGithub === true,
  };
}

/**
 * Fetch the study definition. The last good response is kept on the device, so a participant who
 * opens the app with no connection (a plane, a tunnel, a dead zone) still gets the study. Only a
 * network failure or a server error falls back to that copy; a rejected key or a "no such study"
 * answer is reported as before, so a withdrawn study is not kept alive by the cache.
 */
export async function fetchStudy(accessKey: string, studyId?: number): Promise<FetchedStudy> {
  const url = `${getApiRoot()}api/studies.php?access_key=${encodeURIComponent(accessKey)}`;
  const cacheKey = studyEnvKey(accessKey);
  let resp: Response;
  try {
    resp = await fetch(url, { headers: { Accept: 'application/json' } });
  } catch (err) {
    const cached = readCachedEnvelope(cacheKey);
    if (cached) return { ...pickStudy(cached, studyId), fromCache: true };
    throw err;
  }
  if (!resp.ok) {
    const cached = resp.status >= 500 ? readCachedEnvelope(cacheKey) : null;
    if (cached) return { ...pickStudy(cached, studyId), fromCache: true };
    throw new Error(`studies.php returned ${resp.status}`);
  }
  let env = (await resp.json()) as StudiesEnvelope;
  let picked = pickStudy(env, studyId);
  // The researcher chose the app's language: ask for the study's own text in it too, when the study
  // has been translated into it. Any problem keeps the study as first fetched (its default language).
  const wanted = studyLangToRequest(picked.study);
  if (wanted) {
    try {
      const again = await fetch(`${url}&study_lang=${encodeURIComponent(wanted)}`, { headers: { Accept: 'application/json' } });
      if (again.ok) {
        const env2 = (await again.json()) as StudiesEnvelope;
        picked = pickStudy(env2, studyId);
        env = env2;
      }
    } catch { /* keep the first copy */ }
  }
  try { localStorage.setItem(cacheKey, JSON.stringify(env)); } catch { /* storage full or unavailable: just no offline copy */ }
  return picked;
}

/**
 * The language code to request the study in, when the researcher's `webLanguage` names a language the
 * study is translated into and the study did not arrive in it. Otherwise null.
 */
export function studyLangToRequest(study: Pick<EsmiraStudy, 'webLanguage' | 'langCodes' | 'lang'>): string | null {
  const norm = (c: string) => c.trim().replace('-', '_').toLowerCase();
  const wanted = study.webLanguage ? norm(study.webLanguage) : '';
  if (!wanted || (study.lang && norm(study.lang) === wanted)) return null;
  const code = (study.langCodes ?? []).find((c) => typeof c === 'string' && norm(c) === wanted);
  return code ?? null;
}

function readCachedEnvelope(key: string): StudiesEnvelope | null {
  try {
    const raw = localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as StudiesEnvelope) : null;
  } catch { return null; }
}
