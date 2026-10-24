import { test } from 'node:test';
import assert from 'node:assert/strict';

const store = new Map<string, string>();
(globalThis as Record<string, unknown>).localStorage = {
  getItem: (k: string) => store.get(k) ?? null,
  setItem: (k: string, v: string) => void store.set(k, v),
  removeItem: (k: string) => void store.delete(k),
};

const { fetchStudy } = await import('./studyFetch.ts');

const env = { success: true, serverVersion: 12, dataset: [{ id: 7, title: 'S', questionnaires: [] }] };
const ok = () => new Response(JSON.stringify(env), { status: 200 });

test('a good response is returned and kept for offline use', async () => {
  store.clear();
  globalThis.fetch = (async () => ok()) as typeof fetch;
  assert.equal((await fetchStudy('k')).study.id, 7);
  assert.equal([...store.keys()].some((k) => k.startsWith('esmira_study_env:')), true);
});

test('offline: the last good copy is served', async () => {
  globalThis.fetch = (async () => { throw new TypeError('Failed to fetch'); }) as typeof fetch;
  assert.equal((await fetchStudy('k')).study.id, 7);
});

test('server error: the last good copy is served', async () => {
  globalThis.fetch = (async () => new Response('x', { status: 503 })) as typeof fetch;
  assert.equal((await fetchStudy('k')).study.id, 7);
});

test('offline with no copy: the network error surfaces', async () => {
  store.clear();
  globalThis.fetch = (async () => { throw new TypeError('Failed to fetch'); }) as typeof fetch;
  await assert.rejects(() => fetchStudy('k'), /Failed to fetch/);
});

test('a rejected key is never answered from the cache', async () => {
  globalThis.fetch = (async () => ok()) as typeof fetch;
  await fetchStudy('k'); // cache it
  globalThis.fetch = (async () => new Response(JSON.stringify({ success: false, error: 'Study not found' }), { status: 200 })) as typeof fetch;
  await assert.rejects(() => fetchStudy('k'), /Study not found/);
  globalThis.fetch = (async () => new Response('no', { status: 404 })) as typeof fetch;
  await assert.rejects(() => fetchStudy('k'), /404/);
});
