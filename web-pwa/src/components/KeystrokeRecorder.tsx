/**
 * KeystrokeRecorder — a focused writing modal for `record_keystrokes` questions (the
 * voice-memo skip fallback: answer by typing, log the keystroke *dynamics* not the content).
 *
 * A plain <textarea> is wired to KeystrokeRecorderCore via native listeners (keydown/keyup/
 * beforeinput/focus/blur + document visibilitychange), which builds the content-free classed
 * event log. On Save we serialise the log to a text/CSV blob and hand back
 * (identifier, blob, transcript, captureMode); the typed answer text is kept separate.
 *
 * A soft, non-blocking nudge encourages ~2 minutes of continuous writing so the text answer
 * is comparably substantial to the ~5-minute voice memo. It tracks *active* writing time
 * (idle gaps don't count) and shows a progress cue + a gentle re-engagement line on a lull —
 * never a hard countdown, and Save is always available (this is the optional fallback).
 * When the question sets `min_length`, a character floor is folded into the same nudge:
 * the progress bar completes only once both time and length targets are met.
 */

import { useEffect, useRef, useState } from 'react';
import { motion } from 'motion/react';
import { X, Check } from 'lucide-react';
import { cn, splitQuestionHtml } from '../lib/utils';
import { useDialogA11y } from '../lib/useDialogA11y';
import { KeystrokeRecorderCore, type CaptureMode } from '../lib/keystrokeCapture';
import { newKeystrokeIdentifier } from '../lib/keystrokeUploads';
import type { PreloadedQuestion } from '../types';

/** Soft nudge target: ~2 min of *active* writing (parity with the ~5-min voice memo). */
const TARGET_MS = 120_000;
/** Gaps longer than this don't count toward "continuous" active-writing time. */
const IDLE_GAP_MS = 8_000;
/** After this long without a keystroke (and below target), show a gentle re-engagement line. */
const IDLE_HINT_MS = 12_000;
const TICK_MS = 500;

/** First line of the (rich-text/HTML) prompt — used as the compact modal title. */
function firstLine(html: string): string {
  const text = html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(div|p|h[1-6]|li|tr)>/gi, '\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&');
  const line = text.split('\n').map((s) => s.trim()).find(Boolean);
  return line || 'Your answer';
}

interface Props {
  question: PreloadedQuestion;
  reduceMotion: boolean;
  onCancel: () => void;
  onSave: (identifier: number, blob: Blob, transcript: string, captureMode: CaptureMode) => void;
}

