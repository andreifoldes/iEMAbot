/**
 * Render tests for the install UI (InstallPrompt, FindAppHint, InviteCodeNote).
 *
 * Node's test runner can't import .tsx, so the components are loaded through Vite's
 * SSR loader and rendered to static markup against a faked `window` / `navigator`
 * (see lib/fakeBrowser.mts). Each scenario reloads the modules so the install-prompt
 * capture state (module-level in lib/pwaInstall.ts) starts empty.
 *
 * Run with `npm test`.
 */
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createElement, type ComponentType } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createServer, isRunnableDevEnvironment, type ViteDevServer } from 'vite';
import react from '@vitejs/plugin-react';
import {
  fakeInstallPromptEvent,
  installFakeBrowser,
  UA,
  type FakeBrowser,
  type FakeBrowserOptions,
} from '../lib/fakeBrowser.mts';

const ROOT = fileURLToPath(new URL('../../', import.meta.url));
const PAGE_URL = 'https://example.test/pwa/';

let vite: ViteDevServer;
before(async () => {
  vite = await createServer({
    root: ROOT,
    configFile: false,
    plugins: [react()],
    appType: 'custom',
    server: { middlewareMode: true, hmr: false, watch: null },
    optimizeDeps: { noDiscovery: true, include: [] },
    logLevel: 'error',
  });
});
after(async () => {
  await vite.close();
});

/** Drop every evaluated module, so the next load starts with empty module-level state. */
function resetModules(): void {
  const ssr = vite.environments.ssr;
  assert.ok(isRunnableDevEnvironment(ssr), 'SSR environment can run modules in-process');
  ssr.moduleGraph.invalidateAll();
  ssr.runner.clearCache();
}

interface Scenario {
  env: FakeBrowserOptions;
  variant?: 'card' | 'compact';
  /** Runs after the capture is armed, to fire browser events. */
  events?: (win: FakeBrowser['window']) => void;
}

