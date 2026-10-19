/**
 * Automated WCAG 2.2 accessibility audit for the ESMira participant PWA.
 *
 * Serves the production build (dist/pwa) with `vite preview`, then drives it with
 * Playwright + axe-core through the participant journey — invite-code screen,
 * consent, name entry, the tutorial overview, and a practice run of every
 * questionnaire — running an axe scan (WCAG 2.0/2.1/2.2 A & AA) at each state, in
 * both the light and dark themes. Colour-contrast is checked against the actually
 * rendered colours, so both themes are covered.
 *
 * Question-type coverage: by default the audit runs against a fixture study
 * (fixtures/study-all-types.json) served via request interception, so every
 * renderable question type — likert, single/multiple choice, yes/no, free text,
 * number, time, duration, date, visual-analogue scale, voice memo, cognitive
 * task, and info/image — is exercised regardless of what the live study contains.
 * The fixture is the real `ssrc` study (its morning / momentary / evening bundle)
 * plus an injected "All Question Types" questionnaire, so the requested sample
 * bundle is audited too. Set A11Y_MODE=live to instead drive the real study over
 * the network (catches server-side drift; needs VPN/connectivity).
 *
 * Exit code: non-zero if any violation with an impact in A11Y_FAIL_ON
 * (default: critical,serious) is found — this is what gates a prod deploy.
 *
 * Env knobs:
 *   A11Y_MODE        fixture (default) | live
 *   A11Y_KEY         study invite code for live mode (default: ssrc)
 *   A11Y_PORT        vite preview port (default: 4318)
 *   A11Y_BASE_URL    audit an already-running server instead of spawning preview
 *   A11Y_FAIL_ON     comma list of impacts that fail the gate (default critical,serious)
 *   A11Y_THEMES      comma list of themes to audit (default light,dark)
 *   A11Y_LIVE_BUNDLE 1 = also walk morning/momentary/evening in every theme (slower)
 *   A11Y_CVD         0 = skip the colour-vision-deficiency check (default on, see cvd-scan.mjs)
 *   A11Y_CVD_IMPACT  impact assigned to colour-only findings (default serious = gates)
 *   A11Y_CVD_SHOTS   1 = save each screen as seen with protanopia/deuteranopia/tritanopia/
 *                    achromatopsia to report/cvd/ for human review (large; off by default)
 *   ESMIRA_PROXY     API proxy target for live mode (default from vite.config.ts)
 */

import AxeBuilder from '@axe-core/playwright';
import { chromium } from 'playwright';
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { collectCarriers, findColorOnlyIndicators, collapsingColorPairs, captureCvdScreenshots } from './cvd-scan.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const WEB_PWA = resolve(__dirname, '..');

const MODE = process.env.A11Y_MODE || 'fixture';
const KEY = process.env.A11Y_KEY || 'ssrc';
const PORT = Number(process.env.A11Y_PORT || 4318);
const FAIL_ON = (process.env.A11Y_FAIL_ON || 'critical,serious').split(',').map((s) => s.trim()).filter(Boolean);
const THEMES = (process.env.A11Y_THEMES || 'light,dark').split(',').map((s) => s.trim()).filter(Boolean);
const CVD_ENABLED = process.env.A11Y_CVD !== '0';
const CVD_IMPACT = process.env.A11Y_CVD_IMPACT || 'serious';
const CVD_SHOTS = process.env.A11Y_CVD_SHOTS === '1';
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
// Pinned to 127.0.0.1 on BOTH sides (server bind below + browser URL): `localhost`
// can bind IPv6-only (::1) under modern Node while headless Chromium resolves it to
// IPv4 → connection refused → goto timeout, even though the readiness fetch passes.
const BASE = (process.env.A11Y_BASE_URL || `http://127.0.0.1:${PORT}`).replace(/\/$/, '');
const APP_URL = (qs = '') => `${BASE}/pwa/${qs}`;

const FIXTURE = MODE === 'fixture'
  ? JSON.parse(readFileSync(resolve(__dirname, 'fixtures', 'study-all-types.json'), 'utf-8'))
  : null;

/** All violations collected across the run: {mode,theme,screen,id,impact,help,helpUrl,nodes}. */
const findings = [];
const auditedScreens = [];
/** "#a|#b" → {a, b, types:Set, themes:Set, screens:Set}: text/icon colours that collapse for some deficiency. */
const cvdPairs = new Map();
const cvdShotScreens = new Set();

