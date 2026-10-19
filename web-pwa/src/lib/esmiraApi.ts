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

import type { EsmiraStudy, StudiesEnvelope, WearableStatus } from '../types';
import { flushAudioQueue, markAudioReady } from './audioUploads';
import { flushKeystrokeQueue, markKeystrokesReady } from './keystrokeUploads';

/**
 * Root of the ESMira backend (the PHP app + api/). The PWA is served at /pwa/ but the
 * backend lives under /esmira/, so this can't be derived from the PWA base — it's pinned
 * to /esmira/ (overridable at build time via VITE_API_ROOT). In dev, vite proxies
 * /esmira/api/* to the configured ESMIRA target (see vite.config.ts).
 */
const API_ROOT = (import.meta.env.VITE_API_ROOT as string | undefined) || '/esmira/';

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
    return new URL(API_ROOT, window.location.href).href;
  } catch {
    return window.location.origin;
  }
}

export interface FetchedStudy {
  study: EsmiraStudy;
  serverVersion: number;
  /** Server VAPID public key for web push (only when a study has push enabled). */
  vapidPublicKey?: string;
  /** Wearable providers with credentials configured on the server. */
  wearableProviders?: string[];
}

export async function fetchStudy(accessKey: string, studyId?: number): Promise<FetchedStudy> {
  const url = `${API_ROOT}api/studies.php?access_key=${encodeURIComponent(accessKey)}`;
  const resp = await fetch(url, { headers: { Accept: 'application/json' } });
  if (!resp.ok) throw new Error(`studies.php returned ${resp.status}`);
  const env = (await resp.json()) as StudiesEnvelope;
  if (!env.success) throw new Error(env.error || 'Study fetch failed');
  if (!env.dataset?.length) throw new Error('No study found for this access key');
  const study = studyId != null
    ? env.dataset.find(s => s.id === studyId) ?? env.dataset[0]
    : env.dataset[0];
  return {
    study,
    serverVersion: env.serverVersion,
    vapidPublicKey: env.vapidPublicKey,
    wearableProviders: env.wearableProviders,
  };
}

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
  const resp = await fetch(`${API_ROOT}api/push_subscribe.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      userId: args.userId,
      studyId: args.study.id,
      serverVersion: args.serverVersion,
      subscription: args.subscription,
      // So the server can schedule "08:00" in the participant's local time, not its own.
      tzOffset: new Date().getTimezoneOffset(),
    }),
  });
  if (!resp.ok) throw new Error(`push_subscribe.php returned ${resp.status}`);
  const env = await resp.json();
  if (!env.success) throw new Error(env.error || 'Push subscription rejected');
  return true;
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
    const resp = await fetch(`${API_ROOT}api/push_test.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        userId: args.userId,
        studyId: args.study.id,
        serverVersion: args.serverVersion,
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
    await fetch(`${API_ROOT}api/client_info.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ studyId, userId, installed, device }),
    });
  } catch {
    /* best-effort — telemetry must not affect the participant */
  }
}

/**
 * Report a push funnel event from the app (as opposed to the service worker, which
 * reports 'received'/'clicked'). Used by the onboarding confirmation step to record
 * whether the participant actually saw the welcome notification — 'welcome_confirmed'
 * or 'welcome_missed'. A 'welcome_missed' surfaces a delivery problem in the researcher's
 * Push panel. Best-effort: never throws or blocks onboarding.
 */
export async function reportPushEvent(args: {
  studyId: number;
  userId: string;
  event: 'welcome_confirmed' | 'welcome_missed';
}): Promise<void> {
  if (localOnly) return;
  try {
    await fetch(`${API_ROOT}api/push_event.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ studyId: args.studyId, userId: args.userId, event: args.event }),
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
}

/**
 * Ask the server for this participant's next scheduled reminder and the schedule
 * anchor it used. The client adopts `joinedAt` so an already-enrolled participant's
 * timeline isn't shifted by a client-side backfill. Best-effort: never throws.
 */
export async function fetchNextNotification(studyId: number, userId: string): Promise<PushStatus> {
  try {
    const resp = await fetch(
      `${API_ROOT}api/push_status.php?studyId=${studyId}&userId=${encodeURIComponent(userId)}`,
      { headers: { Accept: 'application/json' } },
    );
    if (!resp.ok) return { next: null, joinedAt: null };
    const env = await resp.json();
    if (!env?.success) return { next: null, joinedAt: null };
    return { next: env.dataset?.nextNotification ?? null, joinedAt: env.dataset?.joinedAt ?? null };
  } catch {
    return { next: null, joinedAt: null };
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
    appType: 'Web',
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
      responses: {},
    }],
  };
  try {
    await fetch(`${API_ROOT}api/datasets.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
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
  if (localOnly) throw new Error('This study does not send any data');
  const resp = await fetch(`${API_ROOT}api/wearables_connect.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      userId: args.userId,
      studyId: args.study.id,
      serverVersion: args.serverVersion,
      provider: args.provider,
    }),
  });
  if (!resp.ok) throw new Error(`wearables_connect.php returned ${resp.status}`);
  const env = await resp.json();
  if (!env.success || !env.dataset?.authUrl) throw new Error(env.error || 'Could not start connection');
  return env.dataset.authUrl as string;
}

