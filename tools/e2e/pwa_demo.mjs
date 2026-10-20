/**
 * End-to-end test of the "Demo: all question types" study in the participant PWA.
 *
 * Opens the PWA on an emulated phone with a fresh participant id, consents, picks a name and
 * answers every item the PWA renders through its real UI (buttons, toggles, slider, pickers,
 * typed answers, the keystroke writer). The voice memo and the cognitive task are skipped (no
 * microphone / no reaction test in a headless browser). Exits non-zero unless the questionnaire
 * completes.
 *
 * Uses the Playwright install of the accessibility audit (web-pwa/a11y,  there once):
 *   node tools/e2e/pwa_demo.mjs <pwa_base_url> [access_key=demoall] [screenshot.png]
 *   e.g. node tools/e2e/pwa_demo.mjs https://ema.example.org/pwa/
 */
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
const require = createRequire(fileURLToPath(new URL('../../web-pwa/a11y/package.json', import.meta.url)));
const { chromium, devices } = require('playwright');
const BASE = (process.argv[2] || '').replace(/\/?$/, '/');
const KEY = process.argv[3] || 'demoall';
const OUT = process.argv[4] || '';
const browser = await chromium.launch();
const ctx = await browser.newContext({ ...devices['iPhone 13'] });
const page = await ctx.newPage();
page.on('dialog', d => d.dismiss());
await page.goto(`${BASE}?key=${KEY}&pid=e2e-web-${Date.now()}`, { waitUntil: 'networkidle' });
await page.waitForTimeout(1200);

const live = () => page.locator('[data-live-question]').last();
const btn = (name, scope = page) => scope.getByRole('button', { name, exact: true }).last();
const visible = async (loc) => (await loc.count()) > 0 && await loc.isVisible().catch(() => false);
const pause = (ms = 900) => page.waitForTimeout(ms);
const confirm = async (scope) => {
  for (const n of ['Confirm', 'Done', 'Send', 'Continue', 'Save', 'Submit']) {
    const b = btn(n, scope);
    if (await visible(b) && await b.isEnabled()) { await b.click(); return n; }
  }
  return null;
};
const chat = async (text) => {
  const box = page.locator('input[aria-label="Type a response"]').last();
  for (let i = 0; i < 30 && !(await box.isEnabled()); i++) await pause(300);
  await box.fill(text); await pause(200);
  await btn('Send').click();
};

async function writeModal(text) {
  await pause(600);
  const area = page.locator('[role=dialog] textarea, textarea').last();
  await area.click(); await area.pressSequentially(text, { delay: 15 });
  for (const n of ['Save', 'Done', 'Submit', 'Send']) {
    const b = page.getByRole('button', { name: new RegExp('^' + n, 'i') }).last();
    if (await visible(b) && await b.isEnabled()) { await b.click(); return `modal ${n}`; }
  }
  return 'modal: no save button';
}

const log = [];
let started = false;
let named = false;
for (let step = 0; step < 70; step++) {
  await pause();
  const body = await page.innerText('body');
  const L = live();
  const q = (await visible(L)) ? (await L.innerText()) : '';
  const head = q.split('\n').find(Boolean) || '';
  let action = null;
  if (await visible(btn('I consent'))) { await btn('I consent').click(); action = 'consent'; }
  else if (!named && !q && /name would you like/i.test(body.slice(-400))) { await chat('Demo tester'); action = 'name'; named = true; }
  else if (!q && await visible(page.getByRole('button', { name: /not now|no thanks|skip/i }).last())) { await page.getByRole('button', { name: /not now|no thanks|skip/i }).last().click(); action = 'decline offer'; }
  else if (started && !q && await visible(page.getByRole('button', { name: /All question types/ }).last())) { action = 'DONE'; }
  else if (!q && await visible(page.getByRole('button', { name: /All question types/ }).last())) { await page.getByRole('button', { name: /All question types/ }).last().click(); action = 'open questionnaire'; started = true; }
  else if (/Likert: how is your mood/.test(q)) { await btn('4', L).click(); action = 'likert 4'; }
  else if (/Binary: are you alone/.test(q)) { await btn('No', L).click(); action = 'binary No'; }
  else if (/Multiple choice/.test(q)) { await L.getByText('Partner', { exact: true }).click(); await L.getByText('Family', { exact: true }).click(); action = 'multi ' + await confirm(L); }
  else if (/Single choice/.test(q)) { await L.getByText('Somewhere else', { exact: true }).click(); await pause(); await writeModalOther(); action = 'single + other'; }
  else if (/Visual analogue/.test(q)) { await L.locator('input[type=range]').fill('63'); action = 'vas ' + await confirm(L); }
  else if (/Free text/.test(q)) { await chat('Testing from the PWA'); action = 'free text'; }
  else if (/Whole number/.test(q)) { await L.locator('input').first().fill('2'); action = 'int ' + await confirm(L); }
  else if (/Decimal number/.test(q)) { await L.locator('input').first().fill('36.6'); action = 'dec ' + await confirm(L); }
  else if (/Time: when did you go to bed/.test(q)) { const s = L.locator('select'); await s.nth(0).selectOption('23'); await s.nth(1).selectOption('30'); action = 'time ' + await confirm(L); }
  else if (/fall asleep/.test(q)) { const s = L.locator('select'); await s.nth(0).selectOption('0'); await s.nth(1).selectOption('20'); action = 'howlong ' + await confirm(L); }
  else if (/Duration: how long did you sleep/.test(q)) { const s = L.locator('select'); await s.nth(0).selectOption('7'); await s.nth(1).selectOption('30'); action = 'duration ' + await confirm(L); }
  else if (/Date: which day/.test(q)) { await L.locator('input[type=date]').fill('2026-10-20'); action = 'date ' + await confirm(L); }
  else if (/Voice memo/.test(q) && await visible(btn('Skip', L))) { await btn('Skip', L).click(); action = 'skip voice memo'; }
  else if (await visible(L.getByRole('button', { name: /Write your answer/ }))) { await L.getByRole('button', { name: /Write your answer/ }).click(); action = 'keystroke ' + await writeModal('My morning was calm and sunny, thanks for asking.'); }
  else if (/Reaction-time game/.test(q)) { await btn('Skip', L).click(); action = 'skip cognitive task'; }
  else if (q && await visible(btn('Continue', L))) { await btn('Continue', L).click(); action = 'continue: ' + head.slice(0, 40); }
  else if (started && /thank|completed|saved/i.test(body.slice(-300)) && !q) { action = 'DONE'; }
  log.push(`[${step}] ${head.slice(0, 60)} → ${action}`);
  console.log(log[log.length - 1]);
  if (action === 'DONE') break;
  if (!action) { console.log('UNKNOWN STATE\n' + (q || body.slice(-600))); break; }
}
if (OUT) await page.screenshot({ path: OUT, fullPage: false });
const done = log.length > 0 && log[log.length - 1].endsWith('DONE');
await browser.close();
process.exit(done ? 0 : 1);

async function writeModalOther() {
  const box = page.locator('[role=dialog] input, [role=dialog] textarea').last();
  await box.fill('At the gym');
  await page.getByRole('button', { name: /^Done$/ }).last().click();
}
