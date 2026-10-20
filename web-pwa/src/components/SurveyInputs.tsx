/**
 * SurveyInputs — renders the current question's input controls inside a
 * chat-style card. Ported from the iEMAbot PWA's OfflineSurveyPanel, adapted to
 * ESMira's web-renderable input set (native time/date/number/va_scale pickers
 * instead of iEMAbot's webview tokens). Plain `text` questions are answered via
 * the footer (handled in App), matching the iEMAbot chat experience.
 */

import { useEffect, useState } from 'react';
import { motion } from 'motion/react';
import { ChevronRight, Check, Play, Mic, PenLine, Volume2, Square } from 'lucide-react';
import { cn, splitQuestionHtml } from '../lib/utils';
import { speak, speechAvailable, stopSpeaking } from '../lib/speech';
import type { PreloadedQuestion } from '../types';

const OPTION_ICONS: Record<string, string> = {
  'Work': '💼', 'Household': '🏠', 'Self-care': '🧴', 'Relaxation': '😌',
  'Sport/physical activity': '🏃', 'Eating/drinking': '🍽️',
  'Traveling/on the way': '🚗', 'In a conversation': '💬',
  'Something else': '📝', 'Nothing': '💤',
  'Indoors': '🏠', 'Home': '🏠', 'Work/Office': '🏢',
  'School/University': '🎓', 'Outdoors': '🌳', 'In transit': '🚌',
  'Shop/Errands': '🛒', 'Restaurant/Cafe': '☕',
  "Someone else's home": '🏡', 'Gym/Sports facility': '🏋️', 'Other': '📍',
};

// Scale-anchor labels and explanatory subtext sit one step below the body text. The
// text-size setting scales the root font size, so these rem-based classes grow with it.
const anchorLabelClass = (_textSizeClass: string): string => 'text-sm';
const subtextClass = (_textSizeClass: string): string => 'text-base';

// Question text is rich-text HTML; strip tags so it's safe to use in aria-labels
// (a screen reader would otherwise announce literal "<div>" markup).
const plainText = (html: string): string =>
  html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

/** "Listen" button: reads the researcher's `spoken_text` aloud; tap again to stop. */
function ListenButton({ text }: { text: string }) {
  const [speaking, setSpeaking] = useState(false);
  // Stop reading when the question goes away.
  useEffect(() => stopSpeaking, []);
  if (!speechAvailable()) return null;
  return (
    <button
      type="button"
      onClick={() => {
        if (speaking) { stopSpeaking(); setSpeaking(false); return; }
        setSpeaking(true);
        speak(text, document.documentElement.lang, () => setSpeaking(false));
      }}
      aria-pressed={speaking}
      className="mb-3 inline-flex items-center gap-2 min-h-11 px-4 rounded-full bg-secondary-container text-on-secondary-container font-semibold text-sm active:scale-95 transition-all"
    >
      {speaking ? <Square size={16} aria-hidden="true" /> : <Volume2 size={18} aria-hidden="true" />}
      {speaking ? 'Stop' : 'Listen'}
    </button>
  );
}

interface Props {
  question: PreloadedQuestion;
  progress: number;
  textSizeClass: string;
  reduceMotion: boolean;
  onRespond: (id: string, value: string) => void;
  onContinueInfo: () => void;
  onOpenWebview: (url: string, title: string) => void;
  onOpenRecorder: () => void;
  /** Skip a voice memo — reveals its keystroke-text fallback if it has one, else plain skip. */
  onSkipAudio: () => void;
  /** Open the keystroke-logging writing modal for a `keystroke_text` question. */
  onOpenKeystroke: () => void;
  /** Study option `webTaskActions`: a task card also offers Remind me later and Skip check-in. */
  taskActions?: TaskActions;
}

export interface TaskActions {
  onRemindLater: () => void;
  onSkipCheckin: () => void;
}

