/**
 * Which device is this, and has the server said another device holds the participant's id?
 *
 * A study can allow a participant id on one device only (study field `pidDevices` "single"). The server then answers
 * every request that carries another device's token with errorCode 409. Every request that names a participant carries
 * this device's token (`deviceField()`), and a 409 anywhere ends in `markWrongDevice()`: the queues of unsent answers
 * pause (they keep everything), and the app offers to take the id over. Kept free of extensionless imports so it can be
 * unit-tested under plain node.
 */

/** localStorage key for the stable per-browser device token (separate from the participant id). */
export const DEVICE_TOKEN_KEY = 'esmira_device_token';

/** The errorCode the server answers with when another device holds the participant id (ParticipantDeviceLock::ERROR_CODE). */
export const WRONG_DEVICE_CODE = 409;

/** Whether the study allows a participant id on one device only. Several devices is the default. */
export function singleDevice(study: { pidDevices?: string; webLocalOnly?: boolean }): boolean {
  return study.pidDevices === 'single' && study.webLocalOnly !== true;
}

let memoryToken = '';

function newToken(): string {
  const c = typeof crypto !== 'undefined' ? crypto : undefined;
  return c?.randomUUID?.() ?? `dt-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

/**
 * Return or create a stable per-browser device identity token. When storage refuses (a private window), the token lives
 * until the page is closed: that device then counts as a new one the next time, which the participant can transfer.
 */
export function getOrCreateDeviceToken(): string {
  try {
    const stored = localStorage.getItem(DEVICE_TOKEN_KEY);
    if (stored) return stored;
  } catch { /* storage blocked: use the one in memory */ }
  if (!memoryToken) memoryToken = newToken();
  try { localStorage.setItem(DEVICE_TOKEN_KEY, memoryToken); } catch { /* kept in memory */ }
  return memoryToken;
}

/** Spread into the JSON body (or append to the form) of every request that names a participant. */
export function deviceField(): { deviceToken: string } {
  return { deviceToken: getOrCreateDeviceToken() };
}

/** The same for a GET request's query string, with the leading `&`. */
export function deviceQuery(): string {
  return `&deviceToken=${encodeURIComponent(getOrCreateDeviceToken())}`;
}

// ── "Another device holds this id" ───────────────────────────────
//
// A refusal belongs to one participant id in one study (the queues can hold answers of an id the participant has left, and
// a request in flight can be answered after the participant has already taken the id over), so it is recorded per id, and a
// refusal to a request that began before the last transfer is ignored.

/** What the participant's app knows about one request when it begins: whose it is, and which transfer it began after. */
export interface DeviceContext {
  scope: string;
  since: number;
}

/** Names one participant id in one study. */
export function scopeKey(studyId: number | string, userId: string): string {
  return `${studyId}:${userId}`;
}

let epoch = 0;
const refused = new Set<string>();
const listeners = new Set<(scope: string) => void>();

/** Call before sending a request about a participant, and hand the result to `noteRefusal` with the reply. */
export function deviceContext(studyId: number | string, userId: string): DeviceContext {
  return { scope: scopeKey(studyId, userId), since: epoch };
}

/** True from the moment the server refused this device for this id until the participant has taken the id over. Its queued uploads stay put meanwhile. */
export function isWrongDevice(scope: string): boolean {
  return refused.has(scope);
}

/** The server said another device holds the id. Tells the listeners once, however many requests are refused. Ignored when the request began before the last transfer. */
export function markWrongDevice(ctx: DeviceContext): void {
  if (ctx.since !== epoch || refused.has(ctx.scope)) return;
  refused.add(ctx.scope);
  listeners.forEach((listener) => listener(ctx.scope));
}

/** This device holds the id again (the participant transferred it, or the server confirmed it), or has left the study: forget the refusal, and every one that is still on its way. Without an id: all of them. */
export function clearWrongDevice(scope?: string): void {
  if (scope === undefined) refused.clear();
  else refused.delete(scope);
  epoch++;
}

/** Be told when the server first refuses this device for an id. Returns the way to stop listening. */
export function onWrongDevice(listener: (scope: string) => void): () => void {
  listeners.add(listener);
  return () => { listeners.delete(listener); };
}

/** For a reply that was parsed already: note it when it is the wrong-device refusal. Returns whether it was one. */
export function noteRefusal(env: unknown, ctx: DeviceContext): boolean {
  const isRefusal = !!env && typeof env === 'object' && (env as { success?: unknown }).success === false
    && (env as { errorCode?: unknown }).errorCode === WRONG_DEVICE_CODE;
  if (isRefusal) markWrongDevice(ctx);
  return isRefusal;
}
