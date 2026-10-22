/**
 * Native (Capacitor) build only: stands in for `virtual:pwa-register`, which exists only when
 * vite-plugin-pwa is active. The Android WebView ships the app bundle inside the APK, so there is
 * no service worker to register or update. See the `native` mode in vite.config.ts.
 */
export function registerSW(_options?: { immediate?: boolean }): (reload?: boolean) => Promise<void> {
  return async () => {};
}