export function SurveyInputs({
  question,
  progress,
  textSizeClass,
  reduceMotion,
  onRespond,
  onContinueInfo,
  onOpenWebview,
  onOpenRecorder,
  onSkipAudio,
  onOpenKeystroke,
  taskActions,
}: Props) {
  return (
    <div data-live-question className="pb-4 pt-2 self-start max-w-[85%] w-[85%]">
      {/* Progress bar */}
      <div className="mb-3 flex items-center gap-2">
        <div className="flex-1 h-1.5 bg-surface-container-high rounded-full overflow-hidden">
          <motion.div
            className="h-full bg-primary rounded-full"
            initial={{ width: 0 }}
            animate={{ width: `${progress * 100}%` }}
            transition={{ duration: reduceMotion ? 0 : 0.3 }}
          />
        </div>
        <span className="text-[0.75rem] font-bold text-on-surface-variant">
          {Math.round(progress * 100)}%
        </span>
      </div>

      <motion.div
        key={question.id}
        initial={reduceMotion ? { opacity: 1 } : { opacity: 0, y: 10, scale: 0.95 }}
        animate={{ opacity: 1, y: 0, scale: 1 }}
        className="self-start w-full"
      >
        {question.type === 'cognitive' ? (
          <CognitiveCard
            question={question}
            textSizeClass={textSizeClass}
            onOpenWebview={onOpenWebview}
            onContinue={onContinueInfo}
            taskActions={taskActions}
          />
        ) : (
        <div
          role="group"
          aria-label={question.aria_label || plainText(question.text) || undefined}
          className="bg-white dark:bg-surface-container-lowest border-l-4 border-primary p-5 rounded-xl rounded-tl-none shadow-lg w-full"
        >
          {question.type === 'info' ? (
            <div
              className={cn('text-on-surface leading-relaxed esmira-rich', textSizeClass)}
              dangerouslySetInnerHTML={{ __html: question.text }}
            />
          ) : question.is_html ? (
            // Every question's text is authored in ESMira's rich-text editor, so it
            // may contain HTML (<div>/<br>/<b>…). Render it as rich text rather than
            // escaped plain text, otherwise participants see literal markup. Covers
            // every input type's label, not just voice memos.
            // Audio/typed questions with an embedded image (picture-description
            // tasks) show only the pitch before the image here — the picture and
            // the detailed instructions live inside the recorder/writing modal.
            <div
              className={cn('font-semibold text-on-surface leading-snug mb-2 esmira-rich', textSizeClass)}
              dangerouslySetInnerHTML={{
                __html:
                  question.type === 'audio' || question.type === 'keystroke_text'
                    ? splitQuestionHtml(question.text).intro
                    : question.text,
              }}
            />
          ) : (
            <p className={cn('font-semibold text-on-surface leading-snug mb-2 whitespace-pre-wrap', textSizeClass)}>
              {question.text}
            </p>
          )}
          {question.type !== 'info' && question.subtext && (
            // Explanatory helper text: de-emphasised (muted, regular weight, relaxed
            // leading) so the primary question above stays visually dominant. Rich HTML
            // like the question text, authored in ESMira's editor.
            <div
              className={cn('text-on-surface-variant leading-relaxed esmira-rich mb-2', subtextClass(textSizeClass))}
              dangerouslySetInnerHTML={{ __html: question.subtext }}
            />
          )}
          {question.spoken_text && <ListenButton key={question.id} text={question.spoken_text} />}
          {question.type !== 'info' && !question.required && (
            <p className="text-xs text-on-surface-variant italic mb-2">(Optional)</p>
          )}

          {question.type === 'likert' && <LikertInput question={question} onRespond={onRespond} labelSizeClass={anchorLabelClass(textSizeClass)} />}
          {question.type === 'yesno' && <YesNoInput question={question} onRespond={onRespond} />}
          {question.type === 'choice' && question.options && <ChoiceList question={question} onRespond={onRespond} />}
          {question.type === 'multi_choice' && question.options && <MultiChoice question={question} onRespond={onRespond} />}
          {question.type === 'number' && <NumberInput question={question} onRespond={onRespond} />}
          {question.type === 'time' && <TimeInput question={question} onRespond={onRespond} />}
          {question.type === 'duration' && <DurationInput question={question} onRespond={onRespond} />}
          {question.type === 'date' && <DateInput question={question} onRespond={onRespond} />}
          {question.type === 'va_scale' && <VaScale question={question} onRespond={onRespond} labelSizeClass={anchorLabelClass(textSizeClass)} />}
          {question.type === 'audio' && <AudioCard question={question} onOpenRecorder={onOpenRecorder} onSkip={onSkipAudio} />}
          {question.type === 'keystroke_text' && <KeystrokeCard question={question} onWrite={onOpenKeystroke} onSkip={onContinueInfo} />}
          {question.type === 'text' && (
            <p className="text-sm text-on-surface-variant mt-2">Type your response below.</p>
          )}
          {question.type === 'info' && (
            <button
              onClick={onContinueInfo}
              className="w-full mt-4 bg-primary text-on-primary font-bold py-3 rounded-full flex items-center justify-center gap-2 hover:brightness-110 transition-all active:scale-95"
            >
              Continue
            </button>
          )}
        </div>
        )}
      </motion.div>
    </div>
  );
}

