import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { readFileSync, readdirSync } from 'node:fs';
import path from 'path';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

// In production the participant PWA is served at the short path /pwa/ (Caddy routes
// /pwa/* to the ESMira container). The ESMira backend stays under /esmira/, so the API
// root is pinned to /esmira/ in esmiraApi.ts rather than derived from this base.
// In dev, all /esmira/api/* calls are proxied to a running ESMira instance; override
// the target with ESMIRA_PROXY (e.g. a local Docker container at http://localhost:8081).
const ESMIRA = process.env.ESMIRA_PROXY || 'https://iemabot.surrey.ac.uk';

// Surface the release version to the app (shown on the About ESMira screen).
// Read from the ROOT package.json — bump it in the PR that ships a change
// (chore(release): vX.Y.Z), unlike web-pwa's own package.json which never moves.
const pkg = JSON.parse(readFileSync(path.resolve(__dirname, '..', 'package.json'), 'utf-8')) as { version: string };

// `vite build --mode native` builds the bundle shipped inside the Capacitor Android app
// (android-app/): relative base, no service worker/manifest (the WebView is not a PWA host and
// the API root is a runtime setting, see src/lib/serverRoot.ts) and no Telegram handoff page.
export default defineConfig(({ mode }) => {
const native = mode === 'native';
return {
  base: native ? './' : '/pwa/',
  define: {
    __APP_VERSION__: JSON.stringify(pkg.version),
    // Native bundle only: files from public/ that ship inside the APK (see lib/bundledAssets.ts).
    __BUNDLED_PUBLIC__: JSON.stringify(native ? readdirSync(path.resolve(__dirname, 'public')) : []),
  },
  plugins: [
    react(),
    tailwindcss(),
    ...(native ? [] : [VitePWA({
      registerType: 'autoUpdate',
      // injectManifest: we own the service worker (src/sw.ts) so it can handle
      // `push` / `notificationclick` for web-push reminders, while still
      // precaching the app shell and keeping the runtime caches.
      strategies: 'injectManifest',
      srcDir: 'src',
      filename: 'sw.ts',
      // Static files (copied from public/) that the SW should precache too.
      includeAssets: ['favicon.ico', 'apple-touch-icon-180x180.png', 'esmira-logo.svg'],
      manifest: {
        // id/scope/start_url are all the served sub-path so the installed app is
        // isolated from ESMira's admin UI at /esmira/. start_url carries no ?key=
        // (the manifest can't); App.tsx restores the last study from localStorage.
        id: '/pwa/',
        scope: '/pwa/',
        start_url: '/pwa/',
        name: 'iEMAbot',
        short_name: 'iEMAbot',
        description: 'Take part in your iEMAbot study.',
        display: 'standalone',
        orientation: 'portrait',
        theme_color: '#075E54',
        background_color: '#075E54',
        icons: [
          { src: 'pwa-192x192.png', sizes: '192x192', type: 'image/png' },
          { src: 'pwa-512x512.png', sizes: '512x512', type: 'image/png' },
          {
            src: 'maskable-icon-512x512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable',
          },
        ],
      },
      injectManifest: {
        // Files to precache (the runtime caches/routes live in src/sw.ts).
        globPatterns: ['**/*.{js,css,html,svg,png,jpg,ico,woff2}'],
      },
      // Exercise the service worker under `npm run dev` (which proxies /esmira/api).
      devOptions: { enabled: true, type: 'module' },
    })]),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
      ...(native ? { 'virtual:pwa-register': path.resolve(__dirname, 'src/native/pwaRegisterStub.ts') } : {}),
    },
  },
  build: {
    // Output into the served tree at <repo>/dist/pwa so Apache can serve it
    // alongside ESMira's own dist/. Kept out of ESMira's webpack build.
    outDir: path.resolve(__dirname, '..', 'dist', native ? 'pwa-native' : 'pwa'),
    emptyOutDir: true,
    rollupOptions: {
      // handoff.html: single-item page opened from chat channels (Telegram Mini App) for
      // items a chat cannot render — see src/handoff/ and backend/channels/handoff/.
      input: {
        main: path.resolve(__dirname, 'index.html'),
        ...(native ? {} : { handoff: path.resolve(__dirname, 'handoff.html') }),
      },
    },
  },
  server: {
    port: 5174,
    proxy: {
      '/esmira/api': {
        target: ESMIRA,
        changeOrigin: true,
        secure: false,
      },
    },
  },
  // `vite preview` serves the production build (dist/pwa). It needs the same API
  // proxy as dev so a preview — and the accessibility audit that drives it
  // (see a11y/) — can load real studies through /esmira/api.
  preview: {
    port: 4318,
    proxy: {
      '/esmira/api': {
        target: ESMIRA,
        changeOrigin: true,
        secure: false,
      },
    },
  },
};
});
