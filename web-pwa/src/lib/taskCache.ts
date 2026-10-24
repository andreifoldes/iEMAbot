/**
 * Offline copies of a study's interactive web tasks (the `webapp` items, e.g. m2c2 assessments).
 *
 * Native app only. After sign-up the app downloads each task into its private storage and, when a
 * task is opened, points the iframe at the local copy (served same-origin by Capacitor under
 * https://localhost/_capacitor_file_/…), so it runs without a connection. Everything is a no-op in
 * the browser PWA, and a task without a copy simply loads from the network as before.
 *
 * The file list comes from a `task-manifest.json` next to each task (written by
 * tools/cognitive-tasks/build-task-manifest.mjs on the host): { version, root, files:[{path,size}] },
 * with `root` the URL from the task directory back to the folder its relative imports resolve in.
 * Plugins are reached through the injected `Capacitor` global (as in nativeReminders.ts).
 */

import { isNativeApp } from './platform.ts';
import { extractCognitive } from './esmiraAdapter.ts';
import type { EsmiraStudy } from '../types.ts';
import { bundledManifestFor } from './bundledManifests.ts';
import { downloadTaskWeb, proxyTaskUrl, webCacheAvailable, webTaskReady } from './webTaskCache.ts';

const INDEX_KEY = 'esmira_task_cache';
const BASE_DIR = 'tasks';
const FILES_KEY = 'esmira_task_files'; // device path -> sha256 of the copy we downloaded

export interface TaskManifest {
  version: string;
  root: string;
  files: { path: string; size: number; sha256?: string }[];
}

export interface CachedTask {
  version: string;
  /** Directory (relative to app Data) holding the manifest root, e.g. tasks/host/webapp/m2c2/ */
  localRoot: string;
  /** Path of the task directory below localRoot, e.g. assessments/pvt-ba/ */
  taskPath: string;
}

type Index = Record<string, CachedTask>;

// ── Pure helpers ───────────────────────────────────────────────────────────────

/** Directory URL (with trailing slash) of a task page. */
export function taskDirUrl(taskUrl: string): string {
  const u = new URL(taskUrl);
  u.search = '';
  u.hash = '';
  u.pathname = u.pathname.replace(/[^/]*$/, '');
  return u.toString();
}

/** Every distinct task page URL a study launches, made absolute against `base`. */
export function collectTaskUrls(study: Pick<EsmiraStudy, 'questionnaires'>, base: string): string[] {
  const out = new Set<string>();
  for (const q of study.questionnaires ?? []) {
    for (const page of q.pages ?? []) {
      for (const input of page.inputs ?? []) {
        let url = '';
        if (input.responseType === 'webapp') url = input.url ?? '';
        else url = extractCognitive(input.text ?? '')?.url ?? '';
        if (!url) continue;
        try {
          const abs = new URL(url, base);
          if (abs.protocol === 'https:' || abs.protocol === 'http:') out.add(abs.toString());
        } catch { /* ignore a malformed URL */ }
      }
    }
  }
  return [...out];
}

/** Where a manifest's files live on the device: tasks/<host>/<path of the manifest root>/. */
export function localRootFor(taskDir: string, manifest: Pick<TaskManifest, 'root'>): { localRoot: string; taskPath: string; rootUrl: string } {
  const rootUrl = new URL(manifest.root, taskDir);
  const dir = new URL(taskDir);
  const taskPath = dir.pathname.slice(rootUrl.pathname.length);
  const host = `${rootUrl.hostname}${rootUrl.port ? `_${rootUrl.port}` : ''}`;
  return { localRoot: `${BASE_DIR}/${host}${rootUrl.pathname}`, taskPath, rootUrl: rootUrl.toString() };
}

