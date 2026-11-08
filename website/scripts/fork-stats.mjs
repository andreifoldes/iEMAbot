#!/usr/bin/env node
// Computes the numbers on the "Fork divergence" page from git, so they are never typed in by hand.
//
// Runs before `docusaurus build|start` and `tsc` (see the pre* scripts in package.json) and writes
// website/src/generated/fork-stats.json (git-ignored), which src/components/ForkStats.tsx renders.
//
// What it needs: the full git history of this repository (CI checks out with fetch-depth: 0) and, for the
// upstream figures, network access to upstream ESMira-web. When something is missing it records `null` for
// that figure and carries on: the page then says "unavailable" instead of showing a stale number, and the
// build never fails because of this script.

import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const OUT = resolve(ROOT, 'website', 'src', 'generated', 'fork-stats.json');

// The last upstream ESMira-web commit the fork shares (upstream release 3.6.2). Fixed: it is the fork point.
const BASE = '4c23717ff913a1a743214935b07e42b8072938ed';
const UPSTREAM_URL = process.env.UPSTREAM_URL || 'https://github.com/KL-Psychological-Methodology/ESMira-web.git';
const UPSTREAM_BRANCH = process.env.UPSTREAM_BRANCH || 'main';
// Lock files change by thousands of lines on any dependency bump and say nothing about the work.
const SIZE_PATHSPEC = ['--', '.', ':(exclude)*package-lock.json'];

function git(args) {
  return execFileSync('git', args, { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}

/** Runs fn; on any error logs a short warning and returns null (so one missing figure never stops the rest). */
function attempt(what, fn) {
  try {
    return fn();
  } catch (e) {
    const msg = String(e?.stderr || e?.message || e).split('\n')[0].slice(0, 140);
    console.warn(`[fork-stats] ${what}: unavailable (${msg})`);
    return null;
  }
}

/** Parses `git diff --shortstat` output ("597 files changed, 63859 insertions(+), 3549 deletions(-)"). */
export function parseShortstat(text) {
  const m = (re) => Number((text.match(re) || [])[1] || 0);
  return {
    filesChanged: m(/(\d+) files? changed/),
    linesAdded: m(/(\d+) insertions?\(\+\)/),
    linesRemoved: m(/(\d+) deletions?\(-\)/),
  };
}

function versionAt(rev) {
  return JSON.parse(git(['show', `${rev}:package.json`])).version;
}

function compute() {
  const forkVersion = JSON.parse(readFileSync(resolve(ROOT, 'package.json'), 'utf8')).version;

  const base = attempt('fork base', () => ({
    version: versionAt(BASE),
    date: git(['log', '-1', '--format=%ad', '--date=short', BASE]),
  }));

  const size = attempt('size of the change', () => parseShortstat(git(['diff', '--shortstat', BASE, 'HEAD', ...SIZE_PATHSPEC])));

  const upstream = attempt('upstream', () => {
    // --filter=blob:none: only commits and trees are needed to count commits and changed files.
    git(['fetch', '--no-tags', '--quiet', '--filter=blob:none', UPSTREAM_URL, UPSTREAM_BRANCH]);
    return {
      version: versionAt('FETCH_HEAD'),
      date: git(['log', '-1', '--format=%ad', '--date=short', 'FETCH_HEAD']),
      commitsAhead: Number(git(['rev-list', '--count', `${BASE}..FETCH_HEAD`])),
      filesChanged: git(['diff', '--name-only', BASE, 'FETCH_HEAD']).split('\n').filter(Boolean).length,
    };
  });

  return {
    generatedAt: new Date().toISOString().slice(0, 10),
    forkVersion,
    base,
    size,
    upstream,
  };
}

// Only run when executed directly (so the parser can be imported by a test).
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const stats = compute();
  mkdirSync(dirname(OUT), { recursive: true });
  writeFileSync(OUT, JSON.stringify(stats, null, 2) + '\n');
  console.log(`[fork-stats] wrote ${OUT.replace(ROOT + '/', '')}:`, JSON.stringify(stats));
}
