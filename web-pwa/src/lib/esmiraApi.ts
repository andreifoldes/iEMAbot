/**
 * ESMira backend client.
 *
 * READ:  GET  {root}api/studies.php?access_key=KEY   -> study definition JSON
 * WRITE: POST {root}api/datasets.php  (raw JSON, the same shape native apps and
 *        QuestionnaireSaver::saveDataset() send) -> identical CSV via CreateDataSet.
 *
 * No new backend code: datasets.php already json-decodes the body straight into
 * CreateDataSet::prepare(). We only add a `joined` event on first submission.
 */

import type { EsmiraStudy, WearableStatus } from '../types';
import { flushAudioQueue, markAudioReady } from './audioUploads';
import { flushKeystrokeQueue, markKeystrokesReady } from './keystrokeUploads';
import { getApiRoot } from './serverRoot';
import { deviceContext, deviceField, deviceQuery, isWrongDevice, noteRefusal, scopeKey } from './deviceBinding.ts';
import { t } from './i18n.ts';
import { pushSubscribePayload } from './pushSubscribePayload.ts';
import { clientAppType } from './platform';
import { buildQuitPayload, withdrawnFromStatus } from './withdrawal';
import { BETWEEN_UPLOADS_MS, MAX_REJECTIONS, TOO_SOON_ATTEMPTS, TOO_SOON_RETRY_MS, UploadTooSoon, checkUploadReply, dueForRetry, rejectionCost } from './uploadRetry';
import { drainQueue, newQueueId, oneAtATime } from './uploadQueue';
import type { ServerInputs } from './schedule';

/**
 * Root of the ESMira backend (the PHP app + api/). The PWA is served at /pwa/ but the
 * backend lives under /esmira/, so this can't be derived from the PWA base — it's pinned
 * to /esmira/ (overridable at build time via VITE_API_ROOT). In dev, vite proxies
 * /esmira/api/* to the configured ESMIRA target (see vite.config.ts).
 */

/**
 * Local-only studies (study field `webLocalOnly`, e.g. the ESRS 2026 poster demo): nothing the
 * participant does is sent to the server. Every call below that writes (datasets, events, joins,
 * telemetry, push, wearables, PID lock) returns early while this is on, so answers and game
 * results stay on the device. Reading the study itself is unaffected. Set once the study has
 * loaded (see App.tsx). `sendParticipantMessage` is exempt: it only runs when the participant
 * chooses to write to the research team.
 */
let localOnly = false;
export function setLocalOnly(on: boolean): void {
  localOnly = on;
}
export function isLocalOnly(): boolean {
  return localOnly;
}

/** Full URL of the ESMira server root the PWA talks to (for display in About). */
export function serverRootUrl(): string {
  try {
    return new URL(getApiRoot(), window.location.href).href;
  } catch {
    return window.location.origin;
  }
}

export type { FetchedStudy } from './studyFetch.ts';
export { fetchStudy } from './studyFetch.ts';

/**
 * Register this device's web-push subscription with the server, so the backend
 * scheduler can send the participant's questionnaire reminders even when the PWA
 * is closed. Mirrors the other POST endpoints; the subscription is the browser's
 * `PushSubscription` serialized as JSON ({ endpoint, keys: { p256dh, auth } }).
 */
export async function subscribeToPush(args: {
  study: EsmiraStudy;
  serverVersion: number;
  userId: string;
  subscription: PushSubscriptionJSON;
}): Promise<boolean> {
  if (localOnly) return false;
  const device = deviceContext(args.study.id, args.userId);
  const resp = await fetch(`${getApiRoot()}api/push_subscribe.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...pushSubscribePayload(args), ...deviceField() }),
  });
  if (!resp.ok) throw new Error(`push_subscribe.php returned ${resp.status}`);
  const env = await resp.json();
  noteRefusal(env, device);
  if (!env.success) throw new Error(env.error || 'Push subscription rejected');
  return true;
}

/**
 * Tell the server to forget this device's web-push subscription (sign-out / leaving the study).
 * The server drops the subscription together with the participant's reminder state, so the
 * per-minute sender cannot queue one more push in the meantime. Best-effort: never throws.
 */
export async function unsubscribeFromPush(args: {
  study: EsmiraStudy;
  serverVersion: number;
  userId: string;
}): Promise<boolean> {
  if (localOnly) return true;
  try {
    const resp = await fetch(`${getApiRoot()}api/push_subscribe.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      keepalive: true,
      body: JSON.stringify({
        userId: args.userId,
        studyId: args.study.id,
        serverVersion: args.serverVersion,
        unsubscribe: true,
        ...deviceField(),
      }),
    });
    if (!resp.ok) return false;
    const env = await resp.json();
    return env?.success === true;
  } catch {
    return false;
  }
}

/**
 * Record that the participant left the study: the `quit` event ESMira's native apps send too.
 * The server marks them as withdrawn (no further web push, Android nudges or chat prompts,
 * on any device) and deletes their push subscription. Sent directly rather than through the
 * offline queue, because sign-out wipes that queue right afterwards. Best-effort: never throws.
 */
