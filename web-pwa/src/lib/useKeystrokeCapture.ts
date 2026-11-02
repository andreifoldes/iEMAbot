/**
 * useKeystrokeCapture — the DOM adapter shared by the `record_keystrokes` writers: the chat
 * footer composer (KeystrokeComposer) and the Telegram handoff modal (KeystrokeRecorder).
 *
 * A plain <textarea> is wired to KeystrokeRecorderCore via native listeners (keydown/keyup/
 * beforeinput/focus/blur + document visibilitychange), which builds the content-free classed
 * event log. Inside the Android app the soft-keyboard rows come from the native IME hook instead
 * (nativeKeystrokes.ts), which stamps them before the WebView's renderer and JS add latency.
 *
 * A soft, non-blocking nudge encourages ~2 minutes of continuous writing so the text answer
 * is comparably substantial to the ~5-minute voice memo. It tracks *active* writing time
 * (idle gaps don't count) and exposes a progress value + a gentle re-engagement flag on a lull —
 * never a hard countdown, and sending is always available (this is the optional fallback).
 * When the question sets `min_length`, a character floor is folded into the same nudge:
 * progress completes only once both time and length targets are met.
 */

import { useEffect, useRef, useState } from 'react';
import { KeystrokeRecorderCore, type CaptureMode } from './keystrokeCapture';
import { startNativeKeystrokes, type NativeKeystrokeSession } from './nativeKeystrokes';

/** Soft nudge target: ~2 min of *active* writing (parity with the ~5-min voice memo). */
const TARGET_MS = 120_000;
/** Gaps longer than this don't count toward "continuous" active-writing time. */
const IDLE_GAP_MS = 8_000;
/** After this long without a keystroke (and below target), show a gentle re-engagement line. */
const IDLE_HINT_MS = 12_000;
const TICK_MS = 500;

export interface KeystrokeCapture {
  csv: string;
  transcript: string;
  captureMode: CaptureMode;
}

/** The nudge line under the progress bar. */
export function nudgeHint(n: { idle: boolean; reached: boolean; lengthShort: boolean }): string {
  return n.idle
    ? 'Still with you? Keep writing whatever comes to mind — no need for polish.'
    : n.reached
      ? "That's plenty — finish whenever you're ready, or keep going."
      : n.lengthShort
        ? 'Almost there — a few more sentences will do.'
        : 'Try to keep writing continuously for about two minutes.';
}

export function useKeystrokeCapture(minChars: number) {
  const [progress, setProgress] = useState(0);
  const [lengthShort, setLengthShort] = useState(false);
  const [idle, setIdle] = useState(false);
  const [hasText, setHasText] = useState(false);

  const coreRef = useRef<KeystrokeRecorderCore | null>(null);
  if (!coreRef.current) coreRef.current = new KeystrokeRecorderCore();
  const nativeRef = useRef<NativeKeystrokeSession | null>(null);
  const textareaRef = useRef<HTMLTextAreaElement | null>(null);
  const committedRef = useRef(false);
  // Nudge accounting (refs so per-keystroke updates don't re-render).
  const activeMsRef = useRef(0);
  const lastKeyMsRef = useRef<number | null>(null);
  const hasTextRef = useRef(false);
  const charCountRef = useRef(0);

  useEffect(() => {
    const ta = textareaRef.current;
    const core = coreRef.current;
    if (!ta || !core) return;
    const native = startNativeKeystrokes();
    nativeRef.current = native;

    const evTs = (e: Event): number => (e.timeStamp > 0 ? e.timeStamp : performance.now());
    const onKeyDown = (e: KeyboardEvent) =>
      core.onKeyDown({ key: e.key, code: e.code, keyCode: e.keyCode, timeStamp: evTs(e), isComposing: e.isComposing, repeat: e.repeat });
    const onKeyUp = (e: KeyboardEvent) =>
      core.onKeyUp({ key: e.key, code: e.code, keyCode: e.keyCode, timeStamp: evTs(e), isComposing: e.isComposing });
    const onBeforeInput = (e: InputEvent) =>
      core.onBeforeInput({ inputType: e.inputType, data: e.data, timeStamp: evTs(e) });
    const onFocus = (e: FocusEvent) => core.onFocus(evTs(e));
    const onBlur = (e: FocusEvent) => core.onBlur(evTs(e));
    // Nudge: the cross-platform `input` event fires once per real content change (no physical/soft
    // double count). Accumulate active-writing time, capping any single gap at IDLE_GAP_MS.
    const onInput = () => {
      const now = performance.now();
      const last = lastKeyMsRef.current;
      if (last != null) activeMsRef.current += Math.min(now - last, IDLE_GAP_MS);
      lastKeyMsRef.current = now;
      charCountRef.current = ta.value.trim().length;
      const nowHasText = ta.value.length > 0;
      if (nowHasText !== hasTextRef.current) { hasTextRef.current = nowHasText; setHasText(nowHasText); }
    };
    const onVis = () => {
      const now = performance.now();
      if (document.hidden) core.onBlur(now);
      else if (document.activeElement === ta) core.onFocus(now);
    };

    ta.addEventListener('keydown', onKeyDown);
    ta.addEventListener('keyup', onKeyUp);
    ta.addEventListener('beforeinput', onBeforeInput as EventListener);
    ta.addEventListener('focus', onFocus);
    ta.addEventListener('blur', onBlur);
    ta.addEventListener('input', onInput);
    document.addEventListener('visibilitychange', onVis);
    ta.focus(); // anchors t0 and emits the opening focus_gained marker

    const iv = setInterval(() => {
      // Overall progress is the lower of the time and length targets, so the bar only
      // completes once both are met (length target is a no-op when minChars is 0).
      const timeProgress = Math.min(1, activeMsRef.current / TARGET_MS);
      const lengthProgress = minChars > 0 ? Math.min(1, charCountRef.current / minChars) : 1;
      setProgress(Math.min(timeProgress, lengthProgress));
      setLengthShort(timeProgress >= 1 && lengthProgress < 1);
      const lk = lastKeyMsRef.current;
      setIdle(lk != null && performance.now() - lk > IDLE_HINT_MS && (timeProgress < 1 || lengthProgress < 1));
    }, TICK_MS);

    return () => {
      clearInterval(iv);
      ta.removeEventListener('keydown', onKeyDown);
      ta.removeEventListener('keyup', onKeyUp);
      ta.removeEventListener('beforeinput', onBeforeInput as EventListener);
      ta.removeEventListener('focus', onFocus);
      ta.removeEventListener('blur', onBlur);
      ta.removeEventListener('input', onInput);
      document.removeEventListener('visibilitychange', onVis);
      // Closed without sending: stop the native recording (no-op after finish()).
      native?.cancel();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  /** Close the log and take the answer; null when there is nothing to send (or it was sent). */
  const finish = async (): Promise<KeystrokeCapture | null> => {
    const core = coreRef.current;
    const ta = textareaRef.current;
    if (committedRef.current || !core || !ta) return null;
    const transcript = ta.value;
    if (!transcript.trim()) return null;
    committedRef.current = true;
    const nativeRows = nativeRef.current ? await nativeRef.current.finish() : [];
    const { csv, captureMode } = core.finish(transcript, nativeRows);
    return { csv, transcript, captureMode };
  };

  return { textareaRef, progress, reached: progress >= 1, lengthShort, idle, hasText, finish };
}
