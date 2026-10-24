/**
 * Render tests for the "smartphone only" invite page (PhoneOnlyInvite) and the
 * enlargeable QR code it uses (EnlargeableQr / QrDialog).
 *
 * Same approach as installPrompt.render.test.mts: components are loaded through Vite's
 * SSR loader and rendered to static markup. Run with `npm test`.
 */
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createElement, type ComponentType } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createServer, type ViteDevServer } from 'vite';
import react from '@vitejs/plugin-react';
import { installFakeBrowser, UA } from '../lib/fakeBrowser.mts';

const ROOT = fileURLToPath(new URL('../../', import.meta.url));
const INVITE_URL = 'https://example.test/pwa/?key=demo&pid=1155';

let vite: ViteDevServer;
before(async () => {
  vite = await createServer({
    root: ROOT,
    configFile: false,
    plugins: [react()],
    appType: 'custom',
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    optimizeDeps: { noDiscovery: true, include: [] },
    logLevel: 'error',
  });
});
after(async () => {
  await vite.close();
});

/** Visible text of rendered markup (tags dropped, entities and curly quotes normalised). */
function text(html: string): string {
  return html
    .replace(/<[^>]+>/g, ' ')
    .replace(/&#x27;|&apos;/g, "'")
    .replace(/&quot;/g, '"')
    .replace(/&amp;/g, '&')
    .replace(/[’]/g, "'")
    .replace(/\s+/g, ' ')
    .trim();
}

async function renderHtml(path: string, exportName: string, props: object): Promise<string> {
  const fake = installFakeBrowser({ ua: UA.desktopChrome, href: INVITE_URL });
  try {
    const mod = (await vite.ssrLoadModule(path)) as Record<string, ComponentType<object>>;
    return renderToStaticMarkup(createElement(mod[exportName], props));
  } finally {
    fake.restore();
  }
}

const invite = (props: object = {}) =>
  renderHtml('/src/components/PhoneOnlyInvite.tsx', 'PhoneOnlyInvite', {
    studyTitle: 'Sleep & Mood Study',
    url: INVITE_URL,
    code: 'demo',
    participantId: '1155',
    canContinueHere: false,
    onContinueHere: () => undefined,
    ...props,
  });

describe('PhoneOnlyInvite', () => {
  it('says what to do: this is a smartphone study, open the link on your phone', async () => {
    const t = text(await invite());
    assert.match(t, /Open this study on your phone/);
    assert.match(t, /Sleep & Mood Study is a smartphone study/);
    assert.match(t, /This device isn't a phone, so open the link on your smartphone/);
  });

  it('falls back to generic wording when the study has no title', async () => {
    assert.match(text(await invite({ studyTitle: '' })), /This is a smartphone study/);
  });

  it('asks the participant to write down the invite code and participant ID (iOS keeps neither across the install)', async () => {
    const t = text(await invite());
    assert.match(t, /Write these down — you might need them:/);
    assert.match(t, /Invite code\s*demo/);
    assert.match(t, /Participant ID\s*1155/);
  });

  it('writes down just the code for a plain invite link, and nothing when there is nothing', async () => {
    const plain = text(await invite({ participantId: '' }));
    assert.match(plain, /Write this down — you might need it:/);
    assert.doesNotMatch(plain, /Participant ID/);
    assert.doesNotMatch(text(await invite({ code: '', participantId: '' })), /Write th/);
  });

  it('puts the QR code first and the alternatives underneath it, then the tip', async () => {
    const t = text(await invite({ canContinueHere: true }));
    const at = (s: string) => {
      const i = t.indexOf(s);
      assert.ok(i >= 0, `missing: ${s}`);
      return i;
    };
    const qr = at('Scan with your phone camera, or tap the code to enlarge it.');
    const others = at('Other ways to open it');
    const copy = at('Copy the link to send it to yourself');
    const hatch = at("continue here");
    const tip = at('Tip: open it in');
    assert.ok(qr < others && others < copy && copy < hatch && hatch < tip, 'QR, then alternatives, then the tip');
  });

  it('renders the QR code as an "Enlarge" button, lazy-loaded behind a spinner', async () => {
    const html = await invite();
    assert.match(html, /<button[^>]*aria-label="Enlarge QR code"/);
    assert.match(html, /aria-haspopup="dialog"/);
    assert.match(html, /animate-spin/, 'QR chunk is lazy-loaded behind a spinner fallback');
    assert.doesNotMatch(html, /role="dialog"/, 'the enlarged view starts closed');
  });

  it('shows the full invite link (code and participant ID included) with a copy button and its own live region', async () => {
    const html = await invite();
    assert.ok(text(html).includes(INVITE_URL), 'the link the QR code carries is shown, selectable');
    assert.match(text(html), /Copy link/);
    assert.match(html, /role="status"/, 'copy confirmation is announced');
  });

  it('ends with the browser tip, worded exactly, even when "continue here" is offered', async () => {
    const tip = 'Tip: open it in Safari or Chrome (iPhone), or Chrome (Android).';
    assert.ok(text(await invite()).endsWith(tip), 'last line without the escape hatch');
    assert.ok(text(await invite({ canContinueHere: true })).endsWith(tip), 'last line with the escape hatch');
  });

  it('offers "continue here" only to touch devices that might be misread (tablets, foldables)', async () => {
    assert.doesNotMatch(text(await invite({ canContinueHere: false })), /continue here/);
    assert.match(text(await invite({ canContinueHere: true })), /I'm on my phone — continue here/);
  });
});

describe('QrDialog (enlarged QR code)', () => {
  it('is a modal dialog with an accessible name and a labelled close button', async () => {
    const html = await renderHtml('/src/components/EnlargeableQr.tsx', 'QrDialog', {
      url: INVITE_URL,
      onClose: () => undefined,
    });
    assert.match(html, /role="dialog"/);
    assert.match(html, /aria-modal="true"/);
    assert.match(html, /aria-label="QR code, enlarged"/);
    assert.match(html, /<button[^>]*aria-label="Close enlarged QR code"/);
  });
});

describe('InviteCodeNote', () => {
  const note = (props: object) => renderHtml('/src/components/InviteCodeNote.tsx', 'InviteCodeNote', props);

  it('lists the invite code on its own for a plain link', async () => {
    const t = text(await note({ code: 'SSRC-42' }));
    assert.match(t, /Write this down — you might need it:/);
    assert.match(t, /Invite code\s*SSRC-42/);
    assert.doesNotMatch(t, /Participant ID/);
  });

  it('adds the participant ID that a personalised link carried', async () => {
    const t = text(await note({ code: 'SSRC-42', participantId: 'P007' }));
    assert.match(t, /Write these down — you might need them:/);
    assert.match(t, /Invite code\s*SSRC-42/);
    assert.match(t, /Participant ID\s*P007/);
  });

  it('renders nothing when there is nothing to write down', async () => {
    assert.equal(await note({ code: '' }), '');
    assert.equal(await note({ code: '', participantId: '' }), '');
  });
});
