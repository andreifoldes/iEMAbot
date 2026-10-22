/**
 * Runtime platform detection. Deliberately does not import @capacitor/core so the web
 * bundle carries no native dependency: inside the Android app Capacitor injects
 * `window.Capacitor` before the page scripts run.
 */

interface CapacitorGlobal {
  isNativePlatform?: () => boolean;
}

export function isNativeApp(): boolean {
  try {
    const cap = (globalThis as { Capacitor?: CapacitorGlobal }).Capacitor;
    return cap?.isNativePlatform?.() === true;
  } catch {
    return false;
  }
}

/** `appType` reported to the server with every dataset/event (see CreateDataSet statistics). */
export function clientAppType(): 'Web' | 'AndroidApp' {
  return isNativeApp() ? 'AndroidApp' : 'Web';
}