// ── tiny helpers ────────────────────────────────────────────────────────────
const log = (...a) => console.log(...a);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function present(locator, timeout = 1200) {
  try { await locator.first().waitFor({ state: 'visible', timeout }); return true; } catch { return false; }
}
async function tryClick(locator, timeout = 1500) {
  try {
    if (await present(locator, timeout)) { await locator.first().click({ timeout: 8000 }); return true; }
  } catch { /* not clickable (e.g. stays disabled) — let the caller move on */ }
  return false;
}

/** Apply a theme by toggling the classes the app's CSS variants key off of. */
async function applyTheme(page, theme) {
  await page.evaluate((t) => {
    const c = document.documentElement.classList;
    c.remove('dark', 'high-contrast');
    if (t.includes('dark')) c.add('dark');
    if (t.includes('high-contrast')) c.add('high-contrast');
  }, theme);
}

// Label of the non-default viewport being audited ('' = the 390px phone baseline).
let viewportLabel = '';

/**
 * WCAG 1.4.10 Reflow: at 320 CSS px (or a 256px-tall viewport, i.e. 400% zoom) content must
 * not need scrolling in two dimensions. Flags any element sticking out past the viewport
 * width, other than content inside its own scroll container.
 */
async function checkReflow(page, screen, theme) {
  const overflow = await page.evaluate(() => {
    const vw = document.documentElement.clientWidth;
    const out = [];
    const scrollable = (el) => {
      for (let p = el.parentElement; p; p = p.parentElement) {
        const o = getComputedStyle(p).overflowX;
        if (o === 'auto' || o === 'scroll' || o === 'hidden') return true;
      }
      return false;
    };
    for (const el of document.querySelectorAll('body *')) {
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0 || r.right <= vw + 1) continue;
      if (getComputedStyle(el).position === 'fixed' || scrollable(el)) continue;
      out.push(`${el.tagName.toLowerCase()}${el.className && typeof el.className === 'string' ? '.' + el.className.split(/\s+/).slice(0, 3).join('.') : ''} (right=${Math.round(r.right)} > ${vw})`);
      if (out.length >= 5) break;
    }
    const pageScrolls = document.documentElement.scrollWidth > vw + 1;
    return { out, pageScrolls };
  });
  if (!overflow.out.length && !overflow.pageScrolls) return;
  findings.push({
    mode: MODE, theme, screen,
    id: 'reflow-horizontal-scroll', impact: 'serious',
    help: 'Content overflows the viewport width and forces horizontal scrolling',
    helpUrl: 'https://www.w3.org/WAI/WCAG22/Understanding/reflow.html',
    nodes: (overflow.out.length ? overflow.out : ['document scrollWidth exceeds viewport']).map((t) => ({ target: t, html: '', summary: 'Wider than the viewport' })),
  });
}

/** Run one axe scan of the current DOM for a labelled screen, in the given theme. */
async function scan(page, screen, theme) {
  if (viewportLabel) screen = `${screen} @${viewportLabel}`;
  await applyTheme(page, theme);
  await sleep(120); // let transitions settle
  await checkReflow(page, screen, theme);
  const results = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
  auditedScreens.push(`${screen} [${theme}]`);
  for (const v of results.violations) {
    findings.push({
      mode: MODE, theme, screen,
      id: v.id, impact: v.impact || 'minor', help: v.help, helpUrl: v.helpUrl,
      nodes: v.nodes.map((n) => ({
        target: Array.isArray(n.target) ? n.target.join(' ') : String(n.target),
        html: (n.html || '').slice(0, 200),
        summary: (n.failureSummary || '').replace(/\s+/g, ' ').slice(0, 300),
      })),
    });
  }
  const cvdCount = CVD_ENABLED ? await scanColorVision(page, screen, theme) : 0;
  const total = results.violations.length + cvdCount;
  const crit = results.violations.filter((v) => FAIL_ON.includes(v.impact)).length
    + (FAIL_ON.includes(CVD_IMPACT) ? cvdCount : 0);
  log(`    · ${screen} [${theme}] — ${total} violation(s)${crit ? `, ${crit} gating` : ''}`);
}