/** Visible text of rendered markup (tags dropped, entities and curly quotes normalised). */
function text(html: string): string {
  return html
    .replace(/<[^>]+>/g, ' ')
    .replace(/&#x27;|&apos;/g, "'")
    .replace(/&quot;/g, '"')
    .replace(/&amp;/g, '&')
    .replace(/[’]/g, "'")
    .replace(/[“”]/g, '"')
    .replace(/\s+/g, ' ')
    .trim();
}

async function renderPrompt({ env, variant = 'card', events }: Scenario): Promise<{ html: string; text: string }> {
  const fake = installFakeBrowser({ href: PAGE_URL, ...env });
  try {
    resetModules();
    const lib = await vite.ssrLoadModule('/src/lib/pwaInstall.ts');
    const { InstallPrompt } = (await vite.ssrLoadModule('/src/components/InstallPrompt.tsx')) as {
      InstallPrompt: ComponentType<{ variant?: 'card' | 'compact' }>;
    };
    lib.initInstallCapture(); // what main.tsx does before React mounts
    events?.(fake.window);
    const html = renderToStaticMarkup(createElement(InstallPrompt, { variant }));
    return { html, text: text(html) };
  } finally {
    fake.restore();
  }
}

const accepted = (win: FakeBrowser['window']) => win.dispatchEvent(fakeInstallPromptEvent('accepted'));
const installed = (win: FakeBrowser['window']) => win.dispatchEvent(new Event('appinstalled'));

describe('InstallPrompt (card) — native install', () => {
  it('Android Chrome with a captured prompt: Install button + find-the-icon hint, no QR', async () => {
    const r = await renderPrompt({ env: { ua: UA.androidChrome }, events: accepted });
    assert.match(r.text, /Install app/);
    assert.match(r.text, /Can't see the app after installing\?/);
    assert.doesNotMatch(r.text, /Use your phone instead/);
  });

  it('desktop Chrome with a captured prompt: Install button, collapsed phone handoff, no icon hint', async () => {
    const r = await renderPrompt({ env: { ua: UA.desktopChrome, platform: 'MacIntel' }, events: accepted });
    assert.match(r.text, /Install app/);
    assert.match(r.text, /Use your phone instead/);
    assert.doesNotMatch(r.text, /Hide QR code/);
    assert.doesNotMatch(r.text, /Can't see the app/);
  });
});

describe('InstallPrompt (card) — iOS', () => {
  it('Safari 16.4+: Safari-worded Share steps and the App Library hint', async () => {
    const r = await renderPrompt({ env: { ua: UA.iphoneSafari164 } });
    assert.match(r.text, /Add this app to your Home Screen/);
    assert.match(r.text, /button at the bottom-right, then Share/);
    assert.match(r.text, /Scroll down and choose Add to Home Screen/);
    assert.match(r.text, /Tap Add in the top-right/);
    assert.match(r.text, /App Library/);
    assert.doesNotMatch(r.text, /View More/);
    assert.doesNotMatch(r.text, /Use your phone instead/); // mobile → no QR handoff
  });

  it('Chrome 113+: Chrome-worded steps (Share by the address bar, View More)', async () => {
    const r = await renderPrompt({ env: { ua: UA.iphoneChrome113 } });
    assert.match(r.text, /Add this app to your Home Screen/);
    assert.match(r.text, /icon to the right of the address bar/);
    assert.match(r.text, /View More/);
    assert.doesNotMatch(r.text, /bottom-right/);
  });

  it('iOS older than 16.4: update-iOS alert instead of steps', async () => {
    const r = await renderPrompt({ env: { ua: UA.iphoneSafari156 } });
    assert.match(r.text, /Update iOS to install the app/);
    assert.match(r.text, /iOS 15\.6/);
    assert.match(r.text, /iOS 16\.4 or later/);
    assert.doesNotMatch(r.text, /Scroll down and choose/);
  });

  it('Chrome older than 113: update-Chrome alert naming the version and the Safari escape', async () => {
    const r = await renderPrompt({ env: { ua: UA.iphoneChrome112 } });
    assert.match(r.text, /Update Chrome to install the app/);
    assert.match(r.text, /\(v112\)/);
    assert.match(r.text, /needs v113\+/);
    assert.match(r.text, /Safari/);
  });

  it('Firefox / Edge on iOS: steered to Safari or Chrome', async () => {
    for (const ua of [UA.iphoneFirefox, UA.iphoneEdge]) {
      const r = await renderPrompt({ env: { ua } });
      assert.match(r.text, /Open this page in Safari or Chrome/, ua);
      assert.doesNotMatch(r.text, /Scroll down and choose/, ua);
    }
  });

  it('iPadOS posing as a Mac still gets the iOS steps (and fails open on the unreadable OS version)', async () => {
    const r = await renderPrompt({
      env: { ua: UA.ipadAsMacSafari, platform: 'MacIntel', maxTouchPoints: 5 },
    });
    assert.match(r.text, /Add this app to your Home Screen/);
  });
});

describe('InstallPrompt (card) — in-app browsers', () => {
  it('iOS web-view: copy-link + open-in-Safari steps, no install steps, no QR', async () => {
    const r = await renderPrompt({ env: { ua: UA.iphoneWhatsApp } });
    assert.match(r.text, /Open this page in your browser/);
    assert.match(r.text, /Copy the link below/);
    assert.match(r.text, /Open Safari \(or Chrome\)/);
    assert.ok(r.text.includes(PAGE_URL), 'shows the page URL to copy');
    assert.doesNotMatch(r.text, /Add this app to your Home Screen/);
  });

  it('Android web-view: "Open in Chrome" menu steps', async () => {
    const r = await renderPrompt({ env: { ua: UA.androidFacebook } });
    assert.match(r.text, /Open this page in your browser/);
    assert.match(r.text, /Open in Chrome/);
    assert.ok(r.text.includes(PAGE_URL));
  });

  it('a captured prompt still wins inside a web-view UA (the browser can evidently install)', async () => {
    const r = await renderPrompt({ env: { ua: UA.androidFacebook }, events: accepted });
    assert.match(r.text, /Install app/);
  });
});

describe('InstallPrompt (card) — desktop and Android without a prompt', () => {
  it('desktop Chromium without a prompt: address-bar guidance + collapsed phone handoff', async () => {
    const r = await renderPrompt({ env: { ua: UA.desktopChrome, platform: 'MacIntel' } });
    assert.match(r.text, /Click the install icon/);
    assert.match(r.text, /Install page as app/);
    assert.match(r.text, /Open in app/);
    assert.match(r.text, /Use your phone instead/);
  });

  it('desktop Safari: Add to Dock', async () => {
    const r = await renderPrompt({ env: { ua: UA.desktopSafari, platform: 'MacIntel' } });
    assert.match(r.text, /Add this app to your Dock/);
    assert.match(r.text, /Add to Dock/);
  });

  it('desktop Firefox: cannot install; phone handoff is open (QR loading, copy link, browser tip)', async () => {
    const r = await renderPrompt({ env: { ua: UA.desktopFirefox, platform: 'MacIntel' } });
    assert.match(r.text, /This browser can't install the app/);
    assert.match(r.text, /Hide QR code/);
    assert.match(r.text, /Scan this code with your phone camera/);
    assert.ok(r.text.includes(PAGE_URL), 'copy-link shows the URL');
    assert.match(r.html, /animate-spin/, 'QR chunk is lazy-loaded behind a spinner fallback');
  });

  it('Android Firefox: browser-menu install + find-the-icon hint', async () => {
    const r = await renderPrompt({ env: { ua: UA.androidFirefox } });
    assert.match(r.text, /Open your browser's menu/);
    assert.match(r.text, /Install app" or "Add to Home screen/);
    assert.match(r.text, /Can't see the app after installing\?/);
  });
});

describe('InstallPrompt (card) — after install', () => {
  it('phone: "Added to your Home Screen" + hint, and the steps are gone', async () => {
    const r = await renderPrompt({ env: { ua: UA.iphoneSafari164 }, events: installed });
    assert.match(r.text, /Added to your Home Screen! Now open iEMAbot from there/);
    assert.match(r.text, /Can't see the app after adding it\?/);
    assert.doesNotMatch(r.text, /Scroll down and choose/);
  });

  it('desktop: "iEMAbot is installed", no Home Screen wording', async () => {
    const r = await renderPrompt({ env: { ua: UA.desktopChrome, platform: 'MacIntel' }, events: installed });
    assert.match(r.text, /iEMAbot is installed/);
    assert.doesNotMatch(r.text, /Home Screen/);
  });

  it('installing clears a captured prompt (no Install button afterwards)', async () => {
    const r = await renderPrompt({
      env: { ua: UA.androidChrome },
      events: (win) => {
        accepted(win);
        installed(win);
      },
    });
    assert.doesNotMatch(r.text, /Install app/);
    assert.match(r.text, /Added to your Home Screen/);
  });
});

describe('InstallPrompt — already running as the installed app', () => {
  it('renders nothing for display-mode: standalone', async () => {
    for (const variant of ['card', 'compact'] as const) {
      const r = await renderPrompt({ env: { ua: UA.androidChrome, displayModeStandalone: true }, variant });
      assert.equal(r.html, '', variant);
    }
  });

  it('renders nothing for iOS navigator.standalone', async () => {
    const r = await renderPrompt({ env: { ua: UA.iphoneSafari164, iosStandalone: true } });
    assert.equal(r.html, '');
  });
});

describe('InstallPrompt (compact header pill)', () => {
  it('shows an Install pill where the app can be installed', async () => {
    const native = await renderPrompt({ env: { ua: UA.androidChrome }, variant: 'compact', events: accepted });
    assert.match(native.html, /aria-label="Install app"/);
    assert.match(native.text, /Install/);
    const ios = await renderPrompt({ env: { ua: UA.iphoneSafari164 }, variant: 'compact' });
    assert.match(ios.html, /aria-expanded="false"/, 'guidance popover starts closed');
  });

  it('renders nothing on browsers that cannot install, or once installed this session', async () => {
    const ff = await renderPrompt({ env: { ua: UA.desktopFirefox, platform: 'MacIntel' }, variant: 'compact' });
    assert.equal(ff.html, '');
    const done = await renderPrompt({ env: { ua: UA.androidChrome }, variant: 'compact', events: installed });
    assert.equal(done.html, '');
  });
});

describe('FindAppHint and InviteCodeNote', () => {
  type Props = Record<string, unknown>;
  async function renderHtml(path: string, name: string, props: Props): Promise<string> {
    const mod = (await vite.ssrLoadModule(path)) as Record<string, ComponentType<Props>>;
    return renderToStaticMarkup(createElement(mod[name], props));
  }
  const hint = (os: string) => renderHtml('/src/components/FindAppHint.tsx', 'FindAppHint', { os }).then(text);

  it('FindAppHint words the advice per OS', async () => {
    const [ios, android, unknown] = await Promise.all([hint('ios'), hint('android'), hint('unknown')]);
    assert.match(ios, /App Library/);
    assert.match(ios, /pull down on the Home Screen/);
    assert.match(android, /swipe up to open your app list/);
    assert.doesNotMatch(android, /App Library/);
    assert.match(unknown, /iPhone/);
    assert.match(unknown, /Android/);
    for (const t of [ios, android, unknown]) assert.match(t, /iEMAbot/);
  });

  it('InviteCodeNote shows the code to write down, and nothing when there is none', async () => {
    const shown = text(await renderHtml('/src/components/InviteCodeNote.tsx', 'InviteCodeNote', { code: 'SSRC-42' }));
    assert.match(shown, /Write this down/);
    assert.match(shown, /SSRC-42/);
    assert.equal(await renderHtml('/src/components/InviteCodeNote.tsx', 'InviteCodeNote', { code: '' }), '');
  });

  it('InstallQr renders a full-size, scannable code with the 4-module quiet zone', async () => {
    const html = await renderHtml('/src/components/InstallQr.tsx', 'default', { url: PAGE_URL });
    // The <svg> is role="img" (set by qrcode.react) so it must carry its own accessible name.
    assert.match(html, /<svg[^>]*role="img"/);
    assert.match(html, /<title>QR code for this page<\/title>/);
    assert.match(html, /<svg[^>]*\bwidth="176"/);
    assert.match(html, /<svg[^>]*\bheight="176"/);
    // viewBox side = QR modules (4·version + 17) + 2 × quiet zone (4 modules).
    const side = Number(/viewBox="0 0 (\d+) \d+"/.exec(html)?.[1]);
    assert.ok(Number.isInteger(side), 'has a square viewBox');
    const modules = side - 2 * 4;
    assert.equal((modules - 17) % 4, 0, `${modules} modules is a valid QR size`);
    assert.match(html, /fill="#ffffff"/, 'white background so the quiet zone is light in dark mode');
  });
});
