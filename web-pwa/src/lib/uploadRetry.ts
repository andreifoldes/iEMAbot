/**
 * Shared retry rule for the offline upload queues (datasets, voice memos, keystroke logs).
 *
 * A queued upload is only given up on after the server has said no to it many times. Being offline,
 * a captive-portal page, a timeout or a server error says nothing about the upload itself, so those
 * failures never count: a participant without a connection for days keeps every answer until it can
 * be sent. Kept free of extensionless imports so it can be unit-tested under plain node.
 */

/** Rejections from the server before a queued upload is only tried once a day. Nothing is ever given up on: a server that
 * refuses everything today (a misconfiguration, say) may be fixed tomorrow, and the answers exist nowhere else. */
export const MAX_REJECTIONS = 20;
export const REFUSED_RETRY_MS = 24 * 60 * 60 * 1000;

/** Whether an upload the server has refused `attempts` times should be tried now. */
export function dueForRetry(attempts: number, lastTry: number | undefined, now: number): boolean {
  return attempts < MAX_REJECTIONS || now - (lastTry ?? 0) >= REFUSED_RETRY_MS;
}

/** The server already holds this file, so there is nothing left to send. */
export function alreadyStored(err: unknown): boolean {
  return err instanceof Error && /already exists/i.test(err.message);
}

/** The server answered and refused this upload (a 4xx other than 408/429, or `success: false`). */
export class UploadRejected extends Error {}

/** The server keeps at most one dataset per second from a participant, and refused this one for coming too soon. */
export class UploadTooSoon extends Error {}

/** How often to try at once, how long between those tries and between queued uploads, and when to try again after that. */
export const TOO_SOON_ATTEMPTS = 3;
export const BETWEEN_UPLOADS_MS = 1100;
export const TOO_SOON_RETRY_MS = 3000;

/** Throw unless the server accepted the upload; UploadRejected only for a definite refusal. */
export async function checkUploadReply(resp: Response, endpoint: string): Promise<void> {
  if (!resp.ok) {
    const transient = resp.status >= 500 || resp.status === 408 || resp.status === 429;
    throw new (transient ? Error : UploadRejected)(`${endpoint} returned ${resp.status}`);
  }
  const env = await resp.json(); // a non-JSON page (captive portal) throws here: transient
  if (!env.success) throw new UploadRejected(env.error || `${endpoint} rejected the upload`);
  // datasets.php answers success:true regardless, and says what became of each dataset here. Only "too soon" is known to
  // have stored nothing (it is decided before any write). Other refusals can come after the row was stored, and a resend
  // gets a new entry id, so sending those again could store one answer many times. They stay taken for sent until
  // datasets have an id of their own that the server can recognise.
  const states: unknown = env.dataset?.states;
  const refused = Array.isArray(states) ? states.filter((state) => state && state.success === false) : [];
  if (refused.some((state) => /too many requests/i.test(String(state.error)))) throw new UploadTooSoon(String(refused[0].error));
  if (refused.length) console.error(`${endpoint} refused a dataset: ${String(refused[0].error)}`);
}

/** How much a failed attempt adds to an upload's rejection count. */
export function rejectionCost(err: unknown): number {
  return err instanceof UploadRejected ? 1 : 0;
}
