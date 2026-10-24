/**
 * Browser PWA side of the offline task copies (see taskCache.ts for the native app).
 *
 * The task pages live outside the PWA's service-worker scope (/pwa/), so the worker cannot answer
 * for them directly. Instead the page downloads a task's files into the Cache API and runs the iframe
 * through a path inside the scope — `<base>t/<server path>` — which the service worker (src/sw.ts)
 * maps back to the cached original, falling through to the network on a miss. Relative imports inside
 * the task resolve below that same path, so the whole tree is served from the cache.
 *
 * Only same-origin tasks are handled. The copy is used only when the worker has answered a probe,
 * so an older worker without the route can never serve the app's own index page into the iframe.
 */

import type { TaskManifest } from './taskCache.ts';

const INDEX_KEY = 'esmira_task_cache_web';
export const WEB_TASK_CACHE_PREFIX = 'esmira-task:';

type WebIndex = Record<string, string>; // task dir URL -> manifest version

function readIndex(): WebIndex {
  try { return JSON.parse(localStorage.getItem(INDEX_KEY) || '{}') as WebIndex; } catch { return {}; }
}
function writeIndex(i: WebIndex): void {
  try { localStorage.setItem(INDEX_KEY, JSON.stringify(i)); } catch { /* storage unavailable */ }
}

/** Path inside the worker's scope that stands for a same-origin task URL. */
export function proxyTaskUrl(taskUrl: string, base: string): string {
  const u = new URL(taskUrl);
  return `${base}t/${u.pathname.replace(/^\//, '')}${u.search}${u.hash}`;
}

export function cacheNameFor(taskDir: string, version: string): string {
  return `${WEB_TASK_CACHE_PREFIX}${taskDir}:${version}`;
}

export function webCacheAvailable(origin: string, taskUrl: string): boolean {
  try {
    return typeof caches !== 'undefined' && new URL(taskUrl).origin === origin;
  } catch { return false; }
}

/** Download one same-origin task into the Cache API. True once a complete, current copy is in place. */
export async function downloadTaskWeb(taskDir: string, manifest: TaskManifest, rootUrl: string): Promise<boolean> {
  const index = readIndex();
  if (index[taskDir] === manifest.version) return true;

  const name = cacheNameFor(taskDir, manifest.version);
  await caches.delete(name); // never reuse a half-written cache of the same version
  const cache = await caches.open(name);
  try {
    for (const f of manifest.files) {
      const url = new URL(f.path, rootUrl).toString();
      const resp = await fetch(url, { cache: 'reload' });
      if (!resp.ok) throw new Error(`${resp.status} for ${f.path}`);
      const body = await resp.clone().arrayBuffer();
      if (body.byteLength !== f.size) throw new Error(`size mismatch for ${f.path}`);
      await cache.put(url, resp);
    }
  } catch (err) {
    await caches.delete(name);
    throw err;
  }
  const done = readIndex();
  done[taskDir] = manifest.version;
  writeIndex(done);
  // Drop superseded copies of this task only after the new one is complete.
  const prefix = `${WEB_TASK_CACHE_PREFIX}${taskDir}:`;
  for (const k of await caches.keys()) if (k.startsWith(prefix) && k !== name) await caches.delete(k);
  return true;
}

/** True when the worker has the task route and a complete copy of this task exists. */
export async function webTaskReady(taskDir: string, base: string): Promise<boolean> {
  if (!readIndex()[taskDir]) return false;
  try {
    const r = await fetch(`${base}t/__ping`, { cache: 'no-store' });
    return r.ok && r.headers.get('x-esmira-task-route') === '1';
  } catch { return false; }
}