export async function submitQuit(args: {
  study: EsmiraStudy;
  serverVersion: number;
  accessKey: string;
  userId: string;
}): Promise<boolean> {
  if (localOnly) return true;
  const payload = buildQuitPayload({
    studyId: args.study.id,
    studyVersion: args.study.version,
    studySubVersion: args.study.subVersion,
    studyLang: args.study.lang,
    serverVersion: args.serverVersion,
    accessKey: args.accessKey,
    userId: args.userId,
    model: navigator.userAgent,
    now: Date.now(),
  });
  try {
    const resp = await fetch(`${getApiRoot()}api/datasets.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      keepalive: true,
      body: JSON.stringify({ ...payload, ...deviceField() }),
    });
    if (!resp.ok) return false;
    const env = await resp.json();
    return env?.success === true;
  } catch {
    return false;
  }
}

/**
 * Ask the server to send this participant an immediate welcome/test web push, right
 * after they enable notifications during onboarding. The push is sent only to this
 * user's own subscription and its title/body are generated server-side. Returns the
 * number of subscriptions the push service accepted (0 on any failure), so the caller
 * can fall back to a local notification. Best-effort: never throws.
 */
export async function sendWelcomePush(args: {
  study: EsmiraStudy;
  serverVersion: number;
  userId: string;
}): Promise<number> {
  if (localOnly) return 0;
  try {
    const resp = await fetch(`${getApiRoot()}api/push_test.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        userId: args.userId,
        studyId: args.study.id,
        serverVersion: args.serverVersion,
        ...deviceField(),
      }),
    });
    if (!resp.ok) return 0;
    const env = await resp.json();
    return env?.success ? Number(env.dataset?.succeeded ?? 0) : 0;
  } catch {
    return 0;
  }
}

/**
 * Best-effort telemetry for the researcher's Push panel: report whether this session is
 * running installed-to-home-screen (display-mode standalone) vs a browser tab, plus a
 * coarse device class. Latest report per participant wins server-side. Aggregate-only.
 */
export async function reportClientInfo(studyId: number, userId: string): Promise<void> {
  if (localOnly) return;
  try {
    const mm = typeof matchMedia !== 'undefined' ? matchMedia : null;
    const installed =
      (mm?.('(display-mode: standalone)').matches ?? false) ||
      // iOS Safari home-screen apps:
      (navigator as unknown as { standalone?: boolean }).standalone === true;
    const uaMobile = (navigator as unknown as { userAgentData?: { mobile?: boolean } }).userAgentData?.mobile;
    const coarse = mm?.('(pointer: coarse)').matches ?? false;
    const minSide = Math.min(screen?.width ?? 0, screen?.height ?? 0);
    const device = coarse ? (minSide >= 600 ? 'tablet' : 'mobile') : (uaMobile ? 'mobile' : 'desktop');
    await fetch(`${getApiRoot()}api/client_info.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ studyId, userId, installed, device, ...deviceField() }),
    });
  } catch {
    /* best-effort — telemetry must not affect the participant */
  }
}

/**
 * Report a notification funnel event from the app (as opposed to the service worker, which
 * reports web push 'received'/'clicked'). Used by the onboarding confirmation step to record
 * whether the participant actually saw the welcome notification — 'welcome_confirmed'
 * or 'welcome_missed' (a 'welcome_missed' surfaces a delivery problem in the researcher's
 * Push panel) — and by the native Android app for its device-planned notifications
 * ('scheduled' / 'received' / 'clicked' on channel 'android', `count` at a time), so the
 * researcher sees one funnel for every channel. Best-effort: never throws or blocks the app.
 */
export async function reportPushEvent(args: {
  studyId: number;
  userId: string;
  event: 'welcome_confirmed' | 'welcome_missed' | 'scheduled' | 'received' | 'clicked';
  channel?: 'web' | 'android';
  count?: number;
}): Promise<void> {
  if (localOnly) return;
  try {
    await fetch(`${getApiRoot()}api/push_event.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        studyId: args.studyId, userId: args.userId, event: args.event,
        ...(args.channel ? { channel: args.channel } : {}),
        ...(args.count && args.count > 1 ? { count: args.count } : {}),
        ...deviceField(),
      }),
    });
  } catch {
    /* best-effort telemetry — must not affect the participant */
  }
}

export interface PushStatus {
  /** Next scheduled reminder time (UTC ms), or null if none upcoming / push off. */
  next: number | null;
  /** The schedule anchor the server used (join time, else subscribe time), or null. */
  joinedAt: number | null;
  /**
   * The participant has left the study (signed out on a device, or withdrawn by the research
   * team): this device must stop its reminders and reset. Only an explicit server answer sets it.
   */
  withdrawn: boolean;
  /** The participant switched notifications off (possibly on another device); null when the server did not say. */
  notificationsOff: boolean | null;
}

/**
 * Send the participant's notification switch (Settings → Notifications) to the server, which stops or
 * resumes sending to them on every channel. Resolves whether the server stored it. Never throws.
 */
