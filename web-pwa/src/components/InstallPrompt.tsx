/**
 * "Install app" affordance with browser-aware, tailored instructions.
 *
 * PWA install works differently in every browser, so we detect the environment
 * (see lib/pwaInstall.ts) and show the right guidance:
 *   - `native`       — Chromium (Android/desktop Chrome, Edge, Samsung…) fires
 *                      `beforeinstallprompt`; we capture it at boot and drive a
 *                      real "Install app" button.
 *   - `in-app`       — a WhatsApp/Instagram/Mail… web-view, which can't install
 *                      anything; the participant must reopen the link in a real
 *                      browser first.
 *   - `ios`          — iOS/iPadOS never fires that event; installing means
 *                      Share → "Add to Home Screen". Safari and Chrome (v113+)
 *                      both work from iOS 16.4; older OS / Chrome versions and
 *                      other browsers get an explanatory alert instead.
 *   - `macos-safari` — desktop Safari installs via File → "Add to Dock".
 *   - `android`      — Android browser without a captured prompt (e.g. Firefox);
 *                      install lives in the browser menu.
 *   - `desktop-chromium` — desktop Chrome/Edge/Brave that CAN install but hasn't
 *                      handed us a `beforeinstallprompt` (already installed once,
 *                      or engagement heuristics not yet met); install lives in the
 *                      address bar / browser menu.
 *   - `unsupported`  — desktop Firefox and others with no PWA install; the only
 *                      way forward is a phone, or Chrome, Edge or Safari.
 *
 * Once the app is installed this session (`appinstalled`) but the tab hasn't
 * become the installed app, the card variant says "now open it from there".
 * Renders nothing once the app is running standalone.
 *
 * Two layouts: `compact` (a header pill that reveals the guidance in a popover)
 * and `card` (full-width, always-visible guidance for the invite-code screen,
 * with a "use your phone instead" QR code on desktops).
 */
import { lazy, ReactNode, Suspense, useState } from 'react';
import {
  AlertTriangle,
  Check,
  ChevronDown,
  Copy,
  Download,
  ExternalLink,
  Globe,
  MoreHorizontal,
  MoreVertical,
  Plus,
  Share,
  Smartphone,
  X,
} from 'lucide-react';
import { cn } from '../lib/utils';
import {
  CHROME_IOS_MIN,
  IOS_PUSH_MIN,
  classifyInstallPlatform,
  findAppHintOS,
  usePwaInstall,
  type HintOS,
  type IOSBrowser,
  type IOSSupport,
  type InstallPlatform,
  type PwaInstall,
} from '../lib/pwaInstall';
import { FindAppHint } from './FindAppHint';

const InstallQr = lazy(() => import('./InstallQr'));

export function InstallPrompt({
  variant = 'compact',
  className,
}: {
  variant?: 'compact' | 'card';
  className?: string;
}) {
  const install = usePwaInstall();
  const [showHint, setShowHint] = useState(false);

  if (install.standalone) return null;

  const platform = classifyInstallPlatform(install);
  const runNativePrompt = () => void install.promptInstall();

  // ── Card: full-width guidance for the invite-code / onboarding screens ──
  if (variant === 'card') {
    // Installed this session, but this tab isn't the app: point them at it.
    if (install.installed) return <InstalledNotice install={install} className={className} />;
    return (
      <div className={cn('w-full flex flex-col gap-3', className)}>
        {platform === 'native' ? (
          <>
            <button
              onClick={runNativePrompt}
              className="w-full inline-flex items-center justify-center gap-2 bg-secondary-container text-on-secondary-container font-semibold py-3 rounded-full active:scale-95 hover:brightness-95 transition-all"
            >
              <Download size={18} aria-hidden="true" />
              Install app
            </button>
            {!install.desktop && <FindAppHint os={findAppHintOS(install.ios)} />}
          </>
        ) : (
          <InstallInstructions platform={platform} install={install} />
        )}
        {install.desktop && platform !== 'in-app' && <PhoneHandoff defaultOpen={platform === 'unsupported'} />}
      </div>
    );
  }

  // ── Compact: a small header pill. Nothing actionable → render nothing. ──
  if (platform === 'unsupported' || install.installed) return null;

  return (
    <div className={cn('relative shrink-0', className)}>
      <button
        onClick={platform === 'native' ? runNativePrompt : () => setShowHint((v) => !v)}
        aria-label="Install app"
        aria-expanded={platform === 'native' ? undefined : showHint}
        className="inline-flex items-center gap-1.5 bg-white/20 hover:bg-white/30 text-white font-semibold text-xs px-3 py-1.5 rounded-full active:scale-95 transition-all"
      >
        {platform === 'native' ? <Download size={14} aria-hidden="true" /> : <Share size={14} aria-hidden="true" />}
        <span>Install</span>
      </button>
      {platform !== 'native' && showHint && (
        <div className="absolute right-0 top-full mt-2 w-72 z-50 text-on-surface">
          <InstallInstructions platform={platform} install={install} onClose={() => setShowHint(false)} />
        </div>
      )}
    </div>
  );
}

