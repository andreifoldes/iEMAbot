/// <reference lib="webworker" />
/**
 * Custom service worker (injectManifest strategy).
 *
 * Replicates the previous generateSW behaviour — precache the app shell, SPA
 * navigation fallback, NetworkFirst for the study definition, CacheFirst for
 * Google Fonts — and adds the web-push handlers that let the server deliver
 * questionnaire reminders while the PWA is closed.
 */
import { CacheableResponsePlugin } from 'workbox-cacheable-response';
import { clientsClaim } from 'workbox-core';
import { ExpirationPlugin } from 'workbox-expiration';
import { createHandlerBoundToURL, precacheAndRoute, type PrecacheEntry } from 'workbox-precaching';
import { NavigationRoute, registerRoute } from 'workbox-routing';
import { CacheFirst, NetworkFirst } from 'workbox-strategies';

declare const self: ServiceWorkerGlobalScope & {
  __WB_MANIFEST: (string | PrecacheEntry)[];
};

// Activate a new service worker immediately (matches registerType: 'autoUpdate').
self.skipWaiting();
clientsClaim();

// ── App shell precache (hashed assets injected at build time) ──────────────────
const manifest = self.__WB_MANIFEST;
precacheAndRoute(manifest);

// SPA navigation fallback → the precached index.html, but never intercept the PHP API
// or the chat-channel handoff page (its own entry, handoff.html — opened from Telegram).
// Guarded: in `vite dev` the injected manifest is empty, and createHandlerBoundToURL
// throws for a non-precached URL ("index.html"), which would fail SW evaluation and
// stop it registering. Dev serves index.html directly, so the fallback isn't needed there.
if (manifest && manifest.length) {
  registerRoute(
    new NavigationRoute(createHandlerBoundToURL('index.html'), {
      denylist: [/^\/esmira\/api\//, /^\/pwa\/handoff/],
    }),
  );
}

// Study definition: NetworkFirst so a previously opened study works offline.
// The query string is part of the cache key, so each access_key caches separately.
registerRoute(
  ({ url }) => url.pathname.endsWith('/api/studies.php'),
  new NetworkFirst({
    cacheName: 'esmira-study',
    networkTimeoutSeconds: 5,
    plugins: [
      new CacheableResponsePlugin({ statuses: [0, 200] }),
      new ExpirationPlugin({ maxEntries: 16, maxAgeSeconds: 60 * 60 * 24 * 30 }),
    ],
  }),
);

// Google Fonts (Inter) used by index.html.
registerRoute(
  ({ url }) =>
    url.origin === 'https://fonts.googleapis.com' || url.origin === 'https://fonts.gstatic.com',
  new CacheFirst({
    cacheName: 'google-fonts',
    plugins: [
      new CacheableResponsePlugin({ statuses: [0, 200] }),
      new ExpirationPlugin({ maxEntries: 20, maxAgeSeconds: 60 * 60 * 24 * 365 }),
    ],
  }),
);

// ── Web push: show the reminder the server sent ────────────────────────────────
// A constant tag keeps at most one ESMira notification on screen (each push replaces
// the last), and the per-questionnaire `items` let us drop any survey the participant
// has already completed (read from the IndexedDB completion mirror, since the SW can't
// read localStorage). See completionMirror.ts and backend PushSender::buildCoalescedPayload.
const DEFAULT_TAG = 'esmira-reminder';

/** One questionnaire referenced by a (possibly coalesced) push. */
interface PushItem {
  qid: number;
  /** Per-occurrence identity ("type:qid:sendTime") for the shown-once-a-day ledger. */
  key?: string;
  /** When this occurrence's completion window opened (epoch ms). */
  windowStart?: number;
  /** When the completion window closes (epoch ms); null/absent = open until end of day. */
  deadline?: number | null;
  /** Whether the questionnaire is one-shot (completableOnce). */
  once?: boolean;
  /** completableOncePerDay — one completion any time this local day satisfies it. */
  dailyOnce?: boolean;
  /** completableOncePerNotification — a completion of THIS occurrence (occKey) satisfies it. */
  onceNotif?: boolean;
  /** Window key `${dayIndex}:${startTimeOfDay}` matching the app's occ map (availability.ts). */
  occKey?: string | null;
  title?: string;
  body?: string;
}

interface PushPayload {
  title?: string;
  body?: string;
  tag?: string;
  url?: string;
  /** studyId/userId the server stamped in, so we can report delivery + click receipts. */
  sid?: number;
  uid?: string;
  /** Per-questionnaire entries for completed-survey suppression + remaining-count re-render. */
  items?: PushItem[];
  /** printf template ("You have %d questionnaires…") used when some items are dropped. */
  bodyTemplate?: string;
}

/** Report a push funnel event ('received'/'clicked') to the backend. Best-effort. */
function reportPushEvent(sid: number | undefined, uid: string | undefined, event: string): Promise<unknown> {
  if (!sid || !uid) return Promise.resolve();
  return fetch('/esmira/api/push_event.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ studyId: sid, userId: uid, event }),
    keepalive: true,
  }).catch(() => undefined);
}