export async function setNotificationPreference(args: {
  study: EsmiraStudy;
  serverVersion: number;
  userId: string;
  off: boolean;
  channel: 'web' | 'android';
}): Promise<boolean> {
  if (localOnly) return true;
  const device = deviceContext(args.study.id, args.userId);
  try {
    const resp = await fetch(`${getApiRoot()}api/notification_prefs.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ studyId: args.study.id, userId: args.userId, serverVersion: args.serverVersion, off: args.off, channel: args.channel, ...deviceField() }),
    });
    if (!resp.ok) return false;
    const env = await resp.json();
    noteRefusal(env, device);
    return env?.success === true;
  } catch {
    return false;
  }
}

/**
 * Ask the server for this participant's next scheduled reminder and the schedule
 * anchor it used. The client adopts `joinedAt` so an already-enrolled participant's
 * timeline isn't shifted by a client-side backfill. Best-effort: never throws.
 */
export async function fetchNextNotification(studyId: number, userId: string): Promise<PushStatus> {
  const device = deviceContext(studyId, userId);
  try {
    const resp = await fetch(
      `${getApiRoot()}api/push_status.php?studyId=${studyId}&userId=${encodeURIComponent(userId)}${deviceQuery()}`,
      { headers: { Accept: 'application/json' } },
    );
    if (!resp.ok) return { next: null, joinedAt: null, withdrawn: false, notificationsOff: null };
    const env = await resp.json();
    noteRefusal(env, device);
    if (!env?.success) return { next: null, joinedAt: null, withdrawn: false, notificationsOff: null };
    const off = env.dataset?.notificationsOff;
    return {
      next: env.dataset?.nextNotification ?? null,
      joinedAt: env.dataset?.joinedAt ?? null,
      withdrawn: withdrawnFromStatus(env),
      notificationsOff: typeof off === 'boolean' ? off : null,
    };
  } catch {
    return { next: null, joinedAt: null, withdrawn: false, notificationsOff: null };
  }
}

/**
 * What the app needs from the server to compute the study's schedule itself: the participant's answers it reads
 * (usual sleep times) and the wearable events it reacts to (see api/schedule.php and lib/schedule.ts). Null when
 * offline or on any error, so the caller keeps its cached copy. Best-effort: never throws.
 */
export async function fetchScheduleInputs(studyId: number, userId: string): Promise<ServerInputs | null> {
  if (localOnly) return null;
  const device = deviceContext(studyId, userId);
  try {
    const resp = await fetch(
      `${getApiRoot()}api/schedule.php?studyId=${studyId}&userId=${encodeURIComponent(userId)}${deviceQuery()}`,
      { headers: { Accept: 'application/json' }, cache: 'no-store' },
    );
    if (!resp.ok) return null;
    const env = await resp.json();
    noteRefusal(env, device);
    if (!env?.success || typeof env.dataset !== 'object') return null;
    const d = env.dataset;
    return {
      variables: d.variables && typeof d.variables === 'object' && !Array.isArray(d.variables) ? d.variables : {},
      events: Array.isArray(d.events)
        ? d.events.filter((e: { t?: unknown }) => typeof e?.t === 'number')
          .map((e: { t: number; provider?: unknown; kind?: unknown }) => ({ t: e.t, provider: String(e.provider ?? ''), kind: String(e.kind ?? '') }))
        : [],
    };
  } catch {
    return null;
  }
}

/**
 * Record a study event (e.g. tutorial engagement) in the participant's event
 * stream. Sent as an events-only dataset to api/datasets.php — the same pipeline
 * the `joined` event uses — so it lands in the study's events.csv export. The
 * backend accepts any safe eventType string. Best-effort: never throws/blocks.
 */
export async function logEvent(args: {
  study: EsmiraStudy;
  serverVersion: number;
  userId: string;
  accessKey: string;
  eventType: string;
}): Promise<void> {
  if (localOnly) return;
  const now = Date.now();
  const payload = {
    userId: args.userId,
    appType: clientAppType(),
    appVersion: String(args.serverVersion),
    serverVersion: args.serverVersion,
    dataset: [{
      userId: args.userId,
      studyId: args.study.id,
      studyVersion: args.study.version ?? 0,
      studySubVersion: args.study.subVersion ?? 0,
      studyLang: args.study.lang ?? 'en',
      accessKey: args.accessKey,
      dataSetId: 0,
      questionnaireName: null,
      questionnaireInternalId: null,
      eventType: args.eventType,
      responseTime: now,
      ...timezoneFields(now),
      responses: {},
    }],
  };
  try {
    await fetch(`${getApiRoot()}api/datasets.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...payload, ...deviceField() }),
    });
  } catch {
    /* best-effort — tutorial telemetry must not block onboarding */
  }
}

/**
 * Start connecting a wearable: the server validates and returns the provider's OAuth
 * authorization URL (carrying a single-use state). The caller then navigates the
 * browser there. Mirrors the other POST endpoints.
 */
export async function startWearableConnect(args: {
  study: EsmiraStudy;
  serverVersion: number;
  userId: string;
  provider: string;
}): Promise<string> {
  if (localOnly) throw new Error(t('pwa_api_no_data'));
  const device = deviceContext(args.study.id, args.userId);
  const resp = await fetch(`${getApiRoot()}api/wearables_connect.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      userId: args.userId,
      studyId: args.study.id,
      serverVersion: args.serverVersion,
      provider: args.provider,
      ...deviceField(),
    }),
  });
  if (!resp.ok) throw new Error(`wearables_connect.php returned ${resp.status}`);
  const env = await resp.json();
  noteRefusal(env, device);
  if (!env.success || !env.dataset?.authUrl) throw new Error(env.error || t('pwa_api_connect_failed'));
  return env.dataset.authUrl as string;
}

/**
 * Fetch the participant's connected wearables (+ last sync time). Best-effort: returns
 * an empty list and never throws (purely informational for the Settings/Details panels).
 */
export async function fetchWearableStatus(studyId: number, userId: string): Promise<WearableStatus[]> {
  const device = deviceContext(studyId, userId);
  try {
    const resp = await fetch(
      `${getApiRoot()}api/wearables_status.php?studyId=${studyId}&userId=${encodeURIComponent(userId)}${deviceQuery()}`,
      { headers: { Accept: 'application/json' } },
    );
    if (!resp.ok) return [];
    const env = await resp.json();
    noteRefusal(env, device);
    return env?.success ? (env.dataset?.providers ?? []) : [];
  } catch {
    return [];
  }
}

/** Disconnect a wearable: removes the server-side token, data and sync cursor. */
export async function disconnectWearable(args: {
  study: EsmiraStudy;
  serverVersion: number;
  userId: string;
  provider: string;
}): Promise<boolean> {
  if (localOnly) return false;
  const device = deviceContext(args.study.id, args.userId);
  const resp = await fetch(`${getApiRoot()}api/wearables_disconnect.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      userId: args.userId,
      studyId: args.study.id,
      serverVersion: args.serverVersion,
      provider: args.provider,
      ...deviceField(),
    }),
  });
  if (!resp.ok) throw new Error(`wearables_disconnect.php returned ${resp.status}`);
  const env = await resp.json();
  noteRefusal(env, device);
  if (!env.success) throw new Error(env.error || t('pwa_api_disconnect_failed'));
  return true;
}

