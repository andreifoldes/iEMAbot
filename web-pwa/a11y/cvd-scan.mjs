/**
 * Colour-vision-deficiency scan for the accessibility audit (WCAG 1.4.1 Use of Color).
 *
 * axe checks luminance contrast, which says nothing about whether two *colours* stay
 * apart for someone with red-green or blue-yellow colour blindness. This module reads the
 * colours the browser actually painted on a screen, simulates protanopia, deuteranopia,
 * tritanopia and achromatopsia (cvd.mjs), and answers two questions:
 *
 *  1. GATING — is there a *colour-only* indicator (a chromatic fill or border with no
 *     text, icon or label of its own or in its container) whose colour is confusable,
 *     under some deficiency, with another chromatic colour on the same screen? Such an
 *     indicator carries meaning that a colour-blind participant cannot read.
 *  2. INFORMATION — which chromatic text/icon colours collapse together under a
 *     deficiency (typically the green/red/amber status trio)? These are not failures
 *     while a word or icon accompanies the colour, but they are the places a future
 *     colour-only change would break, so they are listed in the report.
 *
 * Opt out a deliberate exception with `data-cvd-ok="<reason>"` on the element.
 */

import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { CVD_TYPES, chroma, confusability } from './cvd.mjs';

/** Colourfulness (Lab C*ab) above which a colour counts as "carrying hue". */
export const CHROMATIC_ABOVE = Number(process.env.A11Y_CVD_CHROMA || 25);
/** Smallest painted box (px) treated as an indicator rather than a hairline. */
const MIN_BOX = 6;

/**
 * Runs inside the page. Returns every visible element's painted colours with enough
 * context to judge whether the colour is the only cue. Kept free of closures over Node
 * state because Playwright serialises it.
 */
function collectCarriersInPage(MIN_BOX) {
  const canvas = document.createElement('canvas');
  canvas.width = canvas.height = 1;
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  // Any CSS colour syntax (oklch, color-mix, …) → sRGB 8-bit, via the canvas.
  const toRgba = (css) => {
    ctx.clearRect(0, 0, 1, 1);
    ctx.fillStyle = '#000';
    ctx.fillStyle = css;
    ctx.fillRect(0, 0, 1, 1);
    const d = ctx.getImageData(0, 0, 1, 1).data;
    return [d[0], d[1], d[2], d[3] / 255];
  };
  const over = (top, under) => [0, 1, 2].map((i) => top[i] * top[3] + under[i] * (1 - top[3]));

  const bgCache = new WeakMap();
  // The colour actually behind an element: its ancestors' backgrounds composited over white.
  const effectiveBg = (el) => {
    if (bgCache.has(el)) return bgCache.get(el);
    const parent = el.parentElement;
    const below = parent ? effectiveBg(parent) : [255, 255, 255];
    const own = toRgba(getComputedStyle(el).backgroundColor);
    const result = own[3] > 0 ? over(own, below) : below;
    bgCache.set(el, result);
    return result;
  };

  const describe = (el) => {
    const classes = (el.getAttribute('class') || '').split(/\s+/).filter(Boolean).slice(0, 4).join('.');
    return `${el.tagName.toLowerCase()}${el.id ? `#${el.id}` : ''}${classes ? `.${classes}` : ''}`;
  };
  const visibleText = (el) => (el.innerText || '').replace(/\s+/g, '').length > 0;
  const hasGraphic = (el) => !!el.querySelector('svg, img, canvas, picture') || /^(svg|img|canvas)$/i.test(el.tagName);
  const labelled = (el) => !!(el.getAttribute('aria-label') || el.getAttribute('title') || el.getAttribute('aria-labelledby'));
  // A container that says something in words (or draws an icon) gives its colour dots a cue.
  const containerCue = (el) => {
    const p = el.parentElement;
    if (!p) return false;
    const siblingText = [...p.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim());
    return visibleText(p) || siblingText || (hasGraphic(p) && !hasGraphic(el)) || labelled(p);
  };

  const out = [];
  for (const el of document.body.querySelectorAll('*')) {
    if (el.closest('[data-cvd-ok]')) continue;
    const cs = getComputedStyle(el);
    if (cs.visibility !== 'visible' || cs.display === 'none' || Number(cs.opacity) === 0) continue;
    const rect = el.getBoundingClientRect();
    if (rect.width < 1 || rect.height < 1) continue;

    const bg = effectiveBg(el);
    const base = {
      selector: describe(el),
      html: el.outerHTML.slice(0, 160),
      box: [Math.round(rect.width), Math.round(rect.height)],
      bg,
    };

    // Text and icon colour, over the real background.
    const ownText = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim());
    if (ownText || /^svg$/i.test(el.tagName)) {
      const fg = toRgba(cs.color);
      out.push({ ...base, kind: ownText ? 'text' : 'icon', rgb: over(fg, bg), cue: true });
    }

    // Painted fill: the element's own background where it differs from what is behind it.
    const own = toRgba(cs.backgroundColor);
    if (own[3] > 0.05 && rect.width >= MIN_BOX && rect.height >= MIN_BOX) {
      const below = el.parentElement ? effectiveBg(el.parentElement) : [255, 255, 255];
      const pure = !visibleText(el) && !hasGraphic(el) && !labelled(el);
      out.push({ ...base, kind: 'fill', rgb: over(own, below), cue: !pure || containerCue(el) });
    }

    // Painted border (indicator rings, outlines).
    const bw = parseFloat(cs.borderTopWidth);
    const bc = toRgba(cs.borderTopColor);
    if (bw >= 1 && cs.borderTopStyle !== 'none' && bc[3] > 0.2 && rect.width >= MIN_BOX && rect.height >= MIN_BOX) {
      const pure = !visibleText(el) && !hasGraphic(el) && !labelled(el);
      out.push({ ...base, kind: 'border', rgb: over(bc, bg), cue: !pure || containerCue(el) });
    }
  }
  return out;
}