// ── Sub-components ──────────────────────────────────────────────

function CognitiveCard({
  question,
  textSizeClass,
  onOpenWebview,
  onContinue,
  taskActions,
}: {
  question: PreloadedQuestion;
  textSizeClass: string;
  onOpenWebview: (url: string, title: string) => void;
  onContinue: () => void;
  taskActions?: TaskActions;
}) {
  const title = question.title || 'Assessment';
  const label = question.launch_label || `Start the ${title}`;
  return (
    <div className="bg-white dark:bg-surface-container-lowest border border-outline-variant/30 rounded-2xl rounded-tl-none overflow-hidden shadow-xl w-full">
      {/* Hero header */}
      <div className="min-h-14 bg-primary relative overflow-hidden">
        <div className="bg-gradient-to-t from-primary/80 to-transparent flex items-center px-4 min-h-14 py-3">
          <h3 className={cn('text-on-primary font-bold leading-snug', textSizeClass)}>{title}</h3>
        </div>
      </div>
      {/* Body */}
      <div className="p-4 flex flex-col gap-4">
        {question.description && (
          <p className="text-sm text-on-surface-variant leading-relaxed whitespace-pre-wrap">{question.description}</p>
        )}
        {!question.required && (
          <p className="text-xs text-on-surface-variant italic">(Optional)</p>
        )}
        {taskActions ? (
          // Four actions in two rows, as under a task in the Telegram bot.
          <div className="grid grid-cols-2 gap-2">
            <button
              onClick={() => onOpenWebview(question.launch_url!, title)}
              className="bg-primary text-on-primary font-bold py-3 rounded-full flex items-center justify-center gap-2 hover:brightness-110 transition-all active:scale-95"
            >
              <Play size={18} aria-hidden="true" />
              Start task
            </button>
            <button
              onClick={onContinue}
              className="bg-surface-container-high text-on-surface font-semibold py-3 rounded-full transition-all active:scale-95 hover:bg-surface-container-highest"
            >
              Skip task
            </button>
            <button
              onClick={taskActions.onRemindLater}
              className="bg-surface-container-high text-on-surface font-semibold py-3 rounded-full transition-all active:scale-95 hover:bg-surface-container-highest"
            >
              Remind me later
            </button>
            <button
              onClick={taskActions.onSkipCheckin}
              className="bg-surface-container-high text-on-surface font-semibold py-3 rounded-full transition-all active:scale-95 hover:bg-surface-container-highest"
            >
              Skip check-in
            </button>
          </div>
        ) : (
          <>
            <button
              onClick={() => onOpenWebview(question.launch_url!, title)}
              className="w-full bg-primary text-on-primary font-bold py-3 rounded-full flex items-center justify-center gap-2 hover:brightness-110 transition-all active:scale-95"
            >
              <Play size={18} aria-hidden="true" />
              {label}
            </button>
            <button
              onClick={onContinue}
              className="w-full bg-surface-container-high text-on-surface font-semibold py-3 rounded-full transition-all active:scale-95 hover:bg-surface-container-highest"
            >
              Skip
            </button>
          </>
        )}
      </div>
    </div>
  );
}

