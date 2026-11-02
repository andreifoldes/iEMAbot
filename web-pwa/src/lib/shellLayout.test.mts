/**
 * The app shell keeps the header and the chat row (menu, input, send button) fixed and scrolls only
 * the conversation. Below a short-viewport breakpoint (WCAG 1.4.10 reflow: 400 % zoom on a phone or
 * laptop leaves ~256 CSS px of height) index.css switches to one scrolling document instead.
 *
 * Regression: that breakpoint was `max-height: 30rem`. A rem in a media query is relative to the
 * browser's default font size, which Android raises with the system "Font size" setting, so on a
 * phone with a large system font (default ≥ 28px → breakpoint ≥ 840px) an ordinary portrait screen
 * fell into the reflow mode: the header scrolled away and the chat row no longer stuck to the bottom.
 * These tests pin the breakpoint to a size that does not depend on the font size.
 *
 * Regression 2: an absolutely positioned element inside the conversation (the `sr-only` ", " in each locked
 * questionnaire card) had no positioned ancestor, so it was placed against the page: neither the conversation's
 * nor the shell's overflow clipped it, and it stretched the document far below the shell. Scrolling past the end
 * of the conversation then scrolled the whole page into empty space. The shell and the conversation must stay
 * positioned (`relative`) so such elements stay inside the conversation's scroll area.
 *
 * The real-browser counterpart (large OS font on a phone, header/chat row stay put while the
 * conversation scrolls) is the "sticky-chrome" check in a11y/audit.mjs.
 *
 * Run with `npm test`.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const CSS = readFileSync(new URL('../index.css', import.meta.url), 'utf-8').replace(/\/\*[\s\S]*?\*\//g, '');
const APP = readFileSync(new URL('../App.tsx', import.meta.url), 'utf-8');

/** Every `@media (...) { ... }` block whose rules touch `.app-shell`, with its condition. */
function shellMediaQueries(): string[] {
  const out: string[] = [];
  const re = /@media\s*([^{]+)\{/g;
  for (let m; (m = re.exec(CSS)); ) {
    // Walk to the matching closing brace of the @media block.
    let depth = 1;
    let i = re.lastIndex;
    for (; i < CSS.length && depth; i++) {
      if (CSS[i] === '{') depth++;
      else if (CSS[i] === '}') depth--;
    }
    if (/\.app-shell\b/.test(CSS.slice(re.lastIndex, i))) out.push(m[1].trim());
  }
  return out;
}

/** Lengths in a media condition, e.g. `(max-height: 480px)` → [{ feature: 'max-height', value: 480, unit: 'px' }]. */
function lengths(condition: string) {
  return [...condition.matchAll(/([a-z-]*(?:width|height))\s*:\s*([\d.]+)([a-z%]*)/gi)]
    .map(([, feature, value, unit]) => ({ feature, value: Number(value), unit: unit.toLowerCase() }));
}

describe('app shell: header and chat row stay fixed on phones', () => {
  const queries = shellMediaQueries();

  it('has the short-viewport reflow breakpoint', () => {
    assert.ok(queries.some((q) => lengths(q).some((l) => l.feature === 'max-height')),
      `no @media (max-height: …) rule for .app-shell found; found: ${JSON.stringify(queries)}`);
  });

  it('sizes every shell breakpoint in px, so the system font size cannot move it', () => {
    for (const q of queries) {
      for (const l of lengths(q)) {
        assert.equal(l.unit, 'px',
          `@media ${q}: ${l.feature} is in "${l.unit}". rem/em in a media query follow the browser's default font size ` +
          '(Android raises it with the system font size), so a phone with a large font would leave the fixed layout.');
      }
    }
  });

  it('switches to the reflow layout only on genuinely short viewports', () => {
    for (const q of queries) {
      for (const l of lengths(q).filter((x) => x.feature === 'max-height')) {
        // 256 CSS px = 400 % zoom of a 1024px-tall screen; must still reflow there.
        assert.ok(l.value >= 256, `@media ${q}: breakpoint ${l.value}px misses the 400 % zoom case (256px tall)`);
        // Portrait phones (the smallest common ones leave ~560px for the page once the browser bars
        // are shown) must keep the fixed header and chat row.
        assert.ok(l.value < 560, `@media ${q}: breakpoint ${l.value}px would catch portrait phones`);
      }
    }
  });
});

describe('app shell: nothing inside it can stretch the page', () => {
  const classesOf = (re: RegExp, what: string) => {
    const m = APP.match(re);
    assert.ok(m, `${what} not found in App.tsx`);
    return m[1].split(/\s+/);
  };

  it('the shell is positioned', () => {
    const cls = classesOf(/'(app-shell [^']*)'/, 'the .app-shell class list');
    assert.ok(cls.includes('relative'), `.app-shell must be "relative" (has: ${cls.join(' ')})`);
  });

  it('the conversation is positioned, so absolutely positioned content scrolls inside it', () => {
    const cls = classesOf(/<main ref=\{scrollRef\}[^>]*?className="([^"]*)"/, 'the conversation <main>');
    assert.ok(cls.includes('overflow-y-auto'), 'the conversation should be its own scroll area');
    assert.ok(cls.includes('relative'),
      `the conversation <main> must be "relative" (has: ${cls.join(' ')}): without it an sr-only label in a message ` +
      'or card is placed against the page and makes the whole page scroll');
  });
});
