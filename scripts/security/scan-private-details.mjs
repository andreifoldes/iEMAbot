#!/usr/bin/env node
/**
 * Private-detail scanner for a public repository and its built docs.
 *
 * Modes (combine freely):
 *   --tracked         scan every git-tracked file (default if no mode is given)
 *   --paths           flag git-tracked paths that must never be committed
 *   --dir <path>      scan a directory (e.g. website/build), tracked or not
 *   --history         scan lines ADDED in any commit of any ref
 *
 * Rules come from private-patterns.json (regex classes only). Optional literals
 * can be supplied through the PRIVATE_TERMS environment variable (one per line,
 * from a repository secret): they are matched case-insensitively and are NEVER
 * echoed back, so the CI log cannot leak them.
 *
 * Accepted findings live in allowlist.json as {rule, path, reason}.
 * Exit code: 0 clean, 1 findings, 2 usage/config error.
 */
import {execFileSync, spawn} from 'node:child_process';
import {readFileSync, readdirSync, statSync, existsSync} from 'node:fs';
import {dirname, join, relative, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {createInterface} from 'node:readline';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = execFileSync('git', ['rev-parse', '--show-toplevel'], {cwd: here, encoding: 'utf8'}).trim();
const MAX_BYTES = 2 * 1024 * 1024;

function compile(src, extraFlags = '') {
  let flags = 'g' + extraFlags;
  let body = src;
  if (body.startsWith('(?i)')) {
    body = body.slice(4);
    flags += 'i';
  }
  return new RegExp(body, flags);
}

const config = JSON.parse(readFileSync(join(here, 'private-patterns.json'), 'utf8'));
const allow = JSON.parse(readFileSync(join(here, 'allowlist.json'), 'utf8')).entries.map((e) => {
  if (!e.rule || !e.path || !e.reason) {
    console.error('allowlist.json: every entry needs rule, path and reason');
    process.exit(2);
  }
  return {rule: e.rule, path: new RegExp(e.path), pending: e.pending === true, reason: e.reason, hits: 0};
});
const contentRules = config.contentRules.map((r) => ({...r, re: compile(r.regex)}));
const pathRules = config.forbiddenPathPatterns.map((r) => ({
  ...r,
  re: compile(r.regex).source ? new RegExp(r.regex) : null,
  only: r.onlyUnder ? new RegExp(r.onlyUnder) : null,
}));
const skipPaths = config.skipPathPatterns.map((p) => new RegExp(p));
const terms = (process.env.PRIVATE_TERMS ?? '')
  .split('\n')
  .map((t) => t.trim())
  .filter((t) => t.length >= 3);
const termRules = terms.map((t, i) => ({
  id: `private-term-${i + 1}`,
  description: 'Literal from the PRIVATE_TERMS secret (value withheld)',
  re: new RegExp(t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'),
  secret: true,
}));

const findings = [];
let suppressed = 0;

function isAllowed(rule, path) {
  const hit = allow.find((a) => a.rule === rule && a.path.test(path));
  if (hit) hit.hits++;
  return Boolean(hit);
}

function mask(_text, _rule, m) {
  // Never print the matched value or any surrounding text: only its length.
  return `[REDACTED:${m[0].length} chars]`;
}

function scanText(path, text, where) {
  const lines = text.split('\n');
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    if (line.length > 4000) continue; // minified blobs
    for (const rule of [...contentRules, ...termRules]) {
      rule.re.lastIndex = 0;
      const m = rule.re.exec(line);
      if (!m) continue;
      if (isAllowed(rule.id, path)) {
        suppressed++;
        continue;
      }
      findings.push({rule: rule.id, description: rule.description, path, line: i + 1, where, snippet: mask(line, rule, m)});
    }
  }
}

function isBinary(buf) {
  return buf.subarray(0, Math.min(buf.length, 8000)).includes(0);
}

function scanFile(abs, rel, where) {
  if (skipPaths.some((re) => re.test(rel))) return;
  let st;
  try {
    st = statSync(abs);
  } catch {
    return;
  }
  if (!st.isFile() || st.size > MAX_BYTES) return;
  const buf = readFileSync(abs);
  if (isBinary(buf)) return;
  scanText(rel, buf.toString('utf8'), where);
}

function trackedFiles() {
  const out = execFileSync('git', ['ls-files', '-z'], {cwd: repoRoot, maxBuffer: 256 * 1024 * 1024});
  return out.toString('utf8').split('\0').filter(Boolean);
}

function modeTracked() {
  for (const rel of trackedFiles()) {
    if (!existsSync(join(repoRoot, rel))) continue; // deleted in working tree
    scanFile(join(repoRoot, rel), rel, 'tracked');
  }
}

function modePaths() {
  for (const rel of trackedFiles()) {
    for (const rule of pathRules) {
      if (!rule.re.test(rel)) continue;
      if (rule.only && !rule.only.test(rel)) continue;
      if (isAllowed(rule.id, rel)) {
        suppressed++;
        continue;
      }
      findings.push({rule: rule.id, description: rule.description, path: rel, line: 0, where: 'tracked-path', snippet: '(path)'});
    }
  }
}

function walk(dir, acc = []) {
  for (const name of readdirSync(dir)) {
    const abs = join(dir, name);
    const st = statSync(abs);
    if (st.isDirectory()) walk(abs, acc);
    else acc.push(abs);
  }
  return acc;
}

function modeDir(dir) {
  const abs = resolve(dir);
  if (!existsSync(abs)) {
    console.error(`--dir: ${dir} does not exist (build it first)`);
    process.exit(2);
  }
  for (const f of walk(abs)) scanFile(f, relative(repoRoot, f), `dir:${dir}`);
}

async function modeHistory() {
  const git = spawn('git', ['log', '--all', '-p', '-U0', '--no-color', '--no-ext-diff', '--format=@@commit %H'], {cwd: repoRoot});
  const rl = createInterface({input: git.stdout, crlfDelay: Infinity});
  let commit = '';
  let file = '';
  let skip = false;
  const seen = new Set();
  for await (const line of rl) {
    if (line.startsWith('@@commit ')) {
      commit = line.slice(9, 16);
      continue;
    }
    if (line.startsWith('+++ ')) {
      file = line.startsWith('+++ b/') ? line.slice(6) : '';
      skip = !file || skipPaths.some((re) => re.test(file));
      continue;
    }
    if (skip || !line.startsWith('+') || line.length > 4000) continue;
    const added = line.slice(1);
    for (const rule of [...contentRules, ...termRules]) {
      rule.re.lastIndex = 0;
      const m = rule.re.exec(added);
      if (!m) continue;
      if (isAllowed(rule.id, file)) {
        suppressed++;
        continue;
      }
      const key = `${rule.id}|${file}|${commit}`;
      if (seen.has(key)) continue;
      seen.add(key);
      findings.push({rule: rule.id, description: rule.description, path: file, line: 0, where: `history@${commit}`, snippet: mask(added, rule, m)});
    }
  }
  await new Promise((res) => git.on('close', res));
}

const args = process.argv.slice(2);
const modes = new Set();
const dirs = [];
for (let i = 0; i < args.length; i++) {
  if (args[i] === '--dir') dirs.push(args[++i]);
  else if (['--tracked', '--paths', '--history'].includes(args[i])) modes.add(args[i]);
  else {
    console.error(`unknown argument: ${args[i]}`);
    process.exit(2);
  }
}
if (modes.size === 0 && dirs.length === 0) modes.add('--tracked');

if (modes.has('--tracked')) modeTracked();
if (modes.has('--paths')) modePaths();
for (const d of dirs) modeDir(d);
if (modes.has('--history')) await modeHistory();

if (terms.length) console.log(`PRIVATE_TERMS: ${terms.length} literal(s) loaded (values withheld)`);

// Pending entries are known disclosures awaiting a human decision: never silent.
const pendingHits = allow.filter((a) => a.pending && a.hits > 0);
for (const a of pendingHits) {
  const line = `PENDING [${a.rule}] ${a.path.source}: ${a.reason} (${a.hits} match(es))`;
  console.log(process.env.GITHUB_ACTIONS ? `::warning title=Pending private-detail disclosure::${line}` : `WARN ${line}`);
}

if (findings.length === 0) {
  console.log(`OK: no new private details (${suppressed} allowlisted match(es); ${pendingHits.length} pending disclosure(s) still need a decision)`);
  process.exit(0);
}

findings.sort((a, b) => a.rule.localeCompare(b.rule) || a.path.localeCompare(b.path) || a.line - b.line);
const byRule = new Map();
for (const f of findings) byRule.set(f.rule, (byRule.get(f.rule) ?? 0) + 1);
for (const f of findings) {
  const loc = f.line ? `${f.path}:${f.line}` : f.path;
  const msg = `[${f.rule}] ${loc} (${f.where}) ${f.snippet}`;
  // GitHub Actions annotation when running in CI (path-based, value already redacted)
  if (process.env.GITHUB_ACTIONS && f.line) console.log(`::error file=${f.path},line=${f.line},title=${f.rule}::${f.description}`);
  else console.log(msg);
  if (process.env.GITHUB_ACTIONS && f.line) console.log(msg);
}
console.log(`\nFAIL: ${findings.length} finding(s) (${suppressed} allowlisted suppressed)`);
for (const [r, n] of byRule) console.log(`  ${r}: ${n}`);
process.exit(1);
