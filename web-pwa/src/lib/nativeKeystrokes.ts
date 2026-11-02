/**
 * Native soft-keyboard timing for the keystroke recorder, inside the Android app.
 *
 * In a WebView a soft keyboard's edit reaches JS as a `beforeinput` whose timeStamp is taken
 * when the renderer dispatches it — after the IME → app → renderer hops, so every interval
 * carries their latency and jitter, and Gboard's composing updates only show up as coarse
 * `insertCompositionText` ("other") rows. The app's KeystrokeTiming plugin (android-app,
 * TimedInputConnection.java) wraps the WebView's InputConnection instead: each edit is stamped
 * with SystemClock.uptimeMillis() the moment the keyboard's call reaches the app, and composing
 * updates are diffed into per-key classes (letter/digit/…, never the character).
 *
 * Both clocks are CLOCK_MONOTONIC on Android, so they differ by a constant offset. It is
 * measured around the start/stop bridge calls (midpoint of each round trip; the faster one
 * wins), and native stamps are converted onto the DOM clock so they line up with the focus and
 * paste markers the DOM still records. In a browser (plain PWA) there is no plugin: null.
 */

import type { KeystrokeClass, NativeKeyRow } from './keystrokeCapture';

interface RawRow { cls: string; t: number }

interface KeystrokeTimingPlugin {
  start(): Promise<{ now: number; session: number }>;
  /** Only the latest session stops: a writer closing late can't end a newer one's recording. */
  stop(opts: { session: number }): Promise<{ now: number; rows: RawRow[] }>;
}

const CLASSES = new Set<KeystrokeClass>([
  'letter', 'digit', 'whitespace', 'punctuation', 'backspace', 'delete', 'navigation', 'modifier', 'other',
]);

function plugin(): KeystrokeTimingPlugin | null {
  return (globalThis as { Capacitor?: { Plugins?: { KeystrokeTiming?: KeystrokeTimingPlugin } } })
    .Capacitor?.Plugins?.KeystrokeTiming ?? null;
}

/** One clock-offset sample: native − DOM time, and how wide the round trip was. */
interface Sync { offset: number; rtt: number }

async function sample<T extends { now: number }>(call: () => Promise<T>): Promise<{ res: T; sync: Sync }> {
  const before = performance.now();
  const res = await call();
  const after = performance.now();
  return { res, sync: { offset: res.now - (before + after) / 2, rtt: after - before } };
}

/** Convert native rows onto the DOM clock with the tighter of the two offset samples. */
export function toDomClock(rows: RawRow[], syncs: Sync[]): NativeKeyRow[] {
  const best = syncs.reduce((a, b) => (b.rtt < a.rtt ? b : a));
  return rows
    .filter((r) => CLASSES.has(r.cls as KeystrokeClass) && Number.isFinite(r.t))
    .map((r) => ({ cls: r.cls as KeystrokeClass, timeStamp: r.t - best.offset }));
}

export interface NativeKeystrokeSession {
  /** Stop recording and return the IME-timed rows ([] when the hook saw nothing or failed). */
  finish(): Promise<NativeKeyRow[]>;
  /** Stop recording and drop the rows (writer closed without sending). */
  cancel(): void;
}

/** Start native IME timing, or null outside the Android app (the DOM timings are used alone). */
export function startNativeKeystrokes(): NativeKeystrokeSession | null {
  const p = plugin();
  if (!p) return null;
  const started = sample(() => p.start()).catch(() => null);
  let done = false;
  const stop = async () => {
    const begun = await started;
    if (!begun) return null;
    try {
      const ended = await sample(() => p.stop({ session: begun.res.session }));
      return { rows: ended.res.rows ?? [], syncs: [begun.sync, ended.sync] };
    } catch {
      return null;
    }
  };
  return {
    async finish() {
      if (done) return [];
      done = true;
      const r = await stop();
      return r ? toDomClock(r.rows, r.syncs) : [];
    },
    cancel() {
      if (done) return;
      done = true;
      void stop();
    },
  };
}
