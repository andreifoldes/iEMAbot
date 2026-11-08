/**
 * KeystrokeRecorder — a focused writing modal for `record_keystrokes` questions, used by the
 * Telegram handoff page (HandoffApp), which has no chat footer. In the participant app the same
 * question is answered in the chat footer instead (KeystrokeComposer).
 *
 * Capture and the ~2-minute writing nudge live in useKeystrokeCapture; on Save the log is
 * serialised to a text/CSV blob and handed back as (identifier, blob, transcript, captureMode).
 */

import { motion } from 'motion/react';
import { X, Check } from 'lucide-react';
import { cn, splitQuestionHtml } from '../lib/utils';
import { sanitizeHtml } from '../lib/sanitizeHtml';
import { useDialogA11y } from '../lib/useDialogA11y';
import { type CaptureMode } from '../lib/keystrokeCapture';
import { newKeystrokeIdentifier } from '../lib/keystrokeUploads';
import { nudgeHint, useKeystrokeCapture } from '../lib/useKeystrokeCapture';
import { t } from '../lib/i18n';
import type { PreloadedQuestion } from '../types';

/** First line of the (rich-text/HTML) prompt — used as the compact modal title. */
function firstLine(html: string): string {
  const text = html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(div|p|h[1-6]|li|tr)>/gi, '\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&');
  const line = text.split('\n').map((s) => s.trim()).find(Boolean);
  return line || t('pwa_keys_your_answer');
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

  const { textareaRef, progress, reached, lengthShort, idle, hasText, finish } = useKeystrokeCapture(minChars);

  const commit = async () => {
    const res = await finish();
    if (!res) return;
    const blob = new Blob([res.csv], { type: 'text/csv' });
    onSave(newKeystrokeIdentifier(), blob, res.transcript, res.captureMode);
  };


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
        // With a prompt image the sheet fills the whole screen height on phones (as the
        // voice recorder does): the picture gets ~30% of the viewport (tap it for the
        // fullscreen lightbox) while the writing area stays large; instructions cap +
        // scroll rather than squeeze either.
        className={cn(
          'relative w-full sm:max-w-md flex flex-col overflow-y-auto bg-white dark:bg-surface-container-lowest shadow-2xl px-6 pb-7',
          promptImage
            ? 'h-dvh max-h-dvh sm:h-auto sm:max-h-[94dvh] sm:rounded-3xl pt-[max(1rem,env(safe-area-inset-top))]'
            : 'max-h-[94dvh] rounded-t-3xl sm:rounded-3xl pt-4',
        )}
      >
        {!promptImage && <div className="mx-auto mb-3 h-1.5 w-10 rounded-full bg-outline-variant/50 sm:hidden" />}
        <div className="relative flex items-center justify-center min-h-8 shrink-0">
          {/* Wraps rather than truncating: the prompt must be readable in full. */}
          <h2 className="text-lg font-bold text-on-surface text-center leading-snug px-9">{title}</h2>
          <button
            onClick={onCancel}
            aria-label={t('pwa_keys_close')}
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
            dangerouslySetInnerHTML={{ __html: sanitizeHtml(detail) }}
          />
        )}

        <textarea
          ref={textareaRef}
          rows={7}
          aria-label={title || t('pwa_keys_your_answer')}
          placeholder={t('pwa_keys_placeholder')}
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
          {nudgeHint({ idle, reached, lengthShort })}
        </p>

        <div className="mt-3 shrink-0 flex justify-end">
          <button
            onClick={() => void commit()}
            disabled={!hasText}
            className="flex items-center gap-2 bg-primary text-on-primary font-bold px-6 py-3 rounded-full active:scale-95 transition disabled:opacity-40"
          >
            <Check size={20} aria-hidden="true" />
            {t('pwa_keys_save')}
          </button>
        </div>
      </motion.div>
    </div>
  );
}