// Moved to its own module so Node's test runner can import it (shared parity fixtures).
export { buildEsmiraResponses } from './esmiraResponses';

export interface SubmitArgs {
  study: EsmiraStudy;
  serverVersion: number;
  accessKey: string;
  questionnaireInternalId: number;
  questionnaireName: string;
  /** Stable backend-facing identifier (distinct from the display name). */
  userId: string;
  responses: Record<string, string | boolean | number>;
  newParticipant: boolean;
  formDuration: number;
  pageDurations: string;
  /** Upload identifiers of `record_audio` memos captured in this questionnaire.
   *  The bytes are already persisted in IndexedDB; they become uploadable only
   *  once this dataset has reached the server (see audioUploads.ts). */
  audioIdentifiers?: number[];
  /** Upload identifiers of `record_keystrokes` logs — same deferred-upload contract
   *  as audio, but the bytes are the content-free keystroke CSV (see keystrokeUploads.ts). */
  keystrokeIdentifiers?: number[];
}

/**
 * The device's time zone, stored with each response (`timezone`, `timezoneOffset` columns): the researcher's calendar
 * shows each participant's windows in their own time from it. Offset in the getTimezoneOffset() convention (UTC − local,
 * minutes), as for push subscriptions.
 */
export function timezoneFields(now: number): { timezone: string; timezoneOffset: number } {
  let timezone = '';
  try { timezone = Intl.DateTimeFormat().resolvedOptions().timeZone ?? ''; } catch { /* no Intl time zones */ }
  return { timezone, timezoneOffset: new Date(now).getTimezoneOffset() };
}

interface DatasetEntry {
  dataSetId: number;
  studyId: number;
  studyVersion: number;
  studySubVersion: number;
  studyLang: string;
  accessKey: string;
  questionnaireName: string | null;
  questionnaireInternalId: number | null;
  eventType: 'joined' | 'questionnaire';
  responseTime: number;
  timezone: string;
  timezoneOffset: number;
  responses: Record<string, string | boolean | number>;
}

interface DatasetPayload {
  userId: string;
  appType: string;
  appVersion: string;
  serverVersion: number;
  dataset: DatasetEntry[];
}

function buildPayload(args: SubmitArgs): DatasetPayload {
  const now = Date.now();
  const model = navigator.userAgent;
  const common = {
    studyId: args.study.id,
    studyVersion: args.study.version ?? 0,
    studySubVersion: args.study.subVersion ?? 0,
    studyLang: args.study.lang ?? 'en',
    accessKey: args.accessKey,
    ...timezoneFields(now),
  };
  const dataset: DatasetEntry[] = [];
  if (args.newParticipant) {
    dataset.push({
      ...common,
      dataSetId: 0,
      questionnaireName: null,
      questionnaireInternalId: null,
      eventType: 'joined',
      responseTime: now,
      responses: { model },
    });
  }
  dataset.push({
    ...common,
    dataSetId: dataset.length,
    questionnaireName: args.questionnaireName,
    questionnaireInternalId: args.questionnaireInternalId,
    eventType: 'questionnaire',
    responseTime: now,
    responses: {
      ...args.responses,
      formDuration: args.formDuration,
      pageDurations: args.pageDurations,
      model,
    },
  });
  return {
    userId: args.userId,
    appType: clientAppType(),
    appVersion: String(args.serverVersion),
    serverVersion: args.serverVersion,
    dataset,
  };
}

// ── Upload protocol (client-side transparency log) ───────────────
// ESMira's native apps keep a local record of every dataset sent to the
// server so participants can verify what was uploaded. We mirror that here:
// one entry per logical upload, persisted per study + user in localStorage.

const PROTOCOL_KEY_PREFIX = 'esmira_upload_protocol_';
const PROTOCOL_MAX = 200; // keep the log bounded

export interface UploadProtocolEntry {
  /** Stable id, used to flip a queued entry's status once it actually sends. */
  id: string;
  /** When the data was handed over for upload, i.e. when the questionnaire was completed (epoch ms). */
  time: number;
  /** When the server accepted it (epoch ms); later than `time` when it waited offline in the queue. */
  sentAt?: number;
  /** Survey name, or a label for non-questionnaire uploads. */
  label: string;
  eventType: 'joined' | 'questionnaire' | 'cognitive';
  /** 'sent' once the server accepted it; 'pending' while queued offline; 'failed' if the server kept refusing it. */
  status: 'sent' | 'pending' | 'failed';
}

let protocolSeq = 0;
function newProtocolId(): string {
  protocolSeq += 1;
  return `${Date.now()}-${protocolSeq}`;
}

function protocolKey(studyId: number, userId: string): string {
  return `${PROTOCOL_KEY_PREFIX}${studyId}_${userId}`;
}