/** Colour-vision-deficiency check for the screen on display (WCAG 1.4.1). Returns #findings added. */
async function scanColorVision(page, screen, theme) {
  const carriers = await collectCarriers(page);
  const indicators = findColorOnlyIndicators(carriers);
  if (indicators.length) {
    findings.push({
      mode: MODE, theme, screen,
      id: 'cvd-color-only', impact: CVD_IMPACT,
      help: 'A colour-only indicator is confusable with another colour for people with colour-vision deficiency',
      helpUrl: 'https://www.w3.org/WAI/WCAG22/Understanding/use-of-color.html',
      nodes: indicators.map((i) => ({ target: i.selector, html: i.html, summary: i.clashes.join(' | ').slice(0, 300) })),
    });
  }
  for (const [key, pair] of collapsingColorPairs(carriers)) {
    const entry = cvdPairs.get(key) || { a: pair.a, b: pair.b, types: new Set(), themes: new Set(), screens: new Set() };
    pair.types.forEach((t) => entry.types.add(t));
    entry.themes.add(theme);
    entry.screens.add(screen);
    cvdPairs.set(key, entry);
  }
  if (CVD_SHOTS && !cvdShotScreens.has(screen)) {
    cvdShotScreens.add(screen);
    await captureCvdScreenshots(page, resolve(__dirname, 'report', 'cvd'), `${screen.replace(/[^\w-]+/g, '_')}-${theme}`);
  }
  return indicators.length;
}

// ── preview server ──────────────────────────────────────────────────────────
// Spawned detached so teardown can signal the whole process group (npm parent +
// its vite child). A lingering vite grandchild on $PORT is what makes repeated
// runs flaky: the next run's --strictPort child exits immediately (port taken),
// but the stale server keeps answering the readiness probe, gets adopted, then
// dies mid-audit → ERR_CONNECTION_REFUSED. Group-kill + fail-fast close that gap.
let preview = null;
let previewExited = false;
async function startPreview() {
  if (process.env.A11Y_BASE_URL) { log(`▶ Using running server at ${BASE}`); return; }
  log(`▶ Starting vite preview on :${PORT} …`);
  previewExited = false;
  preview = spawn('npm', ['run', 'preview', '--', '--host', '127.0.0.1', '--port', String(PORT), '--strictPort'], {
    cwd: WEB_PWA,
    env: { ...process.env },
    stdio: ['ignore', 'pipe', 'pipe'],
    detached: true,
  });
  preview.on('exit', (code) => {
    previewExited = true;
    if (code) process.stderr.write(`vite preview exited (code ${code})\n`);
  });
  preview.stdout.on('data', (d) => { if (/error/i.test(String(d))) process.stderr.write(d); });
  preview.stderr.on('data', (d) => process.stderr.write(d));
  const deadline = Date.now() + 60_000;
  while (Date.now() < deadline) {
    // Our child exiting before it's ready almost always means $PORT is held by a
    // stale server — fail loudly instead of adopting it and crashing later.
    if (previewExited) throw new Error(`vite preview exited before becoming ready — is :${PORT} already in use?`);
    try { const r = await fetch(APP_URL()); if (r.ok) { log('  preview ready'); return; } } catch { /* not up yet */ }
    await sleep(500);
  }
  throw new Error('vite preview did not become ready within 60s');
}
function stopPreview() {
  if (!preview || previewExited) return;
  // Negative pid → signal the whole process group (npm parent + vite child), so
  // no vite grandchild survives to poison the next run.
  try { process.kill(-preview.pid, 'SIGTERM'); }
  catch { try { preview.kill('SIGTERM'); } catch { /* ignore */ } }
}

/** Restart the preview server if it has died (OOM, transient crash). No-op when
 *  auditing an externally-provided A11Y_BASE_URL — that server isn't ours. */
async function ensurePreview() {
  if (process.env.A11Y_BASE_URL) return;
  if (!preview || previewExited) {
    log('  ⚠ preview server is down — restarting …');
    await startPreview();
  }
}

/** page.goto with resilience to a transient loss of the preview server: on a
 *  network-level failure, make sure the server is up (restart if needed) and
 *  retry. A single blip mid-run must not abort an otherwise-clean audit. */
async function gotoApp(page, qs = '', opts = { waitUntil: 'domcontentloaded' }) {
  const url = APP_URL(qs);
  let lastErr;
  for (let attempt = 1; attempt <= 3; attempt++) {
    try { return await page.goto(url, opts); }
    catch (e) {
      lastErr = e;
      if (!/net::|ERR_CONNECTION|ERR_EMPTY_RESPONSE|ECONNREFUSED/i.test(String(e))) throw e;
      log(`  ⚠ navigation failed (attempt ${attempt}/3) for ${url}: ${String(e.message || e).split('\n')[0]}`);
      await ensurePreview();
      await sleep(500 * attempt);
    }
  }
  throw lastErr;
}

