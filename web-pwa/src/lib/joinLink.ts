/**
 * Invite deep link for the native app: `esmira://join?server=<url>&key=<access key>&pid=<id>`.
 * A QR code or a link on the invite page carries the server (the generic APK has no built-in
 * one), the study access key and, optionally, the participant ID. Opening it stores the server
 * and starts the normal load flow, the same as opening a web invite link.
 */
import { enrollUrl } from './invite.ts';
import { isNativeApp } from './platform.ts';
import { t } from './i18n.ts';
import { getStoredServerBase, isSecureOrLocalServer, normalizeServerBase, setServerBase } from './serverRoot.ts';

export interface JoinLink {
  /** Normalised server root (ends in "/"), when the link names one. */
  server: string | null;
  key: string;
  pid: string;
}

/**
 * Parse a join link; null when it is not a well-formed `esmira://join` link with a key.
 * Matches on the raw string rather than `new URL()`: for a custom scheme, Chromium (the Android
 * WebView) reports an empty hostname and puts "//join" in the pathname, while Node and other
 * engines report host "join", so URL's host/path split cannot be relied on.
 */
export function parseJoinLink(raw: string): JoinLink | null {
  const m = /^esmira:\/\/join\/?(?:\?([^#]*))?(?:#.*)?$/i.exec(raw.trim());
  if (!m) return null;
  const params = new URLSearchParams(m[1] ?? '');
  const key = (params.get('key') ?? '').trim();
  if (!key) return null;
  const serverParam = params.get('server');
  const server = serverParam ? normalizeServerBase(serverParam) : null;
  if (serverParam && !server) return null; // a server was given but is unusable: do not guess
  if (server && !isSecureOrLocalServer(server)) return null; // never send answers to a remote server in clear text
  const pid = (params.get('pid') ?? params.get('uid') ?? '').trim();
  return { server, key, pid };
}

/**
 * Whether the participant has to confirm a link before the app follows it. A link can come from any web page or QR
 * code and names the server that will receive the participant's answers, so a server the app is not already using
 * always needs a yes: 'new' when none is stored yet, 'switch' when it would replace another one.
 */
export function joinNeedsConfirmation(link: JoinLink, currentServer: string | null): 'none' | 'new' | 'switch' {
  if (!link.server || link.server === currentServer) return 'none';
  return currentServer ? 'switch' : 'new';
}

/** Where the app should go for a parsed link (after storing its server). */
export function joinTarget(link: JoinLink): string {
  if (link.server) setServerBase(link.server);
  return enrollUrl('/', link.key, link.pid);
}

interface AppPlugin {
  getLaunchUrl(): Promise<{ url?: string } | undefined>;
  addListener(event: 'appUrlOpen', cb: (e: { url: string }) => void): unknown;
}

/** Native only: act on the link that launched the app and on links opened while it runs. */
// TODO: LIVE CHECK V16 (needs a phone with the app): open an esmira://join link for a new server (dialog names it; cancel does nothing,
// accept loads the invitation), and one for another server than the current one (dialog says "change"). Tracker Part 1.
export function initJoinLinks(): void {
  if (!isNativeApp()) return;
  // Capacitor's injected global exposes native plugins as `Capacitor.Plugins.<Name>` (it has no
  // `registerPlugin`; that lives in @capacitor/core, which the web bundle deliberately avoids).
  const cap = (globalThis as { Capacitor?: { Plugins?: Record<string, unknown> } }).Capacitor;
  const app = cap?.Plugins?.App as AppPlugin | undefined;
  if (!app) return;
  const open = (raw: string | undefined) => {
    const link = raw ? parseJoinLink(raw) : null;
    if (!link) return;
    const current = getStoredServerBase();
    const need = joinNeedsConfirmation(link, current);
    if (need !== 'none' && link.server) {
      // The server name is shown as the browser would show it, so a look-alike host is visible to the participant.
      const text = need === 'switch'
        ? t('pwa_join_confirm_switch', new URL(current as string).host, new URL(link.server).host)
        : t('pwa_join_confirm_new', new URL(link.server).host);
      if (!window.confirm(text)) return;
    }
    window.location.assign(joinTarget(link));
  };
  void app.getLaunchUrl().then((r) => open(r?.url)).catch(() => {});
  app.addListener('appUrlOpen', (e) => open(e.url));
}