function loadRawProtocol(studyId: number, userId: string): UploadProtocolEntry[] {
  try {
    const raw = localStorage.getItem(protocolKey(studyId, userId));
    return raw ? (JSON.parse(raw) as UploadProtocolEntry[]) : [];
  } catch {
    return [];
  }
}

function saveProtocol(studyId: number, userId: string, list: UploadProtocolEntry[]): void {
  try {
    localStorage.setItem(protocolKey(studyId, userId), JSON.stringify(list.slice(-PROTOCOL_MAX)));
  } catch {
    /* storage full/unavailable */
  }
}

/** Read the upload protocol for a study + user, newest first. */
export function loadUploadProtocol(studyId: number, userId: string): UploadProtocolEntry[] {
  return loadRawProtocol(studyId, userId).sort((a, b) => b.time - a.time);
}

function appendProtocol(studyId: number, userId: string, entries: UploadProtocolEntry[]): void {
  if (!entries.length) return;
  const list = loadRawProtocol(studyId, userId);
  list.push(...entries);
  saveProtocol(studyId, userId, list);
}

function markProtocol(studyId: number, userId: string, ids: string[], status: 'sent' | 'failed'): void {
  if (!ids.length) return;
  const wanted = new Set(ids);
  const list = loadRawProtocol(studyId, userId);
  let changed = false;
  for (const e of list) {
    if (wanted.has(e.id) && e.status !== status) {
      e.status = status;
      if (status === 'sent') e.sentAt = Date.now();
      changed = true;
    }
  }
  if (changed) saveProtocol(studyId, userId, list);
}

function questionnaireProtocolEntries(args: SubmitArgs, status: UploadProtocolEntry['status']): UploadProtocolEntry[] {
  const time = Date.now();
  const sentAt = status === 'sent' ? time : undefined;
  const entries: UploadProtocolEntry[] = [];
  if (args.newParticipant) {
    entries.push({ id: newProtocolId(), time, sentAt, label: 'Joined study', eventType: 'joined', status });
  }
  entries.push({ id: newProtocolId(), time, sentAt, label: args.questionnaireName, eventType: 'questionnaire', status });
  return entries;
}

// ── Submission with offline retry queue ─────────────────────────

const QUEUE_KEY = 'esmira_submit_queue';

interface QueuedSubmit {
  /** Lets a run of the queue update just this item (see uploadQueue). */
  id: string;
  payload: DatasetPayload;
  /** Times the server refused it (see uploadRetry); failures while offline don't count. */
  attempts: number;
  /** When the last attempt began. */
  lastTry?: number;
  /** When an attempt began that has not ended yet (see SENDING_EXPIRES_MS). */
  sendingSince?: number;
  /** Links this queued payload back to its pending upload-protocol entries. */
  protocol?: { studyId: number; userId: string; ids: string[] };
  /** Voice-memo upload identifiers to release for upload once this dataset sends. */
  audio?: { studyId: number; userId: string; identifiers: number[] };
  /** Keystroke-log upload identifiers to release for upload once this dataset sends. */
  keystrokes?: { studyId: number; userId: string; identifiers: number[] };
}

/** What storage refused to hold. While there is any, this is the queue: the answers stay safe as long as the app is open. */
let memoryQueue: QueuedSubmit[] | null = null;

function loadQueue(): QueuedSubmit[] {
  if (memoryQueue) return memoryQueue;
  try {
    const raw = localStorage.getItem(QUEUE_KEY);
    const queue: QueuedSubmit[] = raw ? JSON.parse(raw) : [];
    // Queued by an older version of the app, without an id.
    if (queue.some((item) => !item.id)) {
      queue.forEach((item) => { item.id ??= newQueueId(); });
      saveQueue(queue);
    }
    return queue;
  } catch {
    return [];
  }
}

function saveQueue(q: QueuedSubmit[]): void {
  try {
    localStorage.setItem(QUEUE_KEY, JSON.stringify(q));
    memoryQueue = null;
  } catch (err) {
    memoryQueue = q;
    console.error('Could not save unsent answers on this device', err);
  }
}

/** Unsent answers exist only in memory, because the device refused to store them. The participant has to know. */
export function unsentAnswersNotSaved(): boolean {
  return (memoryQueue?.length ?? 0) > 0;
}

/** An attempt older than this was cut off (the app was closed, say), so another one may start. */
const SENDING_EXPIRES_MS = 2 * 60 * 1000;
/** Longer than a poor connection needs for a dataset, shorter than SENDING_EXPIRES_MS. */
const UPLOAD_TIMEOUT_MS = 90 * 1000;

function updateQueued(item: QueuedSubmit): void {
  saveQueue(loadQueue().map((queued) => (queued.id === item.id ? item : queued)));
}

/**
 * Saves it first, then sends it: from the moment the participant answers, the answer survives the app being closed or the
 * phone switching off. It leaves the queue only when the server has it. If the server had it but the reply never arrived,
 * it is sent again, and the server recognises it (CreateDataSet::isResendOfStoredAnswers).
 */
async function saveThenSend(item: Omit<QueuedSubmit, 'id' | 'attempts'>): Promise<boolean> {
  const queued: QueuedSubmit = { ...item, id: newQueueId(), attempts: 0, lastTry: Date.now(), sendingSince: Date.now() };
  saveQueue([...loadQueue(), queued]);
  try {
    await postDataset(queued.payload);
  } catch (err) {
    updateQueued({ ...queued, sendingSince: undefined, attempts: rejectionCost(err) });
    retrySoonIfTooSoon(err);
    return false;
  }
  saveQueue(loadQueue().filter((other) => other.id !== queued.id));
  return true;
}

