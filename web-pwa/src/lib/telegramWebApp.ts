/**
 * Thin wrapper around the Telegram Mini App API (window.Telegram.WebApp, loaded by
 * handoff.html from telegram.org). Everything is optional: opened as a plain link (or on
 * another channel) the page simply works without it.
 */
interface TelegramWebApp {
  ready(): void;
  expand(): void;
  close(): void;
  colorScheme?: 'light' | 'dark';
  initData?: string;
}

function webApp(): TelegramWebApp | null {
  const w = window as unknown as { Telegram?: { WebApp?: TelegramWebApp } };
  const app = w.Telegram?.WebApp;
  // The script defines WebApp everywhere; initData is only set inside Telegram.
  return app && app.initData ? app : null;
}

export function isInTelegram(): boolean {
  return webApp() !== null;
}

/** Tell Telegram the page is ready and use the full height (tasks need the space). */
export function initTelegram(): void {
  const app = webApp();
  if (!app) return;
  app.ready();
  app.expand();
  if (app.colorScheme === 'dark') document.documentElement.classList.add('dark');
}

/** Close the Mini App (back to the chat); returns false when not inside Telegram. */
export function closeTelegram(): boolean {
  const app = webApp();
  if (!app) return false;
  app.close();
  return true;
}