/**
 * SW-local IndexedDB state. Kept self-contained (no app-module import) on purpose:
 * vite's dev service worker leaves relative app imports unresolved, which breaks SW
 * registration in dev. Must stay in sync with web-pwa/src/lib/completionMirror.ts —
 * DB 'esmira_state' v2 with store 'completions' (written by the app; key
 * `studyId:userId:qid`) and store 'shown' (this SW's display ledger; key = the push
 * item's per-occurrence `key`), so the same notification is never shown twice a day.
 */
function swOpenState(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    let req: IDBOpenDBRequest;
    try {
      req = indexedDB.open('esmira_state', 2);
    } catch (e) {
      reject(e);
      return;
    }
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains('completions')) db.createObjectStore('completions', { keyPath: 'key' });
      if (!db.objectStoreNames.contains('shown')) db.createObjectStore('shown', { keyPath: 'key' });
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error ?? new Error('IndexedDB open failed'));
  });
}

type SwCompletion = { lastAt: number; count: number; occ?: Record<string, number> };

function swGetCompletion(sid: number, uid: string, qid: number): Promise<SwCompletion | null> {
  return swOpenState()
    .then(
      (db) =>
        new Promise<SwCompletion | null>((resolve) => {
          try {
            const g = db.transaction('completions', 'readonly').objectStore('completions').get(`${sid}:${uid}:${qid}`);
            g.onsuccess = () => {
              const r = g.result as SwCompletion | undefined;
              resolve(r ? { lastAt: r.lastAt, count: r.count, occ: r.occ } : null);
              db.close();
            };
            g.onerror = () => { resolve(null); db.close(); };
          } catch {
            resolve(null);
            db.close();
          }
        }),
    )
    .catch(() => null);
}

/** Whether a notification with this per-occurrence key has already been displayed. */
function swWasShown(key: string): Promise<boolean> {
  return swOpenState()
    .then(
      (db) =>
        new Promise<boolean>((resolve) => {
          try {
            const g = db.transaction('shown', 'readonly').objectStore('shown').get(key);
            g.onsuccess = () => { resolve(!!g.result); db.close(); };
            g.onerror = () => { resolve(false); db.close(); };
          } catch {
            resolve(false);
            db.close();
          }
        }),
    )
    .catch(() => false);
}

/** Record keys as shown (and prune entries older than 48h). Best-effort. */
function swRecordShown(keys: string[], now: number): Promise<void> {
  if (!keys.length) return Promise.resolve();
  return swOpenState()
    .then(
      (db) =>
        new Promise<void>((resolve) => {
          try {
            const tx = db.transaction('shown', 'readwrite');
            const store = tx.objectStore('shown');
            for (const key of keys) store.put({ key, shownAt: now });
            const cutoff = now - 48 * 3600 * 1000;
            const cur = store.openCursor();
            cur.onsuccess = () => {
              const c = cur.result;
              if (c) {
                if (((c.value as { shownAt?: number }).shownAt ?? 0) < cutoff) c.delete();
                c.continue();
              }
            };
            tx.oncomplete = () => { resolve(); db.close(); };
            tx.onerror = () => { resolve(); db.close(); };
          } catch {
            resolve();
            db.close();
          }
        }),
    )
    .catch(() => undefined);
}

/** Stable per-occurrence key for the shown ledger (server-provided, with a fallback). */
function itemKey(it: PushItem): string {
  return it.key || `${it.qid}:${it.windowStart ?? 0}`;
}

/** Whether two timestamps fall on the same local calendar day (device-local, matching the
 *  app's availability.ts isToday — the SW runs on the participant's device). */
function isSameLocalDay(a: number, b: number): boolean {
  const da = new Date(a), db = new Date(b);
  return da.getFullYear() === db.getFullYear() && da.getMonth() === db.getMonth() && da.getDate() === db.getDate();
}

