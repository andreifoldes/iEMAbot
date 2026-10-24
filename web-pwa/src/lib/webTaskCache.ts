/**
 * Browser PWA side of the offline task copies (see taskCache.ts for the native app).
 *
 * Files from every task go into ONE shared cache (esmira-task-files), keyed by URL, so a library shared
 * by several tasks (m2c2kit core, several MB) is stored and fetched once; a file is re-fetched only when its
 * sha256 in the manifest changed.
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
export const WEB_TASK_CACHE = 'esmira-task-files';

interface TaskEntry { version: string; urls: string[] }
interface WebState { tasks: Record<string, TaskEntry>; sha: Record<string, string> }

function readState(): WebState {
  try {
    const s = JSON.parse(localStorage.getItem(INDEX_KEY) || '{}') as Partial<WebState>;
    return { tasks: s.tasks ?? {}, sha: s.sha ?? {} };
  } catch { return { tasks: {}, sha: {} }; }
}
function writeState(s: WebState): void {
  try { localStorage.setItem(INDEX_KEY, JSON.stringify(s)); } catch { /* storage unavailable */ }
}

/** Path inside the worker's scope that stands for a same-origin task URL. */
export function proxyTaskUrl(taskUrl: string, base: string): string {
  const u = new URL(taskUrl);
  return `${base}t/${u.pathname.replace(/^\//, '')}${u.search}${u.hash}`;
}

export function webCacheAvailable(origin: string, taskUrl: string): boolean {
  try {
    return typeof caches !== 'undefined' && new URL(taskUrl).origin === origin;
  } catch { return false; }
}

/** Download one same-origin task into the shared cache. True once a complete, current copy is in place. */
export async function downloadTaskWeb(taskDir: string, manifest: TaskManifest, rootUrl: string): Promise<boolean> {
  const state = readState();
  if (state.tasks[taskDir]?.version === manifest.version) return true;

  const cache = await caches.open(WEB_TASK_CACHE);
  const urls: string[] = [];
  for (const f of manifest.files) {
    const url = new URL(f.path, rootUrl).toString();
    urls.push(url);
    // Already stored (shared library, or unchanged since the last version): keep it if it is intact.
    if (f.sha256 && state.sha[url] === f.sha256 && await cache.match(url)) continue;
    delete state.sha[url];
    const resp = await fetch(url, { cache: 'reload' });
    if (!resp.ok) { writeState(state); throw new Error(`${resp.status} for ${f.path}`); }
    const body = await resp.clone().arrayBuffer();
    if (body.byteLength !== f.size) { writeState(state); throw new Error(`size mismatch for ${f.path}`); }
    await cache.put(url, resp);
    if (f.sha256) state.sha[url] = f.sha256;
  }
  state.tasks[taskDir] = { version: manifest.version, urls };
  // Drop files no task refers to any more, only after this task's copy is complete.
  const keep = new Set(Object.values(state.tasks).flatMap((t) => t.urls));
  for (const req of await cache.keys()) {
    if (!keep.has(req.url)) { await cache.delete(req); delete state.sha[req.url]; }
  }
  writeState(state);
  return true;
}

/** True when the worker has the task route and a complete copy of this task exists. */
export async function webTaskReady(taskDir: string, base: string): Promise<boolean> {
  if (!readState().tasks[taskDir]) return false;
  try {
    const r = await fetch(`${base}t/__ping`, { cache: 'no-store' });
    return r.ok && r.headers.get('x-esmira-task-route') === '1';
  } catch { return false; }
}
