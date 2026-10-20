/**
 * Browser-side web-push helpers.
 *
 * The flow: confirm support → ensure notification permission → get (or create) a
 * PushManager subscription against the server's VAPID public key. The resulting
 * `PushSubscription` is then POSTed to the backend via `subscribeToPush` so the
 * server scheduler can deliver reminders while the app is closed.
 *
 * Note: actual delivery only works over HTTPS (or localhost) and, on iOS, only
 * for a PWA installed to the Home Screen (iOS 16.4+).
 */

/** True when this browser can register a web-push subscription. */
export function isPushSupported(): boolean {
  return (
    typeof navigator !== 'undefined' &&
    'serviceWorker' in navigator &&
    typeof window !== 'undefined' &&
    'PushManager' in window &&
    'Notification' in window
  );
}

/** Convert a base64url VAPID public key to the Uint8Array `applicationServerKey` wants. */
export function urlBase64ToUint8Array(base64String: string): Uint8Array {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(base64);
  const output = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; ++i) output[i] = raw.charCodeAt(i);
  return output;
}

/**
 * navigator.serviceWorker.ready, but rejecting after `ms` so callers never hang forever
 * if the service worker fails to register/activate (e.g. a dev build, private browsing,
 * or a browser/enterprise policy blocking SW registration). Without this, the onboarding
 * "Enable notifications" action would await a promise that never settles and stick on
 * "Enabling…". On rejection the caller treats push as unavailable and continues.
 */
function serviceWorkerReady(ms = 8000): Promise<ServiceWorkerRegistration> {
  return Promise.race([
    navigator.serviceWorker.ready,
    new Promise<never>((_, reject) =>
      setTimeout(() => reject(new Error('Service worker did not become ready')), ms)),
  ]);
}

/** Byte-compare an existing subscription's applicationServerKey to the expected VAPID key. */
function sameServerKey(existing: ArrayBuffer | null | undefined, wanted: Uint8Array): boolean {
  if (!existing) return false;
  const a = new Uint8Array(existing);
  if (a.length !== wanted.length) return false;
  for (let i = 0; i < a.length; i++) if (a[i] !== wanted[i]) return false;
  return true;
}

/**
 * Get the existing push subscription for the active service worker, or create one
 * bound to `vapidPublicKey`. Returns the subscription serialized as JSON, ready to
 * send to the backend. Throws if push is unsupported or the SW isn't ready.
 *
 * Self-healing: if the browser already holds a subscription bound to a *different*
 * applicationServerKey than the server's current VAPID key (e.g. it was created under
 * an older/rotated keypair, or on localhost during testing), every server push to it
 * is silently rejected by the push service. We detect that mismatch and drop + recreate
 * the subscription against the current key, so reminders actually get delivered.
 */
export async function ensurePushSubscription(vapidPublicKey: string): Promise<PushSubscriptionJSON> {
  if (!isPushSupported()) throw new Error('Push is not supported on this device');
  const reg = await serviceWorkerReady();
  const wanted = urlBase64ToUint8Array(vapidPublicKey);
  let existing = await reg.pushManager.getSubscription();
  if (existing && !sameServerKey(existing.options.applicationServerKey, wanted)) {
    try {
      await existing.unsubscribe();
    } catch {
      /* ignore — we'll resubscribe regardless */
    }
    existing = null;
  }
  const sub =
    existing ??
    (await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: wanted,
    }));
  return sub.toJSON();
}

/**
 * Show a notification locally via the active service worker — no server round-trip.
 * Used as a fallback for the onboarding welcome when a real server push can't be sent
 * or confirmed (e.g. VAPID not configured, or the push service rejected it), so the
 * participant still gets visible confirmation that notifications work. Requires
 * notification permission to already be granted. Best-effort: resolves false if it
 * can't be shown. Works identically on Chrome and Safari (installed PWA on iOS).
 */
export async function showLocalNotification(title: string, body: string): Promise<boolean> {
  try {
    if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return false;
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return false;
    const reg = await serviceWorkerReady();
    await reg.showNotification(title, {
      body,
      icon: '/pwa/pwa-192x192.png',
      badge: '/pwa/badge-96x96.png',
      tag: 'esmira-welcome',
    });
    return true;
  } catch {
    return false;
  }
}
