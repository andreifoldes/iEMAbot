/// <reference types="vite/client" />
/// <reference types="vite-plugin-pwa/client" />

/** Injected at build time from package.json (see vite.config.ts `define`). */
declare const __APP_VERSION__: string;

/** Participant-app text from `src/locales/pwa/*.json` (see vite-plugin-pwa-locales.ts). */
declare module 'virtual:pwa-locales' {
  export const en: Record<string, string>;
  /** Languages whose text is machine-translated and not reviewed yet. */
  export const machine: string[];
  export const loaders: Record<string, () => Promise<Record<string, string>>>;
}