export function KeystrokeRecorder({ question, reduceMotion, onCancel, onSave }: Props) {
  const dialogRef = useDialogA11y<HTMLDivElement>(true, onCancel);
  const minChars = question.min_length ?? 0;
  // Picture-description prompts embed the image mid-text: the pitch before it
  // stays on the chat card, while the picture and the instructions after it
  // are shown here. The esmira-rich wrapper makes the image tap-to-enlarge via
  // App's delegated lightbox handler.
  const { intro, image: promptImage, detail } = splitQuestionHtml(question.text || '');
  const title = firstLine(intro || question.text || '');

  const [progress, setProgress] = useState(0);
  const [lengthShort, setLengthShort] = useState(false);
  const [idle, setIdle] = useState(false);
  const [hasText, setHasText] = useState(false);

  const coreRef = useRef<KeystrokeRecorderCore | null>(null);
  if (!coreRef.current) coreRef.current = new KeystrokeRecorderCore();
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
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const commit = () => {
    if (committedRef.current) return;
    const core = coreRef.current;
    const ta = textareaRef.current;
    if (!core || !ta) return;
    const transcript = ta.value;
    if (!transcript.trim()) return; // nothing to save yet
    committedRef.current = true;
    const { csv, captureMode } = core.finish(transcript);
    const blob = new Blob([csv], { type: 'text/csv' });
    onSave(newKeystrokeIdentifier(), blob, transcript, captureMode);
  };

  const reached = progress >= 1;

  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
      <div className="absolute inset-0 bg-black/40" onClick={onCancel} aria-hidden="true" />
      <motion.div
        ref={dialogRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        initial={reduceMotion ? { opacity: 1 } : { opacity: 0, y: 24 }}
        animate={{ opacity: 1, y: 0 }}
        // With a prompt image the sheet goes full-height: the picture gets ~30%
        // of the viewport (tap it for the fullscreen lightbox) while the writing
        // area stays large; instructions cap + scroll rather than squeeze either.
        className={cn(
          'relative w-full sm:max-w-md flex flex-col max-h-[92dvh] overflow-y-auto bg-white dark:bg-surface-container-lowest rounded-t-3xl sm:rounded-3xl shadow-2xl px-6 pt-4 pb-7',
          promptImage && 'h-[92dvh] sm:h-auto',
        )}
      >
        <div className="mx-auto mb-3 h-1.5 w-10 rounded-full bg-outline-variant/50 sm:hidden" />
        <div className="relative flex items-center justify-center min-h-8">
          <h2 className="text-lg font-bold text-on-surface text-center truncate px-9">{title}</h2>
          <button
            onClick={onCancel}
            aria-label="Close"
            className="absolute right-0 top-1/2 -translate-y-1/2 p-1.5 rounded-full text-on-surface-variant hover:bg-surface-container-high active:scale-95 transition"
          >
            <X size={22} aria-hidden="true" />
          </button>
        </div>

        {promptImage && (
          // Full-bleed (-mx-6 cancels the card padding) so the picture reaches
          // the width-bound maximum its 4:3 aspect allows on a phone.
          <div className="esmira-rich mt-2 -mx-6 shrink-0 flex justify-center">
            <img
              src={promptImage.src}
              alt={promptImage.alt}
              draggable={false}
              className="w-full max-h-[30dvh] object-contain"
            />
          </div>
        )}
        {detail && (
          // Capped + scrollable so long instructions never squeeze the picture
          // or shrink the writing area.
          <div
            className="mt-3 max-h-14 overflow-y-auto shrink-0 text-sm text-on-surface-variant leading-relaxed esmira-rich"
            dangerouslySetInnerHTML={{ __html: detail }}
          />
        )}

        <textarea
          ref={textareaRef}
          rows={7}
          aria-label={title || 'Your answer'}
          placeholder="Start typing your answer here…"
          className="mt-4 w-full flex-1 min-h-36 h-44 sm:h-52 resize-none rounded-2xl border border-outline-variant/60 bg-surface-container-lowest px-4 py-3 text-on-surface placeholder:text-on-surface-variant/60 focus:outline-none focus:ring-2 focus:ring-primary"
        />

        {/* Soft nudge: progress toward ~2 min of continuous writing (no numeric countdown). */}
        <div className="mt-4 h-1.5 w-full shrink-0 rounded-full bg-surface-container-high overflow-hidden" aria-hidden="true">
          <div
            className={cn('h-full rounded-full transition-[width] duration-500', reached ? 'bg-primary' : 'bg-primary/70')}
            style={{ width: `${Math.round(progress * 100)}%` }}
          />
        </div>
        <p className="mt-2 shrink-0 text-center text-xs text-on-surface-variant" aria-live="polite">
          {idle
            ? 'Still with you? Keep writing whatever comes to mind — no need for polish.'
            : reached
              ? "That's plenty — finish whenever you're ready, or keep going."
              : lengthShort
                ? 'Almost there — a few more sentences will do.'
                : 'Try to keep writing continuously for about two minutes.'}
        </p>

        <div className="mt-3 shrink-0 flex justify-end">
          <button
            onClick={commit}
            disabled={!hasText}
            className="flex items-center gap-2 bg-primary text-on-primary font-bold px-6 py-3 rounded-full active:scale-95 transition disabled:opacity-40"
          >
            <Check size={20} aria-hidden="true" />
            Save
          </button>
        </div>
      </motion.div>
    </div>
  );
}
