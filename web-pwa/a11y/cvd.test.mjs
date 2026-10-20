import test from 'node:test';
import assert from 'node:assert/strict';
import {
  CVD_TYPES, simulate, luminance, contrastRatio, toLab, chroma,
  deltaE2000, perceivedDifference, confusability,
} from './cvd.mjs';

const close = (actual, expected, tol, msg) =>
  assert.ok(Math.abs(actual - expected) <= tol, `${msg ?? ''} expected ${expected}±${tol}, got ${actual}`);

test('deltaE2000 matches the published reference pairs (Sharma, Wu & Dalal 2005)', () => {
  close(deltaE2000([50, 2.6772, -79.7751], [50, 0, -82.7485]), 2.0425, 1e-3);
  close(deltaE2000([50, 2.5, 0], [50, 0, -2.5]), 4.3065, 1e-3);
  close(deltaE2000([50, 0, 0], [50, -1, 2]), 2.3669, 1e-3);
  close(deltaE2000([50, 2.5, 0], [73, 25, -18]), 27.1492, 1e-3);
  assert.equal(deltaE2000([60, 10, 10], [60, 10, 10]), 0);
});

test('toLab: white, black and a mid grey', () => {
  const [lw, aw, bw] = toLab([255, 255, 255]);
  close(lw, 100, 0.05); close(aw, 0, 0.05); close(bw, 0, 0.05);
  close(toLab([0, 0, 0])[0], 0, 0.05);
  close(toLab([119, 119, 119])[0], 50, 0.5);
});

test('WCAG luminance and contrast', () => {
  assert.equal(luminance([255, 255, 255]), 1);
  close(contrastRatio([255, 255, 255], [0, 0, 0]), 21, 1e-9);
  close(contrastRatio([119, 119, 119], [255, 255, 255]), 4.48, 0.02);
});

test('greys and white are unchanged by every simulation', () => {
  for (const type of CVD_TYPES) {
    for (const grey of [[255, 255, 255], [128, 128, 128], [0, 0, 0]]) {
      simulate(grey, type).forEach((c, i) => close(c, grey[i], 1.5, `${type} ${grey}`));
    }
  }
});

test('achromatopsia output is grey', () => {
  const [r, g, b] = simulate([200, 30, 30], 'achromatopsia');
  close(r, g, 1e-6); close(g, b, 1e-6);
});

test('unknown deficiency is rejected', () => {
  assert.throws(() => simulate([1, 2, 3], 'nope'), /Unknown CVD type/);
});

test('chroma separates colour from grey', () => {
  assert.ok(chroma([200, 30, 30]) > 60);
  assert.ok(chroma([120, 120, 120]) < 1);
});

test('red and green collapse for red-green deficiencies but not for tritanopia', () => {
  // Tailwind-like status colours: green-600, red-600.
  const green = [22, 163, 74];
  const red = [220, 38, 38];
  assert.ok(perceivedDifference(green, red) > 40, 'distinct to a typical viewer');
  // The textbook confusable pair: a muted red-brown and a dark green of similar lightness.
  const lightnessMatched = [[30, 100, 60], [120, 80, 60]];
  for (const type of ['protanopia', 'deuteranopia']) {
    const r = confusability(lightnessMatched[0], lightnessMatched[1], type);
    assert.ok(r.confusable, `${type} should confuse lightness-matched red/green (${JSON.stringify(r)})`);
  }
  assert.ok(!confusability(lightnessMatched[0], lightnessMatched[1], 'tritanopia').confusable);
});

test('a pair that differs in lightness is not flagged even if the hues collapse', () => {
  const darkGreen = [0, 70, 28]; // the app's light-theme primary
  const lightRed = [255, 160, 160];
  for (const type of CVD_TYPES) assert.ok(!confusability(darkGreen, lightRed, type).confusable, type);
});

test('identical or already-similar colours are not "confusable"', () => {
  const c = [22, 163, 74];
  assert.ok(!confusability(c, c, 'deuteranopia').confusable);
  assert.ok(!confusability(c, [24, 160, 72], 'protanopia').confusable);
});
