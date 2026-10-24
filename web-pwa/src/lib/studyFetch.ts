/**
 * Study-definition fetch with an offline fallback (see fetchStudy). Kept apart from esmiraApi.ts so
 * it can be unit-tested under plain node.
 */
import type { EsmiraStudy, StudiesEnvelope } from '../types.ts';
import { getApiRoot } from './serverRoot.ts';
import { rewriteBundledAssets } from './bundledAssets.ts';

declare const __BUNDLED_PUBLIC__: string[] | undefined;
const bundled = typeof __BUNDLED_PUBLIC__ === 'undefined' ? [] : __BUNDLED_PUBLIC__;

export interface FetchedStudy {
  study: EsmiraStudy;
  serverVersion: number;
  /** Server VAPID public key for web push (only when a study has push enabled). */
  vapidPublicKey?: string;
  /** Wearable providers with credentials configured on the server. */
  wearableProviders?: string[];
}

const STUDY_ENV_PREFIX = 'esmira_study_env:';

function studyEnvKey(accessKey: string): string {
  return `${STUDY_ENV_PREFIX}${getApiRoot()}:${accessKey}`;
}

function pickStudy(env: StudiesEnvelope, studyId?: number): FetchedStudy {
  if (!env.success) throw new Error(env.error || 'Study fetch failed');
  if (!env.dataset?.length) throw new Error('No study found for this access key');
  const study = studyId != null
    ? env.dataset.find(s => s.id === studyId) ?? env.dataset[0]
    : env.dataset[0];
  return {
    study: rewriteBundledAssets(study, bundled),
    serverVersion: env.serverVersion,
    vapidPublicKey: env.vapidPublicKey,
    wearableProviders: env.wearableProviders,
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
    if (cached) return pickStudy(cached, studyId);
    throw err;
  }
  if (!resp.ok) {
    const cached = resp.status >= 500 ? readCachedEnvelope(cacheKey) : null;
    if (cached) return pickStudy(cached, studyId);
    throw new Error(`studies.php returned ${resp.status}`);
  }
  const env = (await resp.json()) as StudiesEnvelope;
  const picked = pickStudy(env, studyId);
  try { localStorage.setItem(cacheKey, JSON.stringify(env)); } catch { /* storage full or unavailable: just no offline copy */ }
  return picked;
}

function readCachedEnvelope(key: string): StudiesEnvelope | null {
  try {
    const raw = localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as StudiesEnvelope) : null;
  } catch { return null; }
}