/** The participant id a queued dataset belongs to. */
function datasetScope(payload: DatasetPayload): string {
  return scopeKey(payload.dataset[0]?.studyId ?? 0, payload.userId);
}

async function postDataset(payload: DatasetPayload): Promise<void> {
  if (localOnly) return;
  for (let attempt = 1; ; attempt++) {
    const device = deviceContext(payload.dataset[0]?.studyId ?? 0, payload.userId);
    const resp = await fetch(`${getApiRoot()}api/datasets.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...payload, ...deviceField() }),
      signal: AbortSignal.timeout(UPLOAD_TIMEOUT_MS),
    });
    try {
      await checkUploadReply(resp, 'datasets.php', device);
      return;
    } catch (err) {
      // It clears within a second (say, right after a task's own upload): not worth telling the participant they are offline.
      if (!(err instanceof UploadTooSoon) || attempt >= TOO_SOON_ATTEMPTS) throw err;
      await new Promise((resolve) => setTimeout(resolve, BETWEEN_UPLOADS_MS));
    }
  }
}

/**
 * Submit a completed questionnaire. On network failure the payload is queued in
 * localStorage and retried on reconnect/visibility. Returns true if the server
 * accepted it now, false if it was queued for later.
 */
export async function submitQuestionnaire(args: SubmitArgs): Promise<boolean> {
  if (localOnly) return true;
  const payload = buildPayload(args);
  const audioIds = args.audioIdentifiers ?? [];
  const keystrokeIds = args.keystrokeIdentifiers ?? [];
  const entries = questionnaireProtocolEntries(args, 'pending');
  appendProtocol(args.study.id, args.userId, entries);
  const protocol = { studyId: args.study.id, userId: args.userId, ids: entries.map((e) => e.id) };
  const sent = await saveThenSend({
    payload,
    protocol,
    ...(audioIds.length ? { audio: { studyId: args.study.id, userId: args.userId, identifiers: audioIds } } : {}),
    ...(keystrokeIds.length ? { keystrokes: { studyId: args.study.id, userId: args.userId, identifiers: keystrokeIds } } : {}),
  });
  if (!sent) return false;
  markProtocol(protocol.studyId, protocol.userId, protocol.ids, 'sent');
  // Dataset reached the server → its pending-upload markers exist; release the
  // voice memos / keystroke logs for upload and kick a flush (best-effort, non-blocking).
  if (audioIds.length) {
    await markAudioReady(args.study.id, args.userId, audioIds);
    void flushAudioQueue();
  }
  if (keystrokeIds.length) {
    await markKeystrokesReady(args.study.id, args.userId, keystrokeIds);
    void flushKeystrokeQueue();
  }
  return true;
}

/** The server refused for coming too soon: that passes in a moment, so don't wait for the app to next come to the front. */
function retrySoonIfTooSoon(err: unknown): void {
  if (!(err instanceof UploadTooSoon) || retryTimer !== null) return;
  retryTimer = setTimeout(() => {
    retryTimer = null;
    void flushSubmitQueue();
  }, TOO_SOON_RETRY_MS);
}
let retryTimer: ReturnType<typeof setTimeout> | null = null;

export interface JoinArgs {
  study: EsmiraStudy;
  serverVersion: number;
  accessKey: string;
  userId: string;
  /** Consent-acceptance time (epoch ms) — the enrollment/join timestamp. */
  joinedAt: number;
}

function buildJoinPayload(args: JoinArgs): DatasetPayload {
  const model = navigator.userAgent;
  return {
    userId: args.userId,
    appType: clientAppType(),
    appVersion: String(args.serverVersion),
    serverVersion: args.serverVersion,
    dataset: [{
      studyId: args.study.id,
      studyVersion: args.study.version ?? 0,
      studySubVersion: args.study.subVersion ?? 0,
      studyLang: args.study.lang ?? 'en',
      accessKey: args.accessKey,
      dataSetId: 0,
      questionnaireName: null,
      questionnaireInternalId: null,
      eventType: 'joined',
      responseTime: args.joinedAt,
      ...timezoneFields(args.joinedAt),
      responses: { model },
    }],
  };
}

/**
 * Record enrollment ("joined") at consent time — the enrollment event ESMira's native
 * apps also send on joining, rather than deferring it to the first questionnaire. This
 * creates the participant's server record (UserData → joinedTime, the push-schedule
 * anchor) as soon as they consent. Offline-safe: queued and retried like a submission.
 * Call once, guarded by the `esmira_joined_*` key. Returns true if accepted now.
 */
export async function submitJoined(args: JoinArgs): Promise<boolean> {
  if (localOnly) return true;
  const payload = buildJoinPayload(args);
  const id = newProtocolId();
  appendProtocol(args.study.id, args.userId, [{ id, time: args.joinedAt, label: 'Joined study', eventType: 'joined', status: 'pending' }]);
  const protocol = { studyId: args.study.id, userId: args.userId, ids: [id] };
  const sent = await saveThenSend({ payload, protocol });
  if (sent) markProtocol(protocol.studyId, protocol.userId, protocol.ids, 'sent');
  return sent;
}

/**
 * Send a free-text message from the participant to the research team via the
 * existing public `api/save_message.php` (the same endpoint native apps use).
 * The message is stored against the participant's study userId, so the
 * researcher can link it to that participant's data — no real name or contact
 * details are attached. Returns true on success; throws on a network or
 * validation failure so the caller can surface a retry.
 */
export async function sendParticipantMessage(args: {
  study: EsmiraStudy;
  serverVersion: number;
  userId: string;
  content: string;
}): Promise<boolean> {
  const device = deviceContext(args.study.id, args.userId);
  const resp = await fetch(`${getApiRoot()}api/save_message.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      userId: args.userId,
      studyId: args.study.id,
      content: args.content,
      serverVersion: args.serverVersion,
      ...deviceField(),
    }),
  });
  if (!resp.ok) throw new Error(`save_message.php returned ${resp.status}`);
  const env = await resp.json();
  noteRefusal(env, device);
  if (!env.success) throw new Error(env.error || 'Message rejected');
  return true;
}