// Voice-memo launch card. The actual recorder is a modal owned by App (opened via
// onOpenRecorder), matching how cognitive tasks launch their overlay. Optional
// questions can be skipped without recording (onSkip → engine.skip()).
function AudioCard({
  question,
  onOpenRecorder,
  onSkip,
}: {
  question: PreloadedQuestion;
  onOpenRecorder: () => void;
  onSkip: () => void;
}) {
  return (
    <div className="mt-4 flex flex-col gap-2.5">
      <button
        onClick={onOpenRecorder}
        className="w-full bg-primary text-on-primary font-bold py-3 rounded-full flex items-center justify-center gap-2 hover:brightness-110 transition-all active:scale-95"
      >
        <Mic size={18} aria-hidden="true" />
        Record voice memo
      </button>
      {!question.required && (
        <button
          onClick={onSkip}
          className="w-full bg-surface-container-high text-on-surface font-semibold py-3 rounded-full transition-all active:scale-95 hover:bg-surface-container-highest"
        >
          Skip
        </button>
      )}
    </div>
  );
}

// KeystrokeCard — launches the writing modal (KeystrokeRecorder via onWrite) for a
// `record_keystrokes` answer, mirroring AudioCard. Usually shown as a voice-memo skip
// fallback; still skippable when optional (onSkip → engine.skip()).
function KeystrokeCard({
  question,
  onWrite,
  onSkip,
}: {
  question: PreloadedQuestion;
  onWrite: () => void;
  onSkip: () => void;
}) {
  return (
    <div className="mt-4 flex flex-col gap-2.5">
      <button
        onClick={onWrite}
        className="w-full bg-primary text-on-primary font-bold py-3 rounded-full flex items-center justify-center gap-2 hover:brightness-110 transition-all active:scale-95"
      >
        <PenLine size={18} aria-hidden="true" />
        Write your answer
      </button>
      {!question.required && (
        <button
          onClick={onSkip}
          className="w-full bg-surface-container-high text-on-surface font-semibold py-3 rounded-full transition-all active:scale-95 hover:bg-surface-container-highest"
        >
          Skip
        </button>
      )}
    </div>
  );
}

