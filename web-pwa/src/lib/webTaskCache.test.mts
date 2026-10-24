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
}
const caches_ = new Map<string, FakeCache>();
(globalThis as Record<string, unknown>).caches = {
  open: async (n: string) => { if (!caches_.has(n)) caches_.set(n, new FakeCache()); return caches_.get(n)!; },
  delete: async (n: string) => caches_.delete(n),
  keys: async () => [...caches_.keys()],
};

const { proxyTaskUrl, cacheNameFor, downloadTaskWeb, webTaskReady, webCacheAvailable } = await import('./webTaskCache.ts');

const DIR = 'https://srv.example/webapp/m2c2/assessments/pvt-ba/';
const ROOT = 'https://srv.example/webapp/m2c2/';
const manifest = (version: string, body = 'x') => ({ version, root: '../../', files: [{ path: 'assessments/pvt-ba/index.html', size: body.length }, { path: 'modules/c@1/dist/i.js', size: body.length }] });

test('proxy path keeps the server path, query and hash under <base>t/', () => {
  assert.equal(proxyTaskUrl(`${DIR}index.html?embed=1&v=3`, '/pwa/'), '/pwa/t/webapp/m2c2/assessments/pvt-ba/index.html?embed=1&v=3');
});

test('only same-origin tasks are eligible', () => {
  assert.equal(webCacheAvailable('https://srv.example', DIR), true);
  assert.equal(webCacheAvailable('https://other.example', DIR), false);
});

test('downloads every file, records the version, drops older copies, skips an unchanged one', async () => {
  store.clear(); caches_.clear();
  const seen: string[] = [];
  globalThis.fetch = (async (u: string) => { seen.push(u); return new Response('x'); }) as typeof fetch;
  await downloadTaskWeb(DIR, manifest('v1'), ROOT);
  assert.deepEqual(seen, [`${ROOT}assessments/pvt-ba/index.html`, `${ROOT}modules/c@1/dist/i.js`]);
  assert.deepEqual([...caches_.keys()], [cacheNameFor(DIR, 'v1')]);

  await downloadTaskWeb(DIR, manifest('v1'), ROOT);
  assert.equal(seen.length, 2); // unchanged: nothing re-fetched

  await downloadTaskWeb(DIR, manifest('v2'), ROOT);
  assert.deepEqual([...caches_.keys()], [cacheNameFor(DIR, 'v2')]); // v1 dropped only after v2 complete
});

test('a failed or short download leaves nothing usable', async () => {
  store.clear(); caches_.clear();
  globalThis.fetch = (async () => new Response('too long')) as typeof fetch;
  await assert.rejects(() => downloadTaskWeb(DIR, manifest('v1'), ROOT), /size mismatch/);
  assert.equal(caches_.size, 0);
  globalThis.fetch = (async () => new Response('x', { status: 404 })) as typeof fetch;
  await assert.rejects(() => downloadTaskWeb(DIR, manifest('v1'), ROOT), /404/);
  assert.equal(caches_.size, 0);
  assert.equal(await webTaskReady(DIR, '/pwa/'), false);
});

test('ready only when a copy exists AND the worker answers the probe', async () => {
  store.clear(); caches_.clear();
  globalThis.fetch = (async () => new Response('x')) as typeof fetch;
  await downloadTaskWeb(DIR, manifest('v1'), ROOT);
  globalThis.fetch = (async () => new Response('<html>app</html>')) as typeof fetch; // old worker: SPA fallback
  assert.equal(await webTaskReady(DIR, '/pwa/'), false);
  globalThis.fetch = (async () => new Response('ok', { headers: { 'x-esmira-task-route': '1' } })) as typeof fetch;
  assert.equal(await webTaskReady(DIR, '/pwa/'), true);
});
