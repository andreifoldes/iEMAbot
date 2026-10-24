import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  collectTaskUrls, taskDirUrl, localRootFor, validManifest, localTaskUrl, syncStudyTasks, resolveTaskUrl,
} from './taskCache.ts';

const store = new Map<string, string>();
(globalThis as Record<string, unknown>).localStorage = {
  getItem: (k: string) => store.get(k) ?? null,
  setItem: (k: string, v: string) => void store.set(k, v),
  removeItem: (k: string) => void store.delete(k),
};

const DIR = 'https://srv.example/webapp/m2c2/assessments/pvt-ba/';
const study = {
  questionnaires: [{
    pages: [{
      inputs: [
        { name: 'a', responseType: 'webapp', url: `${DIR}index.html?show_results=true` },
        { name: 'b', responseType: 'text_input', text: '<b>T</b><a href="/webapp/m2c2/assessments/pvt-ba/index.html">go</a>' },
        { name: 'c', responseType: 'webapp', url: 'javascript:alert(1)' },
        { name: 'd', responseType: 'likert' },
      ],
    }],
  }],
} as never;

test('collects each task directory once, absolute, http(s) only', () => {
  const urls = collectTaskUrls(study, 'https://srv.example/esmira/');
  assert.equal(urls.length, 2);
  assert.deepEqual([...new Set(urls.map(taskDirUrl))], [DIR]);
});

test('local layout keeps the tree so ../../modules imports resolve', () => {
  const r = localRootFor(DIR, { root: '../../' });
  assert.equal(r.localRoot, 'tasks/srv.example/webapp/m2c2/');
  assert.equal(r.taskPath, 'assessments/pvt-ba/');
  assert.equal(r.rootUrl, 'https://srv.example/webapp/m2c2/');
});

test('manifest validation rejects escapes and empties', () => {
  const ok = { version: 'v', root: '../../', files: [{ path: 'a/b.js', size: 1 }] };
  assert.equal(validManifest(ok), true);
  assert.equal(validManifest({ ...ok, files: [{ path: '../x', size: 1 }] }), false);
  assert.equal(validManifest({ ...ok, files: [{ path: '/etc/x', size: 1 }] }), false);
  assert.equal(validManifest({ ...ok, files: [] }), false);
  assert.equal(validManifest(null), false);
});

test('local task url keeps file, query and hash', () => {
  assert.equal(
    localTaskUrl(`${DIR}index.html?embed=1&v=3`, DIR, 'https://localhost/_capacitor_file_/data/tasks/x/assessments/pvt-ba/'),
    'https://localhost/_capacitor_file_/data/tasks/x/assessments/pvt-ba/index.html?embed=1&v=3',
  );
});

function fakeNative(opts: { manifest?: unknown; sizeOf?: (p: string) => number }) {
  const downloaded: string[] = [];
  const removed: string[] = [];
  (globalThis as Record<string, unknown>).Capacitor = {
    isNativePlatform: () => true,
    getPlatform: () => 'android',
    convertFileSrc: (u: string) => u.replace('file://', 'https://localhost/_capacitor_file_'),
    Plugins: {
      CapacitorHttp: { get: async () => ({ status: opts.manifest ? 200 : 404, data: opts.manifest }) },
      Filesystem: {
        downloadFile: async (o: { path: string }) => void downloaded.push(o.path),
        stat: async (o: { path: string }) => ({ size: opts.sizeOf ? opts.sizeOf(o.path) : 1 }),
        mkdir: async () => undefined,
        rmdir: async (o: { path: string }) => void removed.push(o.path),
        getUri: async (o: { path: string }) => ({ uri: `file:///data/${o.path}` }),
      },
    },
  };
  return { downloaded, removed };
}

const MANIFEST = {
  version: 'v1', root: '../../',
  files: [{ path: 'assessments/pvt-ba/index.html', size: 1 }, { path: 'modules/core@1.0.0/dist/i.js', size: 1 }],
};

test('no-op outside the native app', async () => {
  delete (globalThis as Record<string, unknown>).Capacitor;
  await syncStudyTasks(study, 'https://srv.example/esmira/');
  assert.equal(await resolveTaskUrl(`${DIR}index.html?x=1`), `${DIR}index.html?x=1`);
});

test('downloads a task, then serves the local copy; same version is not re-downloaded', async () => {
  store.clear();
  const n = fakeNative({ manifest: MANIFEST });
  await syncStudyTasks(study, 'https://srv.example/esmira/');
  assert.deepEqual(n.downloaded, [
    'tasks/srv.example/webapp/m2c2/assessments/pvt-ba/index.html',
    'tasks/srv.example/webapp/m2c2/modules/core@1.0.0/dist/i.js',
  ]);
  assert.equal(
    await resolveTaskUrl(`${DIR}index.html?embed=1`),
    'https://localhost/_capacitor_file_/data/tasks/srv.example/webapp/m2c2/assessments/pvt-ba/index.html?embed=1',
  );
  await syncStudyTasks(study, 'https://srv.example/esmira/');
  assert.equal(n.downloaded.length, 2);
});

test('a failed or short download leaves no usable copy (falls back to the network URL)', async () => {
  store.clear();
  fakeNative({ manifest: MANIFEST, sizeOf: () => 99 });
  await syncStudyTasks(study, 'https://srv.example/esmira/');
  assert.equal(await resolveTaskUrl(`${DIR}index.html`), `${DIR}index.html`);
});

test('no manifest published: task stays online-only', async () => {
  store.clear();
  const n = fakeNative({});
  await syncStudyTasks(study, 'https://srv.example/esmira/');
  assert.equal(n.downloaded.length, 0);
  assert.equal(await resolveTaskUrl(`${DIR}index.html`), `${DIR}index.html`);
});

test('a bare API root (browser PWA: "/esmira/") still finds absolute task URLs', async () => {
  store.clear();
  (globalThis as Record<string, unknown>).location = { href: 'https://srv.example/pwa/', origin: 'https://srv.example' };
  const n = fakeNative({ manifest: MANIFEST });
  await syncStudyTasks(study, '/esmira/');
  assert.equal(n.downloaded.length, 2);
  delete (globalThis as Record<string, unknown>).location;
});

test('tasks sharing a folder never clear it, and a shared file is downloaded once', async () => {
  store.clear();
  const dirB = 'https://srv.example/webapp/m2c2/assessments/other/';
  const two = {
    questionnaires: [{ pages: [{ inputs: [
      { name: 'a', responseType: 'webapp', url: `${DIR}index.html` },
      { name: 'b', responseType: 'webapp', url: `${dirB}index.html` },
    ] }] }],
  } as never;
  const shaManifest = (own: string) => ({ version: own, root: '../../', files: [
    { path: `assessments/${own}/index.html`, size: 1, sha256: `s-${own}` },
    { path: 'modules/core@1/dist/i.js', size: 1, sha256: 'lib' },
  ] });
  const n = fakeNative({});
  (globalThis as Record<string, unknown>).Capacitor = {
    ...((globalThis as Record<string, unknown>).Capacitor as object),
    Plugins: {
      ...(((globalThis as Record<string, unknown>).Capacitor as { Plugins: object }).Plugins),
      CapacitorHttp: { get: async ({ url }: { url: string }) => ({ status: 200, data: shaManifest(url.includes('/other/') ? 'other' : 'pvt-ba') }) },
    },
  };
  await syncStudyTasks(two, 'https://srv.example/esmira/');
  assert.equal(n.removed.length, 0);
  assert.equal(n.downloaded.filter((p) => p.endsWith('i.js')).length, 1);
});