/**
 * Fetch the participant's connected wearables (+ last sync time). Best-effort: returns
 * an empty list and never throws (purely informational for the Settings/Details panels).
 */
export async function fetchWearableStatus(studyId: number, userId: string): Promise<WearableStatus[]> {
  try {
    const resp = await fetch(
      `${API_ROOT}api/wearables_status.php?studyId=${studyId}&userId=${encodeURIComponent(userId)}`,
      { headers: { Accept: 'application/json' } },
    );
    if (!resp.ok) return [];
    const env = await resp.json();
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
  const resp = await fetch(`${API_ROOT}api/wearables_disconnect.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      userId: args.userId,
      studyId: args.study.id,
      serverVersion: args.serverVersion,
      provider: args.provider,
    }),
  });
  if (!resp.ok) throw new Error(`wearables_disconnect.php returned ${resp.status}`);
  const env = await resp.json();
  if (!env.success) throw new Error(env.error || 'Disconnect failed');
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
    appType: 'Web',
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
  /** Upload time (epoch ms). */
  time: number;
  /** Survey name, or a label for non-questionnaire uploads. */
  label: string;
  eventType: 'joined' | 'questionnaire' | 'cognitive';
  /** 'sent' once the server accepted it; 'pending' while queued offline. */
  status: 'sent' | 'pending';
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

function markProtocolSent(studyId: number, userId: string, ids: string[]): void {
  if (!ids.length) return;
  const wanted = new Set(ids);
  const list = loadRawProtocol(studyId, userId);
  let changed = false;
  for (const e of list) {
    if (wanted.has(e.id) && e.status !== 'sent') {
      e.status = 'sent';
      changed = true;
    }
  }
  if (changed) saveProtocol(studyId, userId, list);
}

function questionnaireProtocolEntries(args: SubmitArgs, status: UploadProtocolEntry['status']): UploadProtocolEntry[] {
  const time = Date.now();
  const entries: UploadProtocolEntry[] = [];
  if (args.newParticipant) {
    entries.push({ id: newProtocolId(), time, label: 'Joined study', eventType: 'joined', status });
  }
  entries.push({ id: newProtocolId(), time, label: args.questionnaireName, eventType: 'questionnaire', status });
  return entries;
}

// ── Submission with offline retry queue ─────────────────────────

const QUEUE_KEY = 'esmira_submit_queue';

interface QueuedSubmit {
  payload: DatasetPayload;
  attempts: number;
  /** Links this queued payload back to its pending upload-protocol entries. */
  protocol?: { studyId: number; userId: string; ids: string[] };
  /** Voice-memo upload identifiers to release for upload once this dataset sends. */
  audio?: { studyId: number; userId: string; identifiers: number[] };
  /** Keystroke-log upload identifiers to release for upload once this dataset sends. */
  keystrokes?: { studyId: number; userId: string; identifiers: number[] };
}

function loadQueue(): QueuedSubmit[] {
  try {
    const raw = localStorage.getItem(QUEUE_KEY);
    return raw ? JSON.parse(raw) : [];
  } catch {
    return [];
  }
}

function saveQueue(q: QueuedSubmit[]): void {
  try {
    localStorage.setItem(QUEUE_KEY, JSON.stringify(q));
  } catch {
    /* storage full/unavailable */
  }
}

async function postDataset(payload: DatasetPayload): Promise<void> {
  if (localOnly) return;
  const resp = await fetch(`${API_ROOT}api/datasets.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  if (!resp.ok) throw new Error(`datasets.php returned ${resp.status}`);
  const env = await resp.json();
  if (!env.success) throw new Error(env.error || 'Submission rejected');
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
  try {
    await postDataset(payload);
    appendProtocol(args.study.id, args.userId, questionnaireProtocolEntries(args, 'sent'));
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
  } catch {
    const entries = questionnaireProtocolEntries(args, 'pending');
    appendProtocol(args.study.id, args.userId, entries);
    const queue = loadQueue();
    queue.push({
      payload,
      attempts: 1,
      protocol: { studyId: args.study.id, userId: args.userId, ids: entries.map((e) => e.id) },
      ...(audioIds.length ? { audio: { studyId: args.study.id, userId: args.userId, identifiers: audioIds } } : {}),
      ...(keystrokeIds.length ? { keystrokes: { studyId: args.study.id, userId: args.userId, identifiers: keystrokeIds } } : {}),
    });
    saveQueue(queue);
    return false;
  }
}

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
    appType: 'Web',
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
  try {
    await postDataset(payload);
    appendProtocol(args.study.id, args.userId, [{ id, time: args.joinedAt, label: 'Joined study', eventType: 'joined', status: 'sent' }]);
    return true;
  } catch {
    const queue = loadQueue();
    queue.push({ payload, attempts: 1, protocol: { studyId: args.study.id, userId: args.userId, ids: [id] } });
    saveQueue(queue);
    appendProtocol(args.study.id, args.userId, [{ id, time: args.joinedAt, label: 'Joined study', eventType: 'joined', status: 'pending' }]);
    return false;
  }
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
  const resp = await fetch(`${API_ROOT}api/save_message.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      userId: args.userId,
      studyId: args.study.id,
      content: args.content,
      serverVersion: args.serverVersion,
    }),
  });
  if (!resp.ok) throw new Error(`save_message.php returned ${resp.status}`);
  const env = await resp.json();
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
    appType: 'Web',
    appVersion: String(args.serverVersion),
    serverVersion: args.serverVersion,
    dataset: args.rows.map((r, i) => ({
      ...common,
      dataSetId: i,
      questionnaireName: args.trialsName,
      questionnaireInternalId: args.trialsInternalId,
      eventType: 'questionnaire' as const,
      responseTime: now + i,
      responses: { ...r, model },
    })),
  };
  const entry: UploadProtocolEntry = {
    id: newProtocolId(), time: now, label: args.trialsName, eventType: 'cognitive', status: 'sent',
  };
  try {
    await postDataset(payload);
    appendProtocol(args.study.id, args.userId, [entry]);
    return true;
  } catch {
    appendProtocol(args.study.id, args.userId, [{ ...entry, status: 'pending' }]);
    const queue = loadQueue();
    queue.push({
      payload,
      attempts: 1,
      protocol: { studyId: args.study.id, userId: args.userId, ids: [entry.id] },
    });
    saveQueue(queue);
    return false;
  }
}

/** Retry any queued submissions. */
export async function flushSubmitQueue(): Promise<void> {
  let queue = loadQueue();
  if (queue.length === 0) return;
  const remaining: QueuedSubmit[] = [];
  for (const item of queue) {
    if (item.attempts >= 20) continue; // give up after many tries
    try {
      await postDataset(item.payload);
      if (item.protocol) markProtocolSent(item.protocol.studyId, item.protocol.userId, item.protocol.ids);
      // This dataset's pending-upload markers now exist server-side → release
      // any voice memos / keystroke logs that were waiting on it.
      if (item.audio) await markAudioReady(item.audio.studyId, item.audio.userId, item.audio.identifiers);
      if (item.keystrokes) await markKeystrokesReady(item.keystrokes.studyId, item.keystrokes.userId, item.keystrokes.identifiers);
    } catch {
      remaining.push({ ...item, attempts: item.attempts + 1 });
    }
  }
  queue = remaining;
  saveQueue(queue);
  // Whether or not the queue drained fully, try uploading any ready recordings/logs.
  void flushAudioQueue();
  void flushKeystrokeQueue();
}

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

// ── PID device-lock (prevents a pre-assigned invite link from being used on
//    multiple devices simultaneously). Calls the iema-bot API, which is on the
//    same origin as the ESMira PWA so no CORS issues arise.
//    Result: 'claimed' | 'already_yours' | 'conflict' | 'error' (network fault)

const PID_LOCK_API = '/api/v1/esmira/pid-lock';

/** localStorage key for the stable per-browser device token (separate from userId). */
export const DEVICE_TOKEN_KEY = 'esmira_device_token';

/** Return or create a stable per-browser device identity token. */
export function getOrCreateDeviceToken(): string {
  const stored = localStorage.getItem(DEVICE_TOKEN_KEY);
  if (stored) return stored;
  const c = typeof crypto !== 'undefined' ? crypto : undefined;
  const token = c?.randomUUID?.() ?? `dt-${Date.now().toString(36)}`;
  localStorage.setItem(DEVICE_TOKEN_KEY, token);
  return token;
}

export type PidLockStatus = 'claimed' | 'already_yours' | 'conflict' | 'error';

/**
 * Attempt to claim a pre-assigned PID for this device.
 * Pass force=true to transfer the lock from another device (e.g. after user confirms).
 * Returns 'error' on network failure — callers should fail open and let the user through.
 */
export async function claimPidLock(
  studyId: string,
  pid: string,
  deviceToken: string,
  force = false,
): Promise<PidLockStatus> {
  if (localOnly) return 'claimed';
  try {
    const resp = await fetch(PID_LOCK_API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ study_id: studyId, pid, device_token: deviceToken, force }),
    });
    if (!resp.ok) return 'error';
    const env = await resp.json();
    return (env?.status as PidLockStatus) ?? 'error';
  } catch {
    return 'error';
  }
}

/**
 * Release the PID lock for this device (call on sign-out). Best-effort — never throws.
 */
export async function releasePidLock(
  studyId: string,
  pid: string,
  deviceToken: string,
): Promise<void> {
  if (localOnly) return;
  try {
    await fetch(PID_LOCK_API, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ study_id: studyId, pid, device_token: deviceToken }),
    });
  } catch {
    /* best-effort */
  }
}

/** Wire up automatic flushing on reconnect / tab visibility. */
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
  return () => {
    window.removeEventListener('online', onOnline);
    document.removeEventListener('visibilitychange', onVisible);
  };
}
