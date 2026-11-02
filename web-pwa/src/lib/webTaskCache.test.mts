import { test } from 'node:test';
import assert from 'node:assert/strict';

const store = new Map<string, string>();
(globalThis as Record<string, unknown>).localStorage = {
  getItem: (k: string) => store.get(k) ?? null,
  setItem: (k: string, v: string) => void store.set(k, v),
  removeItem: (k: string) => void store.delete(k),
};

class FakeCache {
  entries = new Map<string, Response>();
  async put(url: string, r: Response) { this.entries.set(url, r); }
  async match(url: string) { return this.entries.get(url); }
  async keys() { return [...this.entries.keys()].map((url) => ({ url })); }
  async delete(req: { url: string }) { return this.entries.delete(req.url); }
}
const caches_ = new Map<string, FakeCache>();
(globalThis as Record<string, unknown>).caches = {
  open: async (n: string) => { if (!caches_.has(n)) caches_.set(n, new FakeCache()); return caches_.get(n)!; },
};
const shared = () => caches_.get('esmira-task-files')!;

const { proxyTaskUrl, downloadTaskWeb, webTaskReady, webCacheAvailable } = await import('./webTaskCache.ts');

const A = 'https://srv.example/webapp/m2c2/assessments/a/';
const B = 'https://srv.example/webapp/m2c2/assessments/b/';
const ROOT = 'https://srv.example/webapp/m2c2/';
const LIB = { path: 'modules/core@1/dist/i.js', size: 1, sha256: 'lib1' };
const manifest = (version: string, own: string, ownSha: string) => ({
  version, root: '../../', files: [{ path: `assessments/${own}/index.html`, size: 1, sha256: ownSha }, LIB],
});
const reset = () => { store.clear(); caches_.clear(); };

test('proxy path keeps the server path, query and hash under <base>t/', () => {
  assert.equal(proxyTaskUrl(`${A}index.html?embed=1&v=3`, '/pwa/'), '/pwa/t/webapp/m2c2/assessments/a/index.html?embed=1&v=3');
});

test('only same-origin tasks are eligible', () => {
  assert.equal(webCacheAvailable('https://srv.example', A), true);
  assert.equal(webCacheAvailable('https://other.example', A), false);
});

test('a library shared by two tasks is fetched and stored once', async () => {
  reset();
  const seen: string[] = [];
  globalThis.fetch = (async (u: string) => { seen.push(u); return new Response('x'); }) as typeof fetch;
  await downloadTaskWeb(A, manifest('a1', 'a', 'sa'), ROOT);
  await downloadTaskWeb(B, manifest('b1', 'b', 'sb'), ROOT);
  assert.equal(seen.filter((u) => u.endsWith('i.js')).length, 1);
  assert.equal(shared().entries.size, 3); // a/index.html, b/index.html, lib
});

test('unchanged version is skipped; a new version refetches only changed files and drops orphans', async () => {
  reset();
  const seen: string[] = [];
  globalThis.fetch = (async (u: string) => { seen.push(u); return new Response('x'); }) as typeof fetch;
  await downloadTaskWeb(A, manifest('a1', 'a', 'sa'), ROOT);
  await downloadTaskWeb(A, manifest('a1', 'a', 'sa'), ROOT);
  assert.equal(seen.length, 2);
  seen.length = 0;
  await downloadTaskWeb(A, manifest('a2', 'a', 'sa-new'), ROOT); // own file changed, lib did not
  assert.deepEqual(seen, [`${ROOT}assessments/a/index.html`]);
  // the task now lists a different file set: the old one is dropped once the new copy is complete
  await downloadTaskWeb(A, { version: 'a3', root: '../../', files: [{ path: 'assessments/a/other.html', size: 1, sha256: 'o' }] }, ROOT);
  assert.deepEqual([...shared().entries.keys()], [`${ROOT}assessments/a/other.html`]);
});

test('a failed or short download leaves the task unusable', async () => {
  reset();
  globalThis.fetch = (async () => new Response('too long')) as typeof fetch;
  await assert.rejects(() => downloadTaskWeb(A, manifest('a1', 'a', 'sa'), ROOT), /size mismatch/);
  globalThis.fetch = (async () => new Response('x', { status: 404 })) as typeof fetch;
  await assert.rejects(() => downloadTaskWeb(A, manifest('a1', 'a', 'sa'), ROOT), /404/);
  assert.equal(await webTaskReady(A, '/pwa/'), false);
});

test('ready only when a copy exists AND the worker answers the probe', async () => {
  reset();
  globalThis.fetch = (async () => new Response('x')) as typeof fetch;
  await downloadTaskWeb(A, manifest('a1', 'a', 'sa'), ROOT);
  globalThis.fetch = (async () => new Response('<html>app</html>')) as typeof fetch; // old worker: SPA fallback
  assert.equal(await webTaskReady(A, '/pwa/'), false);
  globalThis.fetch = (async () => new Response('ok', { headers: { 'x-esmira-task-route': '1' } })) as typeof fetch;
  assert.equal(await webTaskReady(A, '/pwa/'), true);
});