/** Shown after `appinstalled`: the tab is still a browser tab, so say where the app went. */
function InstalledNotice({ install, className }: { install: PwaInstall; className?: string }) {
  return (
    <div className={cn('w-full flex flex-col gap-2', className)}>
      <p className="flex items-start gap-2 text-sm text-on-surface leading-relaxed">
        <Check size={16} className="shrink-0 mt-0.5 text-primary" aria-hidden="true" />
        {install.desktop ? (
          <span>
            iEMAbot is installed. Click <strong>Open in app</strong> at the right end of the address
            bar (or open it from your Dock or app launcher), then enter your invite code.
          </span>
        ) : (
          <span>
            Added to your Home Screen! Now open <strong>iEMAbot</strong> from there and enter your
            invite code.
          </span>
        )}
      </p>
      {!install.desktop && <FindAppHint os={findAppHintOS(install.ios)} />}
    </div>
  );
}

const SHARE_ICON_CLASS = 'font-semibold text-on-surface inline-flex items-center gap-1 align-bottom';

/** Inline bold label with a trailing icon, e.g. “Share ⬆”. */
function Key({ label, icon }: { label: string; icon: ReactNode }) {
  return (
    <span className={SHARE_ICON_CLASS}>
      {label} {icon}
    </span>
  );
}

/** Numbered "Add to Home Screen" steps, worded for Safari or Chrome on iOS. */
function iosSteps(browser: IOSBrowser): ReactNode[] {
  const isChrome = browser === 'chrome';
  return [
    isChrome ? (
      <>
        Tap the <Key label="Share" icon={<Share size={14} aria-hidden="true" />} /> icon to the right
        of the address bar.
      </>
    ) : (
      <>
        Tap the{' '}
        <span className={SHARE_ICON_CLASS}>
          <MoreHorizontal size={14} aria-label="More (•••)" />
        </span>{' '}
        button at the bottom-right, then{' '}
        <Key label="Share" icon={<Share size={14} aria-hidden="true" />} /> (on older iPhones, Share
        is right in Safari’s toolbar).
      </>
    ),
    <>
      {isChrome ? (
        <>
          Tap <Key label="View More" icon={<ChevronDown size={14} aria-hidden="true" />} />, then scroll
          down and choose
        </>
      ) : (
        'Scroll down and choose'
      )}{' '}
      <Key label="Add to Home Screen" icon={<Plus size={14} aria-hidden="true" />} />.
    </>,
    <>
      Tap <strong className="text-on-surface">Add</strong> in the top-right.
    </>,
    'Open the app from your Home Screen, then enter your invite code.',
  ];
}

interface Instruction {
  icon: typeof Share;
  title: string;
  steps: ReactNode[];
  /** Amber alert styling: the device can't install as things stand. */
  warn?: boolean;
  /** Extra block under the steps. */
  extra?: ReactNode;
  /** Trailing find-the-icon hint, for flows that end on a Home Screen icon. */
  hint?: HintOS;
}

