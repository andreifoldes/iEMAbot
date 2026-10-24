#!/usr/bin/env node
/**
 * Writes `task-manifest.json` into every task directory under <webroot>/assessments/* so the
 * Android app can download a task once and run it offline.
 *
 *   node tools/cognitive-tasks/build-task-manifest.mjs <webroot> [task-dir ...]
 *
 * <webroot> is the directory a task's relative imports resolve inside (the folder holding both
 * `assessments/` and `modules/`, e.g. the host's /webapp/m2c2). Each manifest lists the task's own
 * files plus every module folder its import map points at, as paths relative to <webroot>:
 *   { version, root: "../../", files: [{ path, size, sha256 }] }
 * `root` is the URL from the task directory back to <webroot>. `version` is a hash of all entries,
 * so the app re-downloads exactly when something changed. Re-run it whenever a task is deployed.
 */
import { createHash } from 'node:crypto';
import { readdirSync, readFileSync, statSync, writeFileSync, existsSync } from 'node:fs';
import { join, relative, posix, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const MANIFEST = 'task-manifest.json';

function walk(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) out.push(...walk(p));
    else if (name !== MANIFEST) out.push(p);
  }
  return out;
}

/** Folders (relative to webroot, posix) that the task's import map points into, one per package version. */
export function moduleDirs(indexHtml, taskRel) {
  const m = indexHtml.match(/<script[^>]*type="importmap"[^>]*>([\s\S]*?)<\/script>/i);
  if (!m) return [];
  let imports;
  try { imports = JSON.parse(m[1]).imports ?? {}; } catch { return []; }
  const dirs = new Set();
  for (const target of Object.values(imports)) {
    if (typeof target !== 'string' || /^[a-z]+:/i.test(target)) continue; // skip absolute URLs
    const resolved = posix.normalize(posix.join(taskRel, target)); // modules/@m2c2kit/core@0.3.35/dist/index.min.js
    const parts = resolved.split('/');
    const at = parts.findIndex((s) => /@[\d.]+$/.test(s));
    if (at > 0) dirs.add(parts.slice(0, at + 1).join('/'));
  }
  return [...dirs];
}

export function buildManifest(webroot, taskRel) {
  const indexPath = join(webroot, taskRel, 'index.html');
  const html = existsSync(indexPath) ? readFileSync(indexPath, 'utf8') : '';
  const roots = [taskRel, ...moduleDirs(html, taskRel)];
  const seen = new Set();
  const files = [];
  for (const r of roots) {
    const abs = join(webroot, r);
    if (!existsSync(abs)) continue;
    for (const f of walk(abs)) {
      const path = relative(webroot, f).split(sep).join('/');
      if (seen.has(path)) continue;
      seen.add(path);
      const buf = readFileSync(f);
      files.push({ path, size: buf.length, sha256: createHash('sha256').update(buf).digest('hex') });
    }
  }
  files.sort((a, b) => (a.path < b.path ? -1 : 1));
  const version = createHash('sha256').update(files.map((f) => `${f.path}:${f.sha256}`).join('\n')).digest('hex').slice(0, 16);
  return { version, root: '../'.repeat(taskRel.split('/').length), files };
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const [webroot, ...tasks] = process.argv.slice(2);
  if (!webroot) { console.error('usage: build-task-manifest.mjs <webroot> [task-dir ...]'); process.exit(2); }
  const list = tasks.length
    ? tasks
    : readdirSync(join(webroot, 'assessments')).map((n) => `assessments/${n}`).filter((r) => statSync(join(webroot, r)).isDirectory());
  for (const taskRel of list) {
    const manifest = buildManifest(webroot, taskRel);
    writeFileSync(join(webroot, taskRel, MANIFEST), JSON.stringify(manifest));
    console.log(`${taskRel}: ${manifest.files.length} files, version ${manifest.version}`);
  }
}