function LikertInput({ question, onRespond, labelSizeClass }: { question: PreloadedQuestion; onRespond: (id: string, v: string) => void; labelSizeClass: string }) {
  const min = question.scale_min ?? 1;
  const max = question.scale_max ?? 5;
  const values = Array.from({ length: max - min + 1 }, (_, i) => min + i);
  const groupLabel = `Rate from ${min}${question.scale_min_label ? ` (${question.scale_min_label})` : ''} `
    + `to ${max}${question.scale_max_label ? ` (${question.scale_max_label})` : ''}`;
  const btnClass = 'min-w-11 w-full h-11 rounded-full bg-surface-container-high hover:bg-primary hover:text-on-primary transition-all font-bold flex items-center justify-center active:scale-90';
  // Scales of 5+ points move the end labels to a legend (WCAG 1.4.10 reflow). A
  // 5-point scale always stays on one row (buttons shrink below 44px on very narrow
  // screens or large text sizes); longer scales wrap into a grid to keep 44px targets.
  if (values.length > 4) {
    const oneRow = values.length <= 5;
    return (
      <div role="group" aria-label={groupLabel} className="mt-3 px-1">
        {(question.scale_min_label || question.scale_max_label) && (
          <p className={cn('mb-2 font-medium text-on-surface-variant', labelSizeClass)}>
            {question.scale_min_label ? `${min} = ${question.scale_min_label}` : ''}
            {question.scale_min_label && question.scale_max_label ? ' · ' : ''}
            {question.scale_max_label ? `${max} = ${question.scale_max_label}` : ''}
          </p>
        )}
        <div className={cn('grid', oneRow ? 'grid-cols-5 gap-1.5' : 'grid-cols-[repeat(auto-fit,minmax(2.75rem,1fr))] gap-2')}>
          {values.map((val) => (
            <button key={val} onClick={() => onRespond(question.id, String(val))} className={cn(btnClass, oneRow && 'min-w-0')}>{val}</button>
          ))}
        </div>
      </div>
    );
  }
  return (
    // Each scale point is an equal-width column (flex-1) so the buttons spread
    // evenly across the card; the anchor label sits centered directly above its
    // end button. min-w-0 + break-words keeps long labels inside their column.
    <div role="group" aria-label={groupLabel} className="flex items-end gap-1 mt-3 px-1">
      {values.map((val) => {
        const label = val === min ? question.scale_min_label : val === max ? question.scale_max_label : '';
        return (
          <div key={val} className="flex-1 min-w-0 flex flex-col items-center gap-1.5">
            {label && (
              <span className={cn('leading-[1.15] text-center font-medium text-on-surface-variant break-words', labelSizeClass)}>
                {label}
              </span>
            )}
            <button onClick={() => onRespond(question.id, String(val))} className={btnClass}>{val}</button>
          </div>
        );
      })}
    </div>
  );
}

