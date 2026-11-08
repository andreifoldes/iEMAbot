/**
 * How the offline dataset queue is worked through. Kept free of extensionless imports so it can be unit-tested under node.
 *
 * Only one run at a time, and storage is brought up to date after every single item: a run takes a while, because the
 * server keeps one dataset a second from a participant. A snapshot saved only at the end would erase whatever was queued in
 * the meantime, and send everything again if the app is closed halfway.
 */

export interface Queued {
  id: string;
}

export interface QueueStore<I extends Queued> {
  load(): I[];
  save(queue: I[]): void;
}

export function newQueueId(): string {
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

/** Calls during a run join it, and cause one more run after it for whatever was added in the meantime. */
export function oneAtATime<T>(run: () => Promise<T>): () => Promise<T> {
  let current: Promise<T> | null = null;
  let again = false;
  const start = (): Promise<T> => run().then(
    (result) => {
      if (!again) { current = null; return result; }
      again = false;
      return start();
    },
    (err) => { current = null; again = false; throw err; },
  );
  return () => {
    if (current) { again = true; return current; }
    current = start();
    return current;
  };
}

export interface DrainSteps<I extends Queued> {
  /** Not now: it is being sent already, or it is not due. It stays in the queue. */
  skip(item: I): boolean;
  /** What the item looks like while being sent, saved before sending: tells others to leave it alone. */
  claim(item: I): I;
  send(item: I): Promise<void>;
  onSent(item: I): void | Promise<void>;
  /** What the item looks like after a failed attempt. It stays in the queue. */
  afterFailure(item: I, err: unknown): I;
  /** Between two sends, so that they do not refuse each other. */
  pause(): Promise<void>;
}

/** Works through what is queued now. Nothing ever leaves the queue except by being sent. Returns the number of items left. */
export async function drainQueue<I extends Queued>(store: QueueStore<I>, steps: DrainSteps<I>): Promise<number> {
  const update = (updated: I) => store.save(store.load().map((queued) => (queued.id === updated.id ? updated : queued)));
  let sentBefore = false;
  for (const { id } of store.load()) {
    const item = store.load().find((queued) => queued.id === id);
    if (!item || steps.skip(item)) continue;
    if (sentBefore) await steps.pause();
    sentBefore = true;
    const claimed = steps.claim(item);
    update(claimed);
    try {
      await steps.send(claimed);
    } catch (err) {
      update(steps.afterFailure(claimed, err));
      continue;
    }
    store.save(store.load().filter((queued) => queued.id !== id)); // At once: from here on, a closed app must not send it again.
    await steps.onSent(claimed);
  }
  return store.load().length;
}
