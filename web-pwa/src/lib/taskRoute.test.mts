import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { isTaskPath, TASK_ROOT } from './taskRoute.ts';

// The service worker keeps its own copy of isTaskPath (src/sw.ts imports nothing from the app).
// Pull that exact block out of the source and run it, so the copy is tested too.
const swSource = readFileSync(new URL('../sw.ts', import.meta.url), 'utf8');
const block = /\/\/ task-path-check:begin\n([\s\S]*?)\/\/ task-path-check:end/.exec(swSource);
assert.ok(block, 'sw.ts must keep the task-path-check block');
const js = block[1].replace('(pathname: string): boolean', '(pathname)');
const swIsTaskPath = new Function(`${js}\nreturn { isTaskPath, TASK_ROOT };`)() as { isTaskPath: (p: string) => boolean; TASK_ROOT: string };

const allowed = [
  '/webapp/m2c2/assessments/pvt-ba/index.html',
  '/webapp/novel-assessments/affective-slider/index.html',
  '/webapp/m2c2/assessments/@m2c2kit/assessment-color-shapes@0.8.33/index.js',
  '/webapp/m2c2/modules/@m2c2kit/core@0.3.34/dist/index.js',
  '/webapp/m2c2/assessments/prices/',
  '/webapp/a%20b/file.js',
  '/webapp/..hidden/file.js',
];
const refused = [
  '/', '/esmira/api/studies.php', '/esmira/admin.php', '/pwa/index.html', '/webapp', '/webapp/', '/webapps/x.js',
  '/webapp/../esmira/api/studies.php', '/webapp/a/../../esmira/x', '/webapp/%2e%2e/esmira/x', '/webapp/%2E%2E/esmira/x',
  '/webapp/a/%2e%2e/%2e%2e/x', '/webapp/a/./b.js', '/webapp/a%2f..%2f..%2fesmira', '/webapp/a%2Fb', '/webapp/a%5cb', '/webapp\\x',
  '/webapp/a//b.js', '//evil.example/webapp/x.js', '/webapp/%00x', '/webapp/%zz', '/WEBAPP/x.js',
  '/webapp//evil.example/x.js',
];

for (const [name, fn] of [['lib/taskRoute.ts', isTaskPath], ['sw.ts copy', swIsTaskPath.isTaskPath]] as const) {
  test(`${name}: ordinary task files are served`, () => {
    for (const p of allowed) assert.equal(fn(p), true, p);
  });
  test(`${name}: everything else is refused`, () => {
    for (const p of refused) assert.equal(fn(p), false, p);
  });
}

test('the worker and the app agree on the task root', () => {
  assert.equal(swIsTaskPath.TASK_ROOT, TASK_ROOT);
});

test('the worker refuses a path outside the task root before it fetches anything', () => {
  const route = swSource.slice(swSource.indexOf('registerRoute('), swSource.indexOf('// SPA navigation fallback'));
  assert.ok(route.indexOf('isTaskPath(taskPath)') < route.indexOf('fetch(target'), 'check must come before the fetch');
});