/** Reject manifests that are malformed or try to escape their root. */
export function validManifest(m: unknown): m is TaskManifest {
  if (!m || typeof m !== 'object') return false;
  const x = m as TaskManifest;
  if (typeof x.version !== 'string' || !x.version || typeof x.root !== 'string' || !Array.isArray(x.files) || !x.files.length) return false;
  return x.files.every((f) => f && typeof f.path === 'string' && f.path && !f.path.startsWith('/') && !f.path.split('/').includes('..') && typeof f.size === 'number');
}

/** Swap a task URL's origin+directory for the local copy's, keeping file name, query and hash. */
export function localTaskUrl(taskUrl: string, taskDir: string, localDirUrl: string): string {
  const u = new URL(taskUrl);
  const dir = new URL(taskDir);
  const rest = u.pathname.slice(dir.pathname.length);
  return `${localDirUrl}${rest}${u.search}${u.hash}`;
}

// ── Native glue ────────────────────────────────────────────────────────────────

interface FilesystemPlugin {
  downloadFile(o: { url: string; path: string; directory: string; recursive: boolean }): Promise<unknown>;
  getUri(o: { path: string; directory: string }): Promise<{ uri: string }>;
  stat(o: { path: string; directory: string }): Promise<{ size: number }>;
  mkdir(o: { path: string; directory: string; recursive: boolean }): Promise<void>;
  rmdir(o: { path: string; directory: string; recursive: boolean }): Promise<void>;
}
interface HttpPlugin {
  get(o: { url: string; headers?: Record<string, string> }): Promise<{ status: number; data: unknown }>;
}
type CapGlobal = {
  Plugins?: { Filesystem?: FilesystemPlugin; CapacitorHttp?: HttpPlugin };
  convertFileSrc?: (uri: string) => string;
};

function cap(): CapGlobal | null {
  return isNativeApp() ? (globalThis as { Capacitor?: CapGlobal }).Capacitor ?? null : null;
}

function readIndex(): Index {
  try { return JSON.parse(localStorage.getItem(INDEX_KEY) || '{}') as Index; } catch { return {}; }
}
function readFileShas(): Record<string, string> {
  try { return JSON.parse(localStorage.getItem(FILES_KEY) || '{}') as Record<string, string>; } catch { return {}; }
}
function writeFileShas(m: Record<string, string>): void {
  try { localStorage.setItem(FILES_KEY, JSON.stringify(m)); } catch { /* re-download next time */ }
}
function writeIndex(i: Index): void {
  try { localStorage.setItem(INDEX_KEY, JSON.stringify(i)); } catch { /* storage unavailable: re-download next time */ }
}

async function fetchManifest(http: HttpPlugin | null, taskDir: string): Promise<TaskManifest | null> {
  // A manifest published next to the task wins (it is regenerated whenever the task is deployed);
  // otherwise the one bundled with the app, so no server-side step is required.
  return (await fetchPublishedManifest(http, taskDir)) ?? bundledManifestFor(taskDir);
}

async function fetchPublishedManifest(http: HttpPlugin | null, taskDir: string): Promise<TaskManifest | null> {
  try {
    const url = `${taskDir}task-manifest.json`;
    // Native: the plugin's own HTTP stack (no CORS). Browser: a plain same-origin fetch.
    const r = http
      ? await http.get({ url })
      : await fetch(url, { cache: 'no-cache' }).then(async (x) => ({ status: x.status, data: x.ok ? await x.json() : null }));
    const data = typeof r.data === 'string' ? JSON.parse(r.data) : r.data;
    return r.status === 200 && validManifest(data) ? data : null;
  } catch { return null; }
}

