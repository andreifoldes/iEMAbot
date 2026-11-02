/**
 * Shared retry rule for the offline upload queues (datasets, voice memos, keystroke logs).
 *
 * A queued upload is only given up on after the server has said no to it many times. Being offline,
 * a captive-portal page, a timeout or a server error says nothing about the upload itself, so those
 * failures never count: a participant without a connection for days keeps every answer until it can
 * be sent. Kept free of extensionless imports so it can be unit-tested under plain node.
 */

/** Rejections from the server before a queued upload is dropped. */
export const MAX_REJECTIONS = 20;

/** The server answered and refused this upload (a 4xx other than 408/429, or `success: false`). */
export class UploadRejected extends Error {}

/** Throw unless the server accepted the upload; UploadRejected only for a definite refusal. */
export async function checkUploadReply(resp: Response, endpoint: string): Promise<void> {
  if (!resp.ok) {
    const transient = resp.status >= 500 || resp.status === 408 || resp.status === 429;
    throw new (transient ? Error : UploadRejected)(`${endpoint} returned ${resp.status}`);
  }
  const env = await resp.json(); // a non-JSON page (captive portal) throws here: transient
  if (!env.success) throw new UploadRejected(env.error || `${endpoint} rejected the upload`);
}

/** How much a failed attempt adds to an upload's rejection count. */
export function rejectionCost(err: unknown): number {
  return err instanceof UploadRejected ? 1 : 0;
}
