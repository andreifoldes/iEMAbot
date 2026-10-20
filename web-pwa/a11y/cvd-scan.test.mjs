/**
 * Proves the colour-only detector can actually fail (a clean audit run alone would also be
 * what a blind detector reports). Uses a controlled page in headless Chromium.
 */
import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { collectCarriers, findColorOnlyIndicators, collapsingColorPairs } from './cvd-scan.mjs';

// Saturated amber ("warning") and olive green ("success") of similar lightness: both clearly
// coloured, clearly different to a typical viewer, and nearly identical for red-green colour blindness.
const GREEN = '#878c00';
const BROWN = '#be7800';

let browser;
let page;
before(async () => {
  browser = await chromium.launch();
  page = await browser.newPage();
});
after(async () => { await browser?.close(); });

const render = async (body) => {
  await page.setContent(`<body style="margin:0;background:#fff;font:16px sans-serif">${body}</body>`);
  return findColorOnlyIndicators(await collectCarriers(page));
};
const dot = (color, extra = '') =>
  `<span ${extra} style="display:inline-block;width:16px;height:16px;border-radius:50%;background:${color}"></span>`;

test('two lightness-matched colour-only dots are flagged', async () => {
  const found = await render(`<div>${dot(GREEN)}</div><div>${dot(BROWN)}</div>`);
  assert.ok(found.length >= 1, 'expected a colour-only finding');
  assert.match(found[0].clashes.join(' '), /protanopia|deuteranopia/);
});

test('the same dots next to a text label are accepted', async () => {
  const found = await render(`<div>${dot(GREEN)} Connected</div><div>${dot(BROWN)} Failed</div>`);
  assert.deepEqual(found, []);
});

test('dots that differ in lightness stay distinguishable and are accepted', async () => {
  const found = await render(`<div>${dot('#003d16')}</div><div>${dot('#ffb3b3')}</div>`);
  assert.deepEqual(found, []);
});

test('a lone coloured dot has nothing to clash with', async () => {
  assert.deepEqual(await render(`<div>${dot(GREEN)}</div>`), []);
});

test('an accessible name counts as a cue', async () => {
  const found = await render(`<div>${dot(GREEN, 'role="img" aria-label="ok"')}</div><div>${dot(BROWN, 'role="img" aria-label="failed"')}</div>`);
  assert.deepEqual(found, []);
});

test('data-cvd-ok opts a deliberate exception out', async () => {
  const found = await render(`<div data-cvd-ok="legend is repeated in the table below">${dot(GREEN)}${dot(BROWN)}</div>`);
  assert.deepEqual(found, []);
});

test('alpha tints are composited over the real background before comparing', async () => {
  // A 10% green wash over white is nearly white, not chromatic: it must not be reported.
  const found = await render(`<div style="width:40px;height:40px;background:rgba(0,200,80,.1)"></div>${dot(BROWN)}`);
  assert.deepEqual(found, []);
});

test('text/icon colours that collapse are reported as information, not findings', async () => {
  await page.setContent(`<body style="background:#fff"><p style="color:${GREEN}">Connected</p><p style="color:${BROWN}">Failed</p></body>`);
  const carriers = await collectCarriers(page);
  assert.deepEqual(findColorOnlyIndicators(carriers), []);
  const pairs = collapsingColorPairs(carriers);
  assert.equal(pairs.size, 1);
  assert.ok([...pairs.values()][0].types.has('deuteranopia'));
});