/** Download one task (all files, then verify sizes). Marks it usable only when everything arrived. */
async function downloadTask(c: CapGlobal, taskDir: string): Promise<void> {
  const fs = c.Plugins?.Filesystem;
  const http = c.Plugins?.CapacitorHttp;
  if (!fs || !http) return;
  const index = readIndex();
  const manifest = await fetchManifest(http, taskDir);
  if (!manifest) return; // no manifest published: stays online-only
  if (index[taskDir]?.version === manifest.version) return;

  const { localRoot, taskPath, rootUrl } = localRootFor(taskDir, manifest);
  delete index[taskDir]; // never serve a half-written copy
  writeIndex(index);
  // Tasks of one folder share a root (and library modules), so never clear it: files are replaced one by one.
  const known = readFileShas();

  try {
    for (const f of manifest.files) {
      const path = `${localRoot}${f.path}`;
      if (f.sha256 && known[path] === f.sha256) {
        // Already downloaded for another task (shared library) or an earlier version: reuse it if intact.
        try { if ((await fs.stat({ path, directory: 'DATA' })).size === f.size) continue; } catch { /* missing: fetch it */ }
      }
      // downloadFile's own `recursive` flag does not create parent folders on Android, so make them first.
      try { await fs.mkdir({ path: path.replace(/\/[^/]*$/, ''), directory: 'DATA', recursive: true }); } catch { /* already there */ }
      delete known[path];
      await fs.downloadFile({ url: new URL(f.path, rootUrl).toString(), path, directory: 'DATA', recursive: true });
      const { size } = await fs.stat({ path, directory: 'DATA' });
      if (size !== f.size) throw new Error(`size mismatch for ${f.path}`);
      if (f.sha256) known[path] = f.sha256;
    }
  } finally {
    writeFileShas(known); // keep what did arrive, so a retry only fetches the rest
  }
  const done = readIndex();
  done[taskDir] = { version: manifest.version, localRoot, taskPath };
  writeIndex(done);
}

/** Browser PWA: the same task, cached through the Cache API and the service worker's task route. */
async function downloadTaskInBrowser(taskDir: string): Promise<void> {
  if (!webCacheAvailable(location.origin, taskDir)) return;
  const manifest = await fetchManifest(null, taskDir);
  if (!manifest) return;
  await downloadTaskWeb(taskDir, manifest, new URL(manifest.root, taskDir).toString());
}

/** Fetch offline copies of every task the study uses. Safe to call repeatedly; failures are silent. */
export async function syncStudyTasks(study: Pick<EsmiraStudy, 'questionnaires'>, serverBase: string): Promise<void> {
  const c = cap();
  // The browser PWA's API root is a bare path (/esmira/); URL() needs an absolute base.
  let base = serverBase;
  try { base = new URL(serverBase, globalThis.location?.href).toString(); } catch { /* no page URL to resolve against */ }
  const dirs = new Set(collectTaskUrls(study, base).map(taskDirUrl));
  for (const dir of dirs) {
    try { await (c ? downloadTask(c, dir) : downloadTaskInBrowser(dir)); } catch (err) { console.warn('[tasks] offline copy not ready:', dir, err); }
  }
}

/** The URL to put in the task iframe: the local copy when complete, otherwise the original. */
export async function resolveTaskUrl(taskUrl: string, base = browserBase()): Promise<string> {
  const c = cap();
  if (!c) {
    try {
      if (!webCacheAvailable(location.origin, taskUrl)) return taskUrl;
      return (await webTaskReady(taskDirUrl(taskUrl), base)) ? proxyTaskUrl(taskUrl, base) : taskUrl;
    } catch { return taskUrl; }
  }
  if (!c.Plugins?.Filesystem || !c.convertFileSrc) return taskUrl;
  try {
    const dir = taskDirUrl(taskUrl);
    const hit = readIndex()[dir];
    if (!hit) return taskUrl;
    const { uri } = await c.Plugins.Filesystem.getUri({ path: `${hit.localRoot}${hit.taskPath}`, directory: 'DATA' });
    const local = c.convertFileSrc(uri.endsWith('/') ? uri : `${uri}/`);
    return localTaskUrl(taskUrl, dir, local);
  } catch { return taskUrl; }
}

function browserBase(): string {
  const env = (import.meta as { env?: Record<string, string | undefined> }).env;
  return env?.BASE_URL || '/pwa/';
}