/** Why this iOS device/browser can't install, with the fix. */
function iosBlockedContent(support: IOSSupport): Instruction | null {
  const { blockReason, version, browserVersion } = support;
  switch (blockReason) {
    case 'os-outdated': {
      const current = version ? `${version.major}.${version.minor}` : 'an older version';
      return {
        icon: AlertTriangle,
        warn: true,
        title: 'Update iOS to install the app',
        steps: [
          <>
            This device is running iOS {current}. Update to{' '}
            <strong className="text-on-surface">
              iOS {IOS_PUSH_MIN.major}.{IOS_PUSH_MIN.minor} or later
            </strong>{' '}
            (Settings → General → Software Update) to install iEMAbot and receive reminders.
          </>,
        ],
      };
    }
    case 'browser-outdated':
      return {
        icon: AlertTriangle,
        warn: true,
        title: 'Update Chrome to install the app',
        steps: [
          <>
            Your Chrome{browserVersion ? ` (v${browserVersion})` : ''} is too old to add apps to the
            Home Screen (needs v{CHROME_IOS_MIN}+). Update Chrome in the App Store, or open this page
            in <strong className="text-on-surface">Safari</strong>.
          </>,
        ],
      };
    case 'unsupported-browser':
      return {
        icon: AlertTriangle,
        warn: true,
        title: 'Open this page in Safari or Chrome',
        steps: [
          <>
            On iPhone and iPad, only <strong className="text-on-surface">Safari</strong> and{' '}
            <strong className="text-on-surface">Chrome</strong> can add iEMAbot to your Home Screen.
          </>,
        ],
      };
    default:
      return null;
  }
}

function instructionFor(platform: Exclude<InstallPlatform, 'native'>, install: PwaInstall): Instruction {
  switch (platform) {
    case 'ios': {
      const blocked = iosBlockedContent(install.iosSupport);
      if (blocked) return blocked;
      return {
        icon: Share,
        title: 'Add this app to your Home Screen',
        steps: iosSteps(install.iosSupport.browser),
        hint: 'ios',
      };
    }
    case 'in-app':
      return {
        icon: Globe,
        warn: true,
        title: 'Open this page in your browser',
        steps: install.ios
          ? [
              'Copy the link below.',
              <>
                Open <strong className="text-on-surface">Safari</strong> (or Chrome) and paste it into
                the address bar.
              </>,
            ]
          : [
              <>
                Tap the <strong className="text-on-surface">⋮</strong> menu (top-right).
              </>,
              <>
                Choose <strong className="text-on-surface">“Open in Chrome”</strong> (or “Open in
                browser”).
              </>,
            ],
        extra: <CopyLink />,
      };
    case 'macos-safari':
      return {
        icon: Download,
        title: 'Add this app to your Dock',
        steps: [
          'In Safari’s menu bar, choose File → “Add to Dock” (or the Share button → “Add to Dock”).',
          'Open the app from your Dock, then enter your invite code.',
        ],
      };
    case 'android':
      return {
        icon: MoreVertical,
        title: 'Install this app',
        steps: [
          'Open your browser’s menu (⋮).',
          'Tap “Install app” or “Add to Home screen”.',
          'Open the app from your Home Screen, then enter your invite code.',
        ],
        hint: 'android',
      };
    case 'desktop-chromium':
      return {
        icon: Download,
        title: 'Install this app',
        steps: [
          'Click the install icon (a screen with a down-arrow, or ⊕) at the right end of the address bar — or open the browser menu (⋮) → “Save and Share” → “Install page as app…”. If the address bar shows “Open in app” instead, it is already installed: click that.',
          'Open the installed app, then enter your invite code.',
        ],
      };
    case 'unsupported':
      return {
        icon: ExternalLink,
        title: 'This browser can’t install the app',
        steps: ['Open this page in Chrome, Edge or Safari to install the app and continue.'],
        extra: <CopyLink />,
      };
  }
}