export interface CognitiveTrialsArgs {
  study: EsmiraStudy;
  serverVersion: number;
  accessKey: string;
  /** Stable backend-facing identifier (distinct from the display name). */
  userId: string;
  trialsInternalId: number;
  trialsName: string;
  rows: Record<string, string | number>[];
}

/**
 * Write one row per cognitive trial to the dedicated "Cognitive Trials"
 * questionnaire (long/tabular format) via the existing datasets.php. Uses the
 * same offline queue on failure.
 */
export async function submitCognitiveTrials(args: CognitiveTrialsArgs): Promise<boolean> {
  if (!args.rows.length || localOnly) return true;
  const now = Date.now();
  const model = navigator.userAgent;
  const common = {
    studyId: args.study.id,
    studyVersion: args.study.version ?? 0,
    studySubVersion: args.study.subVersion ?? 0,
    studyLang: args.study.lang ?? 'en',
    accessKey: args.accessKey,
  };
  const payload: DatasetPayload = {
    userId: args.userId,
    appType: clientAppType(),
    appVersion: String(args.serverVersion),
    serverVersion: args.serverVersion,
    dataset: args.rows.map((r, i) => ({
      ...common,
      dataSetId: i,
      questionnaireName: args.trialsName,
      questionnaireInternalId: args.trialsInternalId,
      eventType: 'questionnaire' as const,
      responseTime: now + i,
      ...timezoneFields(now),
      responses: { ...r, model },
    })),
  };
  const entry: UploadProtocolEntry = {
    id: newProtocolId(), time: now, label: args.trialsName, eventType: 'cognitive', status: 'sent',
  };
  appendProtocol(args.study.id, args.userId, [{ ...entry, status: 'pending' }]);
  const protocol = { studyId: args.study.id, userId: args.userId, ids: [entry.id] };
  const sent = await saveThenSend({ payload, protocol });
  if (sent) markProtocol(protocol.studyId, protocol.userId, protocol.ids, 'sent');
  return sent;
}

/**
 * Retry any queued submissions. Returns how many are still waiting to be sent. Nothing leaves the queue unless the server
 * has it: being offline never counts against an upload, and one the server keeps refusing is tried once a day.
 */
export const flushSubmitQueue = oneAtATime(async (): Promise<number> => {
  const left = await drainQueue<QueuedSubmit>({ load: loadQueue, save: saveQueue }, {
    skip: (item) => {
      const now = Date.now();
      return (item.sendingSince !== undefined && now - item.sendingSince < SENDING_EXPIRES_MS) // by another tab, or just now
        || !dueForRetry(item.attempts, item.lastTry, now)
        || isWrongDevice(datasetScope(item.payload)); // another device holds this id: waits for the participant's transfer
    },
    claim: (item) => ({ ...item, lastTry: Date.now(), sendingSince: Date.now() }),
    send: (item) => postDataset(item.payload),
    onSent: async (item) => {
      if (item.protocol) markProtocol(item.protocol.studyId, item.protocol.userId, item.protocol.ids, 'sent');
      // This dataset's pending-upload markers now exist server-side → release
      // any voice memos / keystroke logs that were waiting on it.
      if (item.audio) await markAudioReady(item.audio.studyId, item.audio.userId, item.audio.identifiers);
      if (item.keystrokes) await markKeystrokesReady(item.keystrokes.studyId, item.keystrokes.userId, item.keystrokes.identifiers);
    },
    afterFailure: (item, err) => {
      retrySoonIfTooSoon(err);
      const attempts = item.attempts + rejectionCost(err);
      // Say so in the protocol. It stays queued, and turns to 'sent' if the server takes it after all.
      if (attempts >= MAX_REJECTIONS && item.protocol) markProtocol(item.protocol.studyId, item.protocol.userId, item.protocol.ids, 'failed');
      return { ...item, attempts, sendingSince: undefined };
    },
    pause: () => new Promise((resolve) => setTimeout(resolve, BETWEEN_UPLOADS_MS)),
  });
  // Whether or not the queue drained fully, try uploading any ready recordings/logs.
  void flushAudioQueue();
  void flushKeystrokeQueue();
  return left;
});

export function pendingSubmitCount(): number {
  return loadQueue().length;
}

// ── Client error log (powers "Send error report") ───────────────
// Capture uncaught errors / promise rejections into a small capped ring buffer
// in localStorage so a participant can forward them to the research team — the
// web equivalent of the native app's "Forward error report".

const ERROR_LOG_KEY = 'esmira_error_log';
const ERROR_LOG_MAX = 30;

export interface ClientErrorEntry {
  time: number;
  message: string;
}

/** Read the captured client error log, oldest first. */
export function loadErrorLog(): ClientErrorEntry[] {
  try {
    const raw = localStorage.getItem(ERROR_LOG_KEY);
    return raw ? (JSON.parse(raw) as ClientErrorEntry[]) : [];
  } catch {
    return [];
  }
}

