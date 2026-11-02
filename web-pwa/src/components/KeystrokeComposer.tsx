/**
 * KeystrokeComposer — the chat footer's writing mode for `record_keystrokes` questions.
 *
 * Tapping "Write your answer" on the question card swaps the footer's one-line input for this
 * messenger-style composer: a textarea that grows with the text (up to about six lines, then
 * scrolls, as in WhatsApp), the ~2-minute writing nudge above it, and the Send button. Sending
 * *is* saving: there is no separate Save step, and the answer settles into the thread as the
 * participant's message. Enter makes a new line (long answers have paragraphs); Ctrl/Cmd+Enter
 * sends from a hardware keyboard.
 *
 * Capture (DOM listeners, and the Android app's native IME timing) lives in useKeystrokeCapture.
 */

import { useState, type ReactNode } from 'react';
import { Send } from 'lucide-react';
import { cn } from '../lib/utils';
import { type CaptureMode } from '../lib/keystrokeCapture';
import { newKeystrokeIdentifier } from '../lib/keystrokeUploads';
import { nudgeHint, useKeystrokeCapture } from '../lib/useKeystrokeCapture';
import type { PreloadedQuestion } from '../types';

interface Props {
  question: PreloadedQuestion;
  /** The footer's quick-actions menu, kept at the start of the row. */
  menu: ReactNode;
  onSend: (identifier: number, blob: Blob, transcript: string, captureMode: CaptureMode) => void;
}

export function KeystrokeComposer({ question, menu, onSend }: Props) {
  const { textareaRef, progress, reached, lengthShort, idle, hasText, finish } =
    useKeystrokeCapture(question.min_length ?? 0);
  const [sending, setSending] = useState(false);

  // Grow with the content; the max-height class caps it and the textarea scrolls beyond that.
  const grow = () => {
    const ta = textareaRef.current;
    if (!ta) return;
    ta.style.height = 'auto';
    ta.style.height = `${ta.scrollHeight}px`;
  };

  const send = async () => {
    if (sending) return;
    setSending(true);
    const res = await finish();
    if (!res) { setSending(false); return; }
    onSend(newKeystrokeIdentifier(), new Blob([res.csv], { type: 'text/csv' }), res.transcript, res.captureMode);
  };

  return (
    <div>
      {/* Soft nudge: progress toward ~2 min of continuous writing (no numeric countdown). */}
      <div className="mb-2.5 px-1">
        <div className="h-1.5 w-full rounded-full bg-surface-container-high overflow-hidden" aria-hidden="true">
          <div
            className={cn('h-full rounded-full transition-[width] duration-500', reached ? 'bg-primary' : 'bg-primary/70')}
            style={{ width: `${Math.round(progress * 100)}%` }}
          />
        </div>
        <p className="mt-1.5 text-center text-xs text-on-surface-variant" aria-live="polite">
          {nudgeHint({ idle, reached, lengthShort })}
        </p>
      </div>
      <div className="flex items-end gap-2">
        {menu}
        <textarea
          ref={textareaRef}
          rows={1}
          onInput={grow}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); void send(); }
          }}
          aria-label="Write your answer"
          placeholder="Write your answer…"
          className="flex-1 min-w-0 block resize-none overflow-y-auto max-h-[156px] bg-surface-container-high rounded-[23px] px-4 py-3 text-[15px] leading-[22px] text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-2 focus:ring-primary/50"
        />
        <button
          onClick={() => void send()}
          disabled={!hasText || sending}
          className="mb-0.5 bg-primary text-on-primary p-3 rounded-full active:scale-95 hover:brightness-110 transition-all disabled:opacity-40 disabled:active:scale-100 shrink-0"
          aria-label="Send"
        >
          <Send size={18} aria-hidden="true" />
        </button>
      </div>
    </div>
  );
}