/** Has this questionnaire's current occurrence already been completed (or otherwise
 *  satisfied for now under the study's completion rules)? */
async function itemCompleted(sid: number, uid: string, it: PushItem): Promise<boolean> {
  const rec = await swGetCompletion(sid, uid, it.qid);
  if (!rec) return false;
  // Completed at/after this occurrence's window opened → already handled.
  if (typeof it.windowStart === 'number' && rec.lastAt >= it.windowStart) return true;
  // One-shot questionnaire completed at least once (covers completion on an earlier day).
  if (it.once && rec.count > 0) return true;
  // Once-per-day questionnaire already completed earlier the SAME local day — even before
  // this signal fired. The app locks it for the day, so don't re-prompt.
  if (it.dailyOnce && isSameLocalDay(rec.lastAt, Date.now())) return true;
  // Once-per-notification questionnaire whose THIS specific occurrence was completed
  // (exact occ-key match; occ is mirrored from availability.ts's per-window record).
  if (it.onceNotif && it.occKey && rec.occ && rec.occ[it.occKey]) return true;
  return false;
}

async function showReminder(payload: PushPayload): Promise<void> {
  const tag = payload.tag || DEFAULT_TAG;
  let title = payload.title || 'iEMAbot';
  let body = payload.body ?? '';

  let shownKeys: string[] = [];
  const items = Array.isArray(payload.items) ? payload.items : [];
  if (items.length && payload.sid && payload.uid) {
    const remaining: PushItem[] = [];
    for (const it of items) {
      // Completion window already closed (deadline passed) → too late to do it, don't nag.
      if (typeof it.deadline === 'number' && Date.now() > it.deadline) continue;
      // Drop anything already completed, or already displayed today (dedup by occurrence key).
      if (await itemCompleted(payload.sid, payload.uid, it)) continue;
      if (await swWasShown(itemKey(it))) continue;
      remaining.push(it);
    }
    // Everything referenced here is already done or already shown — don't re-prompt.
    // (On userVisibleOnly subscriptions the platform may occasionally surface a generic
    // fallback; the server also stops reminding once a dataset arrives, so this is rare.)
    if (remaining.length === 0) return;
    if (remaining.length === 1) {
      title = remaining[0].title || title;
      body = remaining[0].body || body;
    } else {
      title = payload.title || 'iEMAbot';
      body = payload.bodyTemplate
        ? payload.bodyTemplate.replace('%d', String(remaining.length))
        : `You have ${remaining.length} questionnaires to complete.`;
    }
    shownKeys = remaining.map(itemKey);
  }

  // Never stack: clear any existing ESMira notification, then show exactly one.
  for (const n of await self.registration.getNotifications({ tag })) n.close();
  // Record what we're about to display so a re-delivery of the same occurrence is deduped.
  await swRecordShown(shownKeys, Date.now());
  await self.registration.showNotification(title, {
    body,
    icon: '/pwa/pwa-192x192.png',
    // badge is Android's status-bar/notification-corner icon: tinted by the OS
    // using only the alpha channel, so it must be a white silhouette on
    // transparent — not the full-colour logo (which renders as a solid blob).
    badge: '/pwa/badge-96x96.png',
    tag,
    renotify: true,
    data: { url: payload.url || '/pwa/', sid: payload.sid, uid: payload.uid },
  } as NotificationOptions);
}

self.addEventListener('push', (event: PushEvent) => {
  let payload: PushPayload = {};
  if (event.data) {
    try {
      payload = event.data.json() as PushPayload;
    } catch {
      payload = { body: event.data.text() };
    }
  }
  event.waitUntil(
    Promise.all([
      showReminder(payload),
      // The notification actually reached this device — record "arrived".
      reportPushEvent(payload.sid, payload.uid, 'received'),
    ]),
  );
});

// Tapping a reminder focuses the already-open app, or opens it at the study.
self.addEventListener('notificationclick', (event: NotificationEvent) => {
  event.notification.close();
  const data = event.notification.data as { url?: string; sid?: number; uid?: string } | undefined;
  const targetUrl = data?.url || '/pwa/';
  event.waitUntil(
    (async () => {
      await reportPushEvent(data?.sid, data?.uid, 'clicked');
      const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      for (const client of wins) {
        if (client.url.includes('/pwa/')) return (client as WindowClient).focus();
      }
      return self.clients.openWindow(targetUrl);
    })(),
  );
});
