import { test } from 'node:test';
import assert from 'node:assert/strict';
import { rewriteBundledAssets } from './bundledAssets.ts';

const study = { t: '<img src="https://srv.example/pwa/cookie-theft.jpg" alt="x"><img src="https://srv.example/pwa/other.png">', u: 'https://srv.example/pwa/cookie-theft.jpgx' };

test('rewrites only bundled names, keeps the rest', () => {
  const out = rewriteBundledAssets(study, ['cookie-theft.jpg', 'favicon.ico']);
  assert.equal(out.t, '<img src="cookie-theft.jpg" alt="x"><img src="https://srv.example/pwa/other.png">');
  assert.equal(out.u, study.u);
});

test('empty bundle list is a no-op', () => {
  assert.equal(rewriteBundledAssets(study, []), study);
});