function YesNoInput({ question, onRespond }: { question: PreloadedQuestion; onRespond: (id: string, v: string) => void }) {
  const opts = [
    { label: question.yes_label || 'Yes', value: question.yes_value ?? 'Yes', primary: true },
    { label: question.no_label || 'No', value: question.no_value ?? 'No', primary: false },
  ];
  return (
    <div role="group" aria-label="Yes or no" className="flex gap-3 mt-4">
      {opts.map((o) => (
        <button
          key={o.value}
          onClick={() => onRespond(question.id, o.value)}
          className={cn(
            'flex-1 py-3 px-4 rounded-full font-bold text-sm transition-all active:scale-95',
            o.primary ? 'bg-primary text-on-primary' : 'bg-surface-container-high text-on-surface',
          )}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

function ChoiceList({ question, onRespond }: { question: PreloadedQuestion; onRespond: (id: string, v: string) => void }) {
  // Full-height list: every option is rendered (no nested scrollbox), so scales like
  // the KSS are visible in their entirety. Tall lists rely on the page scroll — the
  // auto-scroll in App aligns the question's top when the card exceeds the viewport.
  return (
    <div role="group" aria-label="Answer options" className="mt-4 flex flex-col gap-2">
      {question.options!.map((opt) => (
        <button
          key={opt}
          onClick={() => onRespond(question.id, opt)}
          className="w-full flex items-center justify-between px-4 py-3 bg-surface-container-low hover:bg-surface-container-high rounded-xl transition-colors text-left group shrink-0"
        >
          <span className="font-medium text-sm">
            {OPTION_ICONS[opt] && <span className="mr-2">{OPTION_ICONS[opt]}</span>}
            {opt}
          </span>
          <ChevronRight size={16} className="text-outline-variant group-hover:text-primary" aria-hidden="true" />
        </button>
      ))}
    </div>
  );
}

function MultiChoice({ question, onRespond }: { question: PreloadedQuestion; onRespond: (id: string, v: string) => void }) {
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const toggle = (opt: string) =>
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(opt) ? next.delete(opt) : next.add(opt);
      return next;
    });
  return (
    <div className="mt-4 flex flex-col gap-2">
      <div role="group" aria-label="Select all that apply" className="flex flex-col gap-2">
        {question.options!.map((opt) => {
          const on = selected.has(opt);
          return (
            <button
              key={opt}
              onClick={() => toggle(opt)}
              role="checkbox"
              aria-checked={on}
              className={cn(
                'w-full flex items-center justify-between px-4 py-3 rounded-xl transition-colors text-left shrink-0',
                on ? 'bg-primary text-on-primary' : 'bg-surface-container-low hover:bg-surface-container-high',
              )}
            >
              <span className="font-medium text-sm">{opt}</span>
              {on && <Check size={16} aria-hidden="true" />}
            </button>
          );
        })}
      </div>
      <button
        onClick={() => onRespond(question.id, Array.from(selected).join(','))}
        className="w-full mt-2 bg-primary text-on-primary font-bold py-3 rounded-full transition-all active:scale-95 hover:brightness-110"
      >
        Done
      </button>
    </div>
  );
}

function NumberInput({ question, onRespond }: { question: PreloadedQuestion; onRespond: (id: string, v: string) => void }) {
  const [val, setVal] = useState('');
  return (
    <ConfirmRow disabled={val.trim() === ''} onConfirm={() => onRespond(question.id, val.trim())}>
      <input
        type="number"
        step={question.decimal ? '0.5' : '1'}
        value={val}
        onChange={(e) => setVal(e.target.value)}
        aria-label={plainText(question.text) || 'Enter a number'}
        className="flex-1 bg-surface-container-low rounded-xl px-4 py-3 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/50"
        placeholder="Enter a number"
      />
    </ConfirmRow>
  );
}

// Hour : minute picker built from native <select>s rather than
// <input type="time">. The native time input renders and behaves
// inconsistently across browsers (notably Firefox) and on mobile, so we use
// two selects — the most universally supported control — which work
// identically everywhere. Value is emitted as "HH:MM" (24h), matching
// ESMira's QuestionnaireSaver time/duration format. The minute wheel steps by
// `minute_step` (default 1); the adapter sets 5 for clock-time questions.
const TIME_HOURS = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'));

function TimeInput({ question, onRespond }: { question: PreloadedQuestion; onRespond: (id: string, v: string) => void }) {
  const [hh, setHh] = useState('');
  const [mm, setMm] = useState('');
  const step = question.minute_step ?? 1;
  const minutes = Array.from({ length: Math.floor(60 / step) }, (_, i) => String(i * step).padStart(2, '0'));
  const selectCls =
    'flex-1 min-w-0 bg-surface-container-low rounded-xl px-3 py-3 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/50';
  return (
    <ConfirmRow disabled={hh === '' || mm === ''} onConfirm={() => onRespond(question.id, `${hh}:${mm}`)}>
      <div className="flex flex-1 items-center gap-2">
        <select value={hh} onChange={(e) => setHh(e.target.value)} className={selectCls} aria-label="Hour">
          <option value="" disabled>HH</option>
          {TIME_HOURS.map((h) => <option key={h} value={h}>{h}</option>)}
        </select>
        <span className="font-bold text-on-surface-variant">:</span>
        <select value={mm} onChange={(e) => setMm(e.target.value)} className={selectCls} aria-label="Minute">
          <option value="" disabled>MM</option>
          {minutes.map((m) => <option key={m} value={m}>{m}</option>)}
        </select>
      </div>
    </ConfirmRow>
  );
}

// Duration picker (hours + minutes). Distinct from TimeInput: ESMira's
// `duration` responseType stores the value as the TOTAL NUMBER OF MINUTES
// (a positive integer), not an "HH:MM" clock string. So this emits
// `hours * 60 + minutes` as the answer value.
// Respects optional `max_hours` (default 24) and `minute_step` (default 1).

function DurationInput({ question, onRespond }: { question: PreloadedQuestion; onRespond: (id: string, v: string) => void }) {
  const [h, setH] = useState('');
  const [m, setM] = useState('');
  const maxHours = question.max_hours ?? 24;
  const step = question.minute_step ?? 1;
  const hours = Array.from({ length: maxHours + 1 }, (_, i) => String(i));
  const minutes = Array.from(
    { length: Math.floor(60 / step) },
    (_, i) => String(i * step).padStart(step > 1 ? 2 : 1, '0'),
  );
  const selectCls =
    'flex-1 min-w-0 bg-surface-container-low rounded-xl px-3 py-3 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/50';
  const total = Number(h || 0) * 60 + Number(m || 0);
  return (
    <ConfirmRow disabled={h === '' || m === '' || total < 1} onConfirm={() => onRespond(question.id, String(total))}>
      <div className="flex flex-1 items-center gap-2">
        <select value={h} onChange={(e) => setH(e.target.value)} className={selectCls} aria-label="Hours">
          <option value="" disabled>Hours</option>
          {hours.map((x) => <option key={x} value={x}>{x}</option>)}
        </select>
        <span className="text-sm font-medium text-on-surface-variant">h</span>
        <select value={m} onChange={(e) => setM(e.target.value)} className={selectCls} aria-label="Minutes">
          <option value="" disabled>Minutes</option>
          {minutes.map((x) => <option key={x} value={x}>{x}</option>)}
        </select>
        <span className="text-sm font-medium text-on-surface-variant">min</span>
      </div>
    </ConfirmRow>
  );
}

function DateInput({ question, onRespond }: { question: PreloadedQuestion; onRespond: (id: string, v: string) => void }) {
  const [val, setVal] = useState('');
  return (
    <ConfirmRow disabled={!val} onConfirm={() => onRespond(question.id, val)}>
      <input
        type="date"
        value={val}
        onChange={(e) => setVal(e.target.value)}
        aria-label={plainText(question.text) || 'Select a date'}
        className="flex-1 bg-surface-container-low rounded-xl px-4 py-3 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/50"
      />
    </ConfirmRow>
  );
}

function VaScale({ question, onRespond, labelSizeClass }: { question: PreloadedQuestion; onRespond: (id: string, v: string) => void; labelSizeClass: string }) {
  const max = question.max_value ?? 100;
  const [val, setVal] = useState(Math.round(max / 2));
  return (
    <div className="mt-4 flex flex-col gap-3">
      {(question.scale_min_label || question.scale_max_label) && (
        <div className={cn('flex justify-between text-on-surface-variant', labelSizeClass)}>
          <span>{question.scale_min_label}</span>
          <span>{question.scale_max_label}</span>
        </div>
      )}
      <input
        type="range"
        min={0}
        max={max}
        value={val}
        onChange={(e) => setVal(Number(e.target.value))}
        aria-label={plainText(question.text) || 'Visual analogue scale'}
        aria-valuemin={0}
        aria-valuemax={max}
        aria-valuenow={val}
        className="w-full accent-primary"
      />
      <div className="text-center font-bold text-on-surface">{val}</div>
      <button
        onClick={() => onRespond(question.id, String(val))}
        className="w-full bg-primary text-on-primary font-bold py-3 rounded-full transition-all active:scale-95 hover:brightness-110"
      >
        Confirm
      </button>
    </div>
  );
}

function ConfirmRow({ children, disabled, onConfirm }: { children: React.ReactNode; disabled: boolean; onConfirm: () => void }) {
  return (
    <div className="mt-4 flex items-center gap-2">
      {children}
      <button
        onClick={onConfirm}
        disabled={disabled}
        className="bg-primary text-on-primary font-bold p-3 rounded-full transition-all active:scale-95 hover:brightness-110 disabled:opacity-40 disabled:active:scale-100"
        aria-label="Confirm"
      >
        <Check size={18} aria-hidden="true" />
      </button>
    </div>
  );
}