const round = (rgb) => rgb.map((c) => Math.round(c));
const hex = (rgb) => `#${round(rgb).map((c) => c.toString(16).padStart(2, '0')).join('')}`;

/**
 * Pure colour-only carriers that are confusable with another chromatic colour on the
 * same screen. Returns one finding per carrier (with the worst partner per deficiency).
 */
export function findColorOnlyIndicators(carriers) {
  const chromatic = carriers.filter((c) => chroma(c.rgb) >= CHROMATIC_ABOVE);
  const findings = [];
  const seen = new Set();
  for (const c of chromatic.filter((x) => !x.cue)) {
    const key = `${c.selector}|${hex(c.rgb)}`;
    if (seen.has(key)) continue;
    seen.add(key);
    const clashes = [];
    for (const type of CVD_TYPES) {
      for (const other of chromatic) {
        if (other === c) continue;
        const r = confusability(c.rgb, other.rgb, type);
        if (r.confusable) { clashes.push({ type, other, r }); break; }
      }
    }
    if (clashes.length === 0) continue;
    findings.push({
      selector: c.selector,
      html: c.html,
      kind: c.kind,
      color: hex(c.rgb),
      clashes: clashes.map(({ type, other, r }) =>
        `${type}: ${hex(c.rgb)} ≈ ${hex(other.rgb)} (${other.kind} ${other.selector}; ΔE ${r.simulated.toFixed(1)}, contrast ${r.contrast.toFixed(2)}:1)`),
    });
  }
  return findings;
}

/**
 * Informational: chromatic text/icon colours that collapse together under a deficiency.
 * Keyed by "hexA|hexB" so the audit can aggregate across screens.
 */
export function collapsingColorPairs(carriers) {
  const unique = new Map();
  for (const c of carriers) {
    if ((c.kind === 'text' || c.kind === 'icon') && chroma(c.rgb) >= CHROMATIC_ABOVE) {
      unique.set(`${hex(c.rgb)}@${hex(c.bg)}`, c);
    }
  }
  const list = [...unique.values()];
  const pairs = new Map();
  for (let i = 0; i < list.length; i++) {
    for (let j = i + 1; j < list.length; j++) {
      for (const type of CVD_TYPES) {
        const r = confusability(list[i].rgb, list[j].rgb, type);
        if (!r.confusable) continue;
        const [a, b] = [hex(list[i].rgb), hex(list[j].rgb)].sort();
        const key = `${a}|${b}`;
        const entry = pairs.get(key) || { a, b, types: new Set(), normal: r.normal };
        entry.types.add(type);
        pairs.set(key, entry);
      }
    }
  }
  return pairs;
}

export async function collectCarriers(page) {
  return page.evaluate(collectCarriersInPage, MIN_BOX);
}

/**
 * Optional human-review artefacts: the same screen as Chromium itself renders it under each
 * deficiency (CDP vision-deficiency emulation, an independent check of cvd.mjs).
 */
export async function captureCvdScreenshots(page, dir, name) {
  mkdirSync(dir, { recursive: true });
  const cdp = await page.context().newCDPSession(page);
  try {
    for (const type of CVD_TYPES) {
      await cdp.send('Emulation.setEmulatedVisionDeficiency', { type });
      await page.screenshot({ path: resolve(dir, `${name}-${type}.png`) });
    }
  } finally {
    await cdp.send('Emulation.setEmulatedVisionDeficiency', { type: 'none' }).catch(() => {});
    await cdp.detach().catch(() => {});
  }
}