/** Tailored, browser-specific "how to install" panel. */
function InstallInstructions({
  platform,
  install,
  className,
  onClose,
}: {
  platform: Exclude<InstallPlatform, 'native'>;
  install: PwaInstall;
  className?: string;
  onClose?: () => void;
}) {
  const content = instructionFor(platform, install);
  const Icon = content.icon;
  return (
    <div
      className={cn(
        'bg-white dark:bg-surface-container-lowest border rounded-xl shadow-lg p-4 text-sm text-on-surface',
        content.warn
          ? 'border-amber-300 dark:border-amber-500/40'
          : 'border-slate-200 dark:border-outline-variant/30',
        className,
      )}
    >
      <div className="flex items-start gap-3">
        <span
          className={cn(
            'p-1.5 rounded-full shrink-0',
            content.warn
              ? 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200'
              : 'bg-secondary-container text-on-secondary-container',
          )}
        >
          <Icon size={16} aria-hidden="true" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="font-semibold leading-snug">{content.title}</p>
          <ol className="mt-1.5 flex flex-col gap-1.5 text-on-surface-variant leading-relaxed">
            {content.steps.map((step, i) => (
              <li key={i} className="flex gap-2">
                {content.steps.length > 1 && (
                  <span className="font-semibold text-on-surface shrink-0">{i + 1}.</span>
                )}
                <span className="min-w-0">{step}</span>
              </li>
            ))}
          </ol>
          {content.extra}
          {content.hint && (
            <div className="mt-3">
              <FindAppHint os={content.hint} />
            </div>
          )}
        </div>
        {onClose && (
          <button onClick={onClose} aria-label="Dismiss" className="shrink-0 text-on-surface-variant">
            <X size={16} aria-hidden="true" />
          </button>
        )}
      </div>
    </div>
  );
}

/** "Copy link" row for browsers that can't install — so participants can paste
 *  the URL into a real browser, or onto their phone. */
function CopyLink() {
  const [copied, setCopied] = useState(false);
  const url = window.location.href;
  const onCopy = async () => {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      /* clipboard blocked — the URL is shown below regardless */
    }
  };
  return (
    <button
      onClick={onCopy}
      className="mt-2.5 w-full inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-container-high dark:bg-surface-container-highest text-on-surface font-medium text-xs active:scale-95 transition-all"
    >
      {copied ? <Check size={14} aria-hidden="true" /> : <Copy size={14} aria-hidden="true" />}
      <span className="truncate">{copied ? 'Link copied' : url}</span>
    </button>
  );
}

/**
 * Desktop → phone handoff: reminders and the diary are phone-first, and the invite
 * link usually lands on a computer first. A QR code (plus copy link) lets the
 * participant carry on from their phone. Collapsed by default where the desktop
 * browser can install by itself; open by default where it can't.
 */
function PhoneHandoff({ defaultOpen }: { defaultOpen: boolean }) {
  const [open, setOpen] = useState(defaultOpen);
  const url = window.location.href.split('#')[0];
  return (
    <div className="w-full text-center">
      <button
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="inline-flex items-center gap-1.5 text-xs font-semibold text-on-surface-variant hover:text-on-surface underline underline-offset-2 transition-colors"
      >
        <Smartphone size={14} aria-hidden="true" />
        {open ? 'Hide QR code' : 'Use your phone instead'}
      </button>
      {open && (
        <div className="mt-3 flex flex-col items-center gap-2">
          <p className="text-xs text-on-surface-variant leading-relaxed">
            iEMAbot works best on a phone. Scan this code with your phone camera to continue there, or
            copy the link below.
          </p>
          <Suspense
            fallback={
              <div className="w-[176px] h-[176px] flex items-center justify-center">
                <div className="w-6 h-6 border-2 border-primary border-t-transparent rounded-full animate-spin" />
              </div>
            }
          >
            <InstallQr url={url} />
          </Suspense>
          <div className="w-full">
            <CopyLink />
          </div>
          <p className="text-[0.75rem] text-on-surface-variant leading-relaxed">
            Open it in <strong>Safari</strong> or <strong>Chrome</strong> (iPhone), or{' '}
            <strong>Chrome</strong> (Android).
          </p>
        </div>
      )}
    </div>
  );
}