function pushErrorLog(message: string): void {
  try {
    const list = loadErrorLog();
    list.push({ time: Date.now(), message: message.slice(0, 500) });
    localStorage.setItem(ERROR_LOG_KEY, JSON.stringify(list.slice(-ERROR_LOG_MAX)));
  } catch {
    /* storage full/unavailable */
  }
}

/** Clear the captured client error log (e.g. after a report is sent). */
export function clearErrorLog(): void {
  try {
    localStorage.removeItem(ERROR_LOG_KEY);
  } catch {
    /* storage unavailable */
  }
}

/** Capture uncaught errors and unhandled rejections into the local log.
 *  Returns an uninstaller (so it cleans up cleanly under StrictMode remounts). */
export function installErrorLogger(): () => void {
  const onError = (e: ErrorEvent) => {
    pushErrorLog(`${e.message}${e.filename ? ` @ ${e.filename}:${e.lineno}:${e.colno}` : ''}`);
  };
  const onRejection = (e: PromiseRejectionEvent) => {
    const r = e.reason;
    pushErrorLog(`Unhandled rejection: ${r instanceof Error ? `${r.message}` : String(r)}`);
  };
  window.addEventListener('error', onError);
  window.addEventListener('unhandledrejection', onRejection);
  return () => {
    window.removeEventListener('error', onError);
    window.removeEventListener('unhandledrejection', onRejection);
  };
}

// ── Device token (deviceBinding.ts) ─────────────────────────────

export { DEVICE_TOKEN_KEY, getOrCreateDeviceToken } from './deviceBinding.ts';

export type PidCheckResult =
  /** The study lets this id in; `userId` is the id as the study spells it (a typed "p001" may be the listed "P001"). */
  | { status: 'allowed'; userId: string }
  | { status: 'denied' }
  /** The server could not be asked (offline, wrong code, old server). In a restricted study the participant retries. */
  | { status: 'error' };

/**
 * Ask whether a restricted study lets this participant id in (api/check_pid.php), before anything is stored or
 * sent for it. Only asked for studies with a list; an open study never calls this.
 */
export async function checkPid(studyId: number, userId: string, accessKey: string, serverVersion: number): Promise<PidCheckResult> {
  try {
    const resp = await fetch(`${getApiRoot()}api/check_pid.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ studyId, userId, accessKey, serverVersion }),
    });
    if (!resp.ok) return { status: 'error' };
    const env = await resp.json();
    if (!env?.success || typeof env.dataset?.allowed !== 'boolean') return { status: 'error' };
    if (!env.dataset.allowed) return { status: 'denied' };
    const canonical = typeof env.dataset.userId === 'string' && env.dataset.userId.trim() ? env.dataset.userId.trim() : userId;
    return { status: 'allowed', userId: canonical };
  } catch {
    return { status: 'error' };
  }
}

export type PidLockStatus = 'claimed' | 'already_yours' | 'conflict' | 'error';

interface LockArgs {
  studyId: number;
  userId: string;
  accessKey: string;
  serverVersion: number;
}

/**
 * Ask the server to give this participant id to this device (api/pid_lock.php), for a study that allows an id on one
 * device only. 'claimed' / 'already_yours': this device holds it. 'conflict': another device does, and the participant
 * may take it over with `force`. 'error' (offline, old server): callers let the participant through, because the server
 * refuses the other device's requests on its own anyway.
 */
export async function claimPidLock(args: LockArgs & { force?: boolean }): Promise<PidLockStatus> {
  if (localOnly) return 'claimed';
  try {
    const resp = await fetch(`${getApiRoot()}api/pid_lock.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...args, ...deviceField() }),
    });
    if (!resp.ok) return 'error';
    const env = await resp.json();
    const status = env?.success ? env.dataset?.status : null;
    return status === 'claimed' || status === 'already_yours' || status === 'conflict' ? status : 'error';
  } catch {
    return 'error';
  }
}

/**
 * Let go of the participant id (call on sign-out), so another device can use it without a transfer. Best-effort: never throws.
 */
export async function releasePidLock(args: LockArgs): Promise<void> {
  if (localOnly) return;
  try {
    await fetch(`${getApiRoot()}api/pid_lock.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...args, ...deviceField(), release: true }),
    });
  } catch {
    /* best-effort */
  }
}

/** Wire up automatic flushing on reconnect / tab visibility. */
const QUEUE_CHECK_MS = 60 * 1000;

export function installSubmitQueueFlusher(): () => void {
  // flushSubmitQueue early-returns when the dataset queue is empty, so also flush
  // the audio queue directly — a memo may still be pending after its dataset sent.
  const flushAll = () => {
    void flushSubmitQueue();
    void flushAudioQueue();
    void flushKeystrokeQueue();
  };
  const onOnline = () => flushAll();
  const onVisible = () => {
    if (document.visibilityState === 'visible') flushAll();
  };
  window.addEventListener('online', onOnline);
  document.addEventListener('visibilitychange', onVisible);
  flushAll();
  // A connection can come back without an 'online' event, and a participant can leave the app open for a long time.
  const timer = setInterval(() => {
    if (document.visibilityState === 'visible' && navigator.onLine !== false) flushAll();
  }, QUEUE_CHECK_MS);
  return () => {
    clearInterval(timer);
    window.removeEventListener('online', onOnline);
    document.removeEventListener('visibilitychange', onVisible);
  };
}