// ── request interception (fixture mode = fully offline & deterministic) ───────
async function installRouting(page, { phoneOnly = false } = {}) {
  if (MODE !== 'fixture') return;
  // `phoneOnly` mimics a researcher ticking "Only allow participation on a smartphone".
  const fixture = phoneOnly
    ? { ...FIXTURE, dataset: FIXTURE.dataset.map((study) => ({ ...study, webPhoneOnly: true })) }
    : FIXTURE;
  const body = JSON.stringify(fixture);
  await page.route('**/esmira/api/**', async (route) => {
    const url = route.request().url();
    if (url.includes('studies.php')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body });
    }
    // Every other endpoint is best-effort in the app; answer benignly so the
    // audit needs no network and logs no failures.
    return route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":false}' });
  });
}

// ── journey ─────────────────────────────────────────────────────────────────

const readyBtn = (page) => page.getByRole('button', { name: /^I'm ready/ });

/**
 * Advance from a fresh mount to the tutorial overview, handling the onboarding
 * gates: informed consent → name entry → the mandatory notifications step
 * (push-enabled studies). Returning visits (state already settled) fast-path
 * straight to the tutorial.
 */
async function reachTutorial(page) {
  await gotoApp(page, `?key=${encodeURIComponent(KEY)}&tutorial=1`);
  if (await present(readyBtn(page), 3500)) return; // returning visit → already at tutorial
  await tryClick(page.getByRole('button', { name: 'I consent' }), 5000);
  if (await present(page.getByPlaceholder('Enter your name'), 3500)) {
    await page.getByPlaceholder('Enter your name').fill('Audit Tester');
    await page.getByRole('button', { name: 'Send' }).click();
  }
  // Mandatory notifications step: opt out to continue (headless can't grant push).
  await tryClick(page.getByRole('button', { name: 'Continue without notifications' }), 5000);
  await present(readyBtn(page), 8000);
}

/**
 * Audit the install guidance, which is chosen from the browser's user agent: the
 * default headless UA only ever shows one branch, so each flavour gets its own
 * context with a UA override — iOS Safari / Chrome / outdated iOS (amber alert) /
 * an in-app web-view, Android with a captured install prompt, and a desktop browser
 * that can't install, with the "use your phone" QR panel open.
 */
async function auditInstallScreens(browser) {
  log(`\n▶ Auditing install screens (per-browser guidance, QR handoff)`);
  const UA = {
    iphoneSafari: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    iphoneOld: 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6 Mobile/15E148 Safari/604.1',
    iphoneChrome: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0.6099.119 Mobile/15E148 Safari/604.1',
    iphoneWhatsApp: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/21A329 WhatsApp/23.20.79',
    ipad: 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    androidChrome: 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
    desktopFirefox: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:120.0) Gecko/20100101 Firefox/120.0',
  };
  // Chromium fires `beforeinstallprompt` after load; simulate it so the native "Install app" button renders.
  const fireInstallPrompt = () => window.addEventListener('load', () => setTimeout(() => {
    const e = new Event('beforeinstallprompt', { cancelable: true });
    e.prompt = async () => {};
    e.userChoice = Promise.resolve({ outcome: 'accepted' });
    window.dispatchEvent(e);
  }, 50));
  // Pretend the page runs as an installed home-screen app: that is when the code-entry form shows.
  const installedApp = () => {
    const real = window.matchMedia.bind(window);
    window.matchMedia = (q) => /display-mode:\s*standalone/.test(q)
      ? { matches: true, media: q, onchange: null, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {}, dispatchEvent: () => false }
      : real(q);
  };
  const phone = { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true };
  const desktop = { viewport: { width: 1100, height: 800 } };
  const variants = [
    { name: 'install-ios-safari', ua: UA.iphoneSafari, ...phone },
    { name: 'install-ios-chrome', ua: UA.iphoneChrome, ...phone },
    { name: 'install-ios-outdated', ua: UA.iphoneOld, ...phone },
    { name: 'install-in-app-browser', ua: UA.iphoneWhatsApp, ...phone },
    { name: 'install-android-native', ua: UA.androidChrome, init: fireInstallPrompt, ...phone },
    {
      name: 'install-desktop-qr', ua: UA.desktopFirefox, ...desktop,
      prepare: async (page) => { await present(page.locator('svg[role="img"]'), 4000); },
    },
    // Study restricted to smartphones: the invite page with a QR code replaces the study, on a
    // computer and on a tablet (which also gets the "I'm on my phone — continue here" escape).
    {
      name: 'phone-only-invite-desktop', ua: UA.desktopFirefox, ...desktop,
      phoneOnly: true, qs: `?key=${encodeURIComponent(KEY)}&pid=P001`, ready: 'Open this study on your phone',
      prepare: async (page) => { await present(page.locator('svg[role="img"]'), 4000); },
    },
    // Code entry in the installed app: invite code plus the optional participant ID (iOS keeps no state across the install).
    {
      name: 'code-entry-installed-app', ua: UA.iphoneSafari, ...phone, init: installedApp,
      ready: 'Enter your study invite code',
      prepare: async (page) => { await present(page.getByLabel('Participant ID, if you were given one'), 2000); },
    },
    // The enlarged QR code (lightbox) open over the phone-only invite page.
    {
      name: 'phone-only-invite-qr-enlarged', ua: UA.desktopFirefox, ...desktop,
      phoneOnly: true, qs: `?key=${encodeURIComponent(KEY)}&pid=P001`, ready: 'Open this study on your phone',
      prepare: async (page) => {
        await page.getByRole('button', { name: 'Enlarge QR code' }).click();
        await present(page.getByRole('dialog', { name: 'QR code, enlarged' }), 4000);
        await present(page.locator('[role="dialog"] svg[role="img"]'), 4000);
      },
    },
    {
      name: 'phone-only-invite-tablet', ua: UA.ipad,
      viewport: { width: 820, height: 1180 }, isMobile: true, hasTouch: true, screen: { width: 820, height: 1180 },
      phoneOnly: true, qs: `?key=${encodeURIComponent(KEY)}&pid=P001`, ready: 'Open this study on your phone',
      prepare: async (page) => {
        await present(page.locator('svg[role="img"]'), 4000);
        await present(page.getByRole('button', { name: /continue here/ }), 2000);
      },
    },
  ];
  // The phone-only variants need the fixture study patched (installRouting), so skip them in live mode.
  for (const v of variants.filter((x) => !x.phoneOnly || MODE === 'fixture')) {
    const context = await browser.newContext({
      viewport: v.viewport, isMobile: v.isMobile, hasTouch: v.hasTouch, userAgent: v.ua, screen: v.screen,
      reducedMotion: 'reduce', serviceWorkers: 'block',
    });
    try {
      const page = await context.newPage();
      if (v.init) await page.addInitScript(v.init);
      await installRouting(page, { phoneOnly: v.phoneOnly });
      await gotoApp(page, v.qs ?? '', { waitUntil: 'networkidle' });
      if (!(await present(page.getByText(v.ready ?? 'Install the app to begin'), 8000)))
        throw new Error(`${v.name}: expected screen never appeared ("${v.ready ?? 'Install the app to begin'}")`);
      await sleep(400);
      await v.prepare?.(page);
      for (const theme of THEMES) await scan(page, v.name, theme);
    } finally {
      await context.close();
    }
  }
}

/** One-time audit of the onboarding screens (consent, name, notifications), each
 *  in every theme via class-toggling, then leaves the app at the tutorial. */
async function auditOnboarding(page) {
  log(`\n▶ Auditing onboarding (consent → name → notifications)`);
  await gotoApp(page, `?key=${encodeURIComponent(KEY)}&tutorial=1`);
  if (await present(page.getByRole('button', { name: 'I consent' }), 6000)) {
    for (const theme of THEMES) await scan(page, 'consent', theme);
    await page.getByRole('button', { name: 'I consent' }).click();
  }
  if (await present(page.getByPlaceholder('Enter your name'), 4000)) {
    for (const theme of THEMES) await scan(page, 'name-entry', theme);
    await page.getByPlaceholder('Enter your name').fill('Audit Tester');
    await page.getByRole('button', { name: 'Send' }).click();
  }
  if (await present(page.getByRole('button', { name: 'Continue without notifications' }), 5000)) {
    for (const theme of THEMES) await scan(page, 'notifications-gate', theme);
    await page.getByRole('button', { name: 'Continue without notifications' }).click();
  }
  await present(readyBtn(page), 8000);
}

/** Audit the app-chrome modals (menu, settings + subpanels, details, contact). */
async function auditModals(page, theme) {
  const openMenu = () => tryClick(page.getByRole('button', { name: 'Quick actions' }), 4000);

  // Quick-actions grid menu
  if (await openMenu()) {
    await scan(page, 'menu', theme);
    // Settings modal (appearance) + its sub-panels
    if (await tryClick(page.getByRole('button', { name: 'Settings', exact: true }))) {
      await scan(page, 'settings-appearance', theme);
      const back = () => tryClick(page.getByRole('button', { name: 'Back' }));
      if (await tryClick(page.getByRole('button', { name: 'Send error report' }))) { await scan(page, 'settings-error-report', theme); await back(); }
      if (await tryClick(page.getByRole('button', { name: 'Notifications not working?' }))) { await scan(page, 'settings-notifications', theme); await back(); }
      if (await tryClick(page.getByRole('button', { name: 'Connect wearables' }))) { await scan(page, 'settings-wearables', theme); await back(); }
      if (await tryClick(page.getByRole('button', { name: 'About ESMira' }))) { await scan(page, 'settings-about', theme); }
      await tryClick(page.getByRole('button', { name: 'Close Settings' }));
    }
  }
  // Details / study-information modal
  if (await openMenu() && await tryClick(page.getByRole('button', { name: 'Details' }))) {
    await scan(page, 'details', theme);
    const back = () => tryClick(page.getByRole('button', { name: 'Back' }));
    if (await tryClick(page.getByRole('button', { name: 'Study description' }))) { await scan(page, 'details-description', theme); await back(); }
    if (await tryClick(page.getByRole('button', { name: 'Informed consent' }))) { await scan(page, 'details-consent', theme); await back(); }
    if (await tryClick(page.getByRole('button', { name: 'Upload protocol' }))) { await scan(page, 'details-protocol', theme); await back(); }
    await tryClick(page.getByRole('button', { name: 'Close', exact: true }));
  }
  // Contact modal
  if (await openMenu() && await tryClick(page.getByRole('button', { name: 'Contact' }))) {
    await scan(page, 'contact', theme);
    await tryClick(page.getByRole('button', { name: 'Close', exact: true }));
  }
}

/**
 * Answer whatever survey control is on screen, so the walk advances. Returns
 * false when no known control is present (questionnaire finished / stuck).
 */
async function advanceSurvey(page, theme, stepLabel) {
  const M = page.locator('main');
  // info card → Continue
  if (await present(M.getByRole('button', { name: 'Continue' }), 400)) { await M.getByRole('button', { name: 'Continue' }).click(); return true; }
  // voice-memo card → open recorder, audit it, close, then skip
  if (await present(M.getByRole('button', { name: 'Record voice memo' }), 400)) {
    await M.getByRole('button', { name: 'Record voice memo' }).click();
    if (await present(page.getByRole('dialog'), 3000)) { await scan(page, `${stepLabel}:recorder`, theme); }
    await tryClick(page.getByRole('button', { name: 'Close', exact: true }));
    return await tryClick(M.getByRole('button', { name: 'Skip' }), 1000);
  }
  // cognitive launch card → Skip (never open the external task iframe)
  if (await present(M.getByRole('button', { name: /^Start the/ }), 400)) {
    return await tryClick(M.getByRole('button', { name: 'Skip' }), 1000);
  }
  // multiple choice → pick one, then Done
  if (await present(M.locator('[role=group][aria-label="Select all that apply"]'), 400)) {
    await M.locator('[role=checkbox]').first().click();
    return await tryClick(M.getByRole('button', { name: 'Done' }));
  }
  // scale/choice/yes-no groups → click the first option
  for (const sel of ['[role=group][aria-label^="Rate from"]', '[role=group][aria-label="Yes or no"]', '[role=group][aria-label="Answer options"]']) {
    if (await present(M.locator(sel), 300)) { await M.locator(`${sel} button`).first().click(); return true; }
  }
  // number → fill + confirm
  if (await present(M.locator('input[type=number]'), 300)) {
    await M.locator('input[type=number]').fill('3');
    return await tryClick(page.getByRole('button', { name: 'Confirm' }));
  }
  // date → fill + confirm
  if (await present(M.locator('input[type=date]'), 300)) {
    await M.locator('input[type=date]').fill('2024-01-15');
    return await tryClick(page.getByRole('button', { name: 'Confirm' }));
  }
  // visual-analogue scale → set + confirm
  if (await present(M.locator('input[type=range]'), 300)) {
    await M.locator('input[type=range]').fill('70');
    return await tryClick(page.getByRole('button', { name: 'Confirm' }));
  }
  // time / duration → two selects + confirm
  const hour = M.locator('select[aria-label="Hour"], select[aria-label="Hours"]');
  const minute = M.locator('select[aria-label="Minute"], select[aria-label="Minutes"]');
  if (await present(hour, 300)) {
    // index 0 is the disabled placeholder; pick non-zero values so a duration
    // picker (which needs total ≥ 1 min) enables its Confirm button.
    await hour.first().selectOption({ index: 2 });
    if (await present(minute, 300)) await minute.first().selectOption({ index: 3 });
    return await tryClick(page.getByRole('button', { name: 'Confirm' }));
  }
  // free-text question → answer via the footer
  if (await present(page.getByPlaceholder('Type your response…'), 400)) {
    await page.getByPlaceholder('Type your response…').fill('A calm and productive day.');
    await page.getByRole('button', { name: 'Send' }).click();
    return true;
  }
  return false;
}

/** Start a practice run of one questionnaire and audit every question state. */
async function walkQuestionnaire(page, titleRe, label, theme, maxSteps = 24) {
  await reachTutorial(page);
  const practice = page.getByRole('button', { name: new RegExp(`practice run of .*${titleRe}`, 'i') });
  if (!await present(practice, 5000)) {
    const names = await page.getByRole('button', { name: /practice run of/i }).evaluateAll((els) => els.map((e) => e.getAttribute('aria-label')));
    log(`    ! could not find practice button for ${label} — available: ${JSON.stringify(names)}`);
    return;
  }
  await practice.first().click();
  for (let step = 1; step <= maxSteps; step++) {
    if (!await present(page.locator('main'), 2000)) break;
    // Stop when we've fallen back to the tutorial overview (practice complete).
    if (await present(readyBtn(page), 300)) break;
    await scan(page, `${label}:q${step}`, theme);
    if (!await advanceSurvey(page, theme, `${label}:q${step}`)) break;
    await sleep(180);
  }
}

// ── report ──────────────────────────────────────────────────────────────────
function writeReport() {
  const outDir = resolve(__dirname, 'report');
  mkdirSync(outDir, { recursive: true });
  const gating = findings.filter((f) => FAIL_ON.includes(f.impact));
  const byImpact = (imp) => findings.filter((f) => f.impact === imp);
  const uniqRules = [...new Set(findings.map((f) => f.id))];

  writeReport.json = {
    mode: MODE, themes: THEMES, failOn: FAIL_ON,
    screensAudited: auditedScreens.length, screens: auditedScreens,
    totalViolations: findings.length, gatingViolations: gating.length,
    counts: { critical: byImpact('critical').length, serious: byImpact('serious').length, moderate: byImpact('moderate').length, minor: byImpact('minor').length },
    rules: uniqRules, findings,
    colourVision: CVD_ENABLED ? {
      impact: CVD_IMPACT,
      collapsingPairs: [...cvdPairs.values()].map((p) => ({ a: p.a, b: p.b, types: [...p.types], themes: [...p.themes], screens: [...p.screens] })),
    } : null,
  };
  writeFileSync(resolve(outDir, 'a11y-report.json'), JSON.stringify(writeReport.json, null, 2));

  // Markdown summary grouped by rule → screens.
  const lines = ['# ESMira PWA — accessibility audit', '',
    `- Mode: **${MODE}**  |  Themes: **${THEMES.join(', ')}**  |  Gate impacts: **${FAIL_ON.join(', ')}**`,
    `- Screens audited: **${auditedScreens.length}**`,
    `- Violations: **${findings.length}** total — 🔴 ${writeReport.json.counts.critical} critical, 🟠 ${writeReport.json.counts.serious} serious, 🟡 ${writeReport.json.counts.moderate} moderate, ⚪ ${writeReport.json.counts.minor} minor`,
    `- **Gate: ${gating.length ? `❌ FAIL (${gating.length} critical/serious)` : '✅ PASS'}**`, ''];
  const grouped = new Map();
  for (const f of findings) {
    const k = f.id;
    if (!grouped.has(k)) grouped.set(k, { impact: f.impact, help: f.help, helpUrl: f.helpUrl, hits: [] });
    grouped.get(k).hits.push(`${f.screen} [${f.theme}] × ${f.nodes.length}`);
  }
  const order = { critical: 0, serious: 1, moderate: 2, minor: 3 };
  for (const [id, g] of [...grouped.entries()].sort((a, b) => order[a[1].impact] - order[b[1].impact])) {
    lines.push(`## ${g.impact.toUpperCase()} — \`${id}\``, `${g.help}  ([ref](${g.helpUrl}))`, '', ...g.hits.map((h) => `- ${h}`), '');
  }
  if (CVD_ENABLED) {
    lines.push('## Colour-vision deficiency (WCAG 1.4.1)', '',
      `Colour-only indicators that clash under a deficiency are gated as **${CVD_IMPACT}** (\`cvd-color-only\` above).`, '');
    if (cvdPairs.size === 0) {
      lines.push('No chromatic text/icon colours collapse together under any simulated deficiency.', '');
    } else {
      lines.push('These text/icon colours look alike to someone with the listed deficiency. They are **not failures** while a word or',
        'icon accompanies the colour; they mark where a future colour-only change would break.', '',
        '| Colour A | Colour B | Collapses under | Seen in |', '| --- | --- | --- | --- |',
        ...[...cvdPairs.values()].map((p) => `| \`${p.a}\` | \`${p.b}\` | ${[...p.types].join(', ')} | ${p.screens.size} screen(s), ${[...p.themes].join('/')} |`), '');
    }
  }
  writeFileSync(resolve(outDir, 'a11y-report.md'), lines.join('\n'));
  log(`\n▶ Report written to a11y/report/a11y-report.{json,md}`);
}

// ── main ────────────────────────────────────────────────────────────────────
(async () => {
  await startPreview();
  const browser = await chromium.launch({
    args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream', '--autoplay-policy=no-user-gesture-required'],
  });
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    permissions: ['microphone'],
    // Audit the settled visual state: prefers-reduced-motion stops the app's
    // fade/scale entry animations, so axe measures final colours rather than a
    // mid-transition blend (which reads as a false contrast failure). Colours in
    // the settled full-motion state are identical.
    reducedMotion: 'reduce',
    // Block the app's service worker so (a) request interception (fixture mode)
    // actually intercepts the study fetch — the SW otherwise proxies it — and
    // (b) audits aren't served from a stale precache.
    serviceWorkers: 'block',
  });
  context.on('page', (p) => p.on('dialog', (d) => d.dismiss().catch(() => {})));
  const page = await context.newPage();
  page.on('dialog', (d) => d.dismiss().catch(() => {}));
  await installRouting(page);

  try {
    log(`\n▶ Auditing invite-code screen`);
    await gotoApp(page);
    await present(page.getByRole('button', { name: 'Continue' }), 8000);
    for (const theme of THEMES) await scan(page, 'enter-code', theme);

    await auditInstallScreens(browser);

    await auditOnboarding(page);

    for (const theme of THEMES) {
      log(`\n▶ Theme: ${theme}`);
      await reachTutorial(page);
      await scan(page, 'tutorial-overview', theme);
      await auditModals(page, theme);
      await walkQuestionnaire(page, 'All Question Types', 'all-types', theme);
      if (theme === THEMES[0] || process.env.A11Y_LIVE_BUNDLE === '1') {
        await walkQuestionnaire(page, 'Morning', 'morning', theme);
        await walkQuestionnaire(page, 'Momentary', 'momentary', theme);
        await walkQuestionnaire(page, 'Evening', 'evening', theme);
      }
    }

    // Reflow passes: 320 CSS px wide, and a 256px-tall viewport (≈400% zoom of a laptop).
    for (const vp of [{ label: '320', width: 320, height: 640 }, { label: 'short', width: 1280, height: 256 }]) {
      log(`\n▶ Viewport: ${vp.label} (${vp.width}x${vp.height})`);
      await page.setViewportSize({ width: vp.width, height: vp.height });
      viewportLabel = vp.label;
      await reachTutorial(page);
      await scan(page, 'tutorial-overview', THEMES[0]);
      await walkQuestionnaire(page, 'All Question Types', 'all-types', THEMES[0]);
      viewportLabel = '';
    }
    await page.setViewportSize({ width: 390, height: 844 });
  } finally {
    await context.close();
    await browser.close();
    stopPreview();
  }

  writeReport();
  const gating = findings.filter((f) => FAIL_ON.includes(f.impact));
  const j = writeReport.json;
  log(`\n${'─'.repeat(60)}`);
  log(`Screens: ${j.screensAudited}  |  Violations: ${j.totalViolations}  (🔴 ${j.counts.critical} / 🟠 ${j.counts.serious} / 🟡 ${j.counts.moderate} / ⚪ ${j.counts.minor})`);
  if (gating.length) {
    log(`\n❌ Accessibility gate FAILED — ${gating.length} ${FAIL_ON.join('/')} violation node(s):`);
    const seen = new Set();
    for (const f of gating) {
      const k = `${f.id}@${f.screen}:${f.theme}`;
      if (seen.has(k)) continue; seen.add(k);
      log(`  • [${f.impact}] ${f.id} — ${f.screen} [${f.theme}] (${f.nodes.length} node${f.nodes.length > 1 ? 's' : ''})`);
    }
    process.exitCode = 1;
  } else {
    log(`\n✅ Accessibility gate PASSED — no ${FAIL_ON.join('/')} violations.`);
  }
})().catch((e) => {
  console.error('\n✖ Audit crashed:', e);
  stopPreview();
  process.exit(2);
});
