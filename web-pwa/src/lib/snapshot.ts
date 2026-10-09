/**
 * End-of-questionnaire read-out for the ESRS 2026 mini check-in.
 *
 * A study opts in with the `webSnapshot` field set to `esrs2026` (source-edit only, no editor
 * control). After the check-in the chat gets the same three messages as the Telegram bot's esrs2026
 * study (telegram-ema-bot, src/anonymous.py): a playful snapshot, the participant's own answers, and
 * a thank-you. Everything is computed in the browser from the answers just given.
 *
 * The answers list follows the bot's rule: questions in survey order, only those that actually have
 * an answer. A question hidden by its show_if, or skipped, has no answer and so never appears.
 */
import type { EsmiraStudy, PreloadedQuestion } from '../types';

export const ESRS2026_PRESET = 'esrs2026';

const PA_ITEMS = ['alert', 'inspired', 'active'] as const;
const NA_ITEMS = ['nervous', 'upset', 'afraid'] as const;

/** Short names for the answers list; any other question falls back to its own text. */
const LABELS: Record<string, string> = {
  conf_enjoyment: 'Conference enjoyment',
  conf_fuel: 'Keeping you going',
  kss: 'Sleepiness (KSS)',
};

const THANK_YOU = 'Thank you so much for trying out iEMAbot at ESRS 2026! Enjoy the rest of the conference, '
  + 'and come and say hello at our poster. If you fancy another go, just open the check-in again.';
const LOCAL_ONLY_NOTE = 'This chat is the only copy: the app sent nothing to the server.';

/** Leading number of an answer such as "4" or "7: Sleepy, but no effort to keep awake". */
function leadingNumber(value: string | undefined): number | null {
  const match = /^\s*(\d+(?:\.\d+)?)/.exec(value ?? '');
  return match ? Number(match[1]) : null;
}

function mean(answers: Readonly<Record<string, string>>, items: readonly string[]): number | null {
  const values = items.map((i) => leadingNumber(answers[i])).filter((v): v is number => v !== null);
  return values.length ? values.reduce((a, b) => a + b, 0) / values.length : null;
}

function moodLine(pa: number, na: number): string {
  let verdict: string;
  if (pa >= 3.5 && na <= 2) verdict = 'Conference glow: high energy, low worries.';
  else if (na >= 3) verdict = 'A bit frazzled. A coffee and a quiet corner may help.';
  else if (pa < 2.5) verdict = 'Running on low power, so find a good talk or a snack.';
  else verdict = 'Steady and balanced.';
  return `Mood: positive ${pa.toFixed(1)}/5, negative ${na.toFixed(1)}/5. ${verdict}`;
}

function sleepinessLine(kss: number): string {
  let verdict: string;
  if (kss >= 7) verdict = 'Sleep debt detected. The coffee queue is calling.';
  else if (kss >= 5) verdict = 'Somewhere between alert and drowsy.';
  else verdict = 'Wide awake. Sleep researchers would be proud.';
  return `Sleepiness (KSS): ${kss.toFixed(0)}/9. ${verdict}`;
}

/** The snapshot text, or null when there is nothing to say about this study. */
function snapshotText(answers: Readonly<Record<string, string>>): string {
  const lines = ['Your ESRS 2026 snapshot'];
  const pa = mean(answers, PA_ITEMS);
  const na = mean(answers, NA_ITEMS);
  if (pa !== null && na !== null) lines.push(moodLine(pa, na));
  const kss = leadingNumber(answers.kss);
  if (kss !== null) lines.push(sleepinessLine(kss));
  return lines.join('\n');
}

/**
 * One line about the reaction-time game from the task's trials, or null without usable data.
 * Same rule as the bot: false starts are not taps; a lapse is a trial flagged `is_lapse`.
 */
export function pvtSummary(trials: unknown): string | null {
  if (!Array.isArray(trials)) return null;
  const rows = trials.filter((t): t is Record<string, unknown> => typeof t === 'object' && t !== null);
  const valid = rows
    .filter((t) => typeof t.rt_ms === 'number' && !t.is_false_start)
    .map((t) => t.rt_ms as number);
  if (!valid.length) return null;
  const lapses = rows.filter((t) => t.is_lapse).length;
  const average = valid.reduce((a, b) => a + b, 0) / valid.length;
  return `${valid.length} taps, average ${average.toFixed(0)} ms, ${lapses} lapse${lapses === 1 ? '' : 's'}`;
}

const plain = (html: string): string => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

/** "Your answers": the participant's own answers in question order, unanswered questions left out. */
export function answersText(
  questions: readonly Pick<PreloadedQuestion, 'id' | 'text' | 'type' | 'title' | 'scale_max'>[],
  answers: Readonly<Record<string, string>>,
  pvt: string | null,
): string {
  const lines = ['Your answers'];
  for (const q of questions) {
    const value = answers[q.id];
    if (value === undefined) continue;
    if (q.type === 'cognitive') {
      lines.push(`• ${plain(q.title || q.text) || 'Task'}: ${pvt ?? 'completed'}`);
      continue;
    }
    const suffix = q.type === 'likert' ? `/${q.scale_max ?? 5}` : '';
    lines.push(`• ${LABELS[q.id] ?? plain(q.text)}: ${value}${suffix}`);
  }
  return lines.join('\n');
}

/**
 * The chat messages to add after a finished check-in: snapshot, the participant's answers, and a
 * thank-you. Null when the study has no snapshot. `pvt` is the reaction-time line, if the game ran.
 */
export function snapshotMessages(
  study: Pick<EsmiraStudy, 'webSnapshot' | 'webLocalOnly'>,
  questions: readonly Pick<PreloadedQuestion, 'id' | 'text' | 'type' | 'title' | 'scale_max'>[],
  answers: Readonly<Record<string, string>>,
  pvt: string | null,
): string[] | null {
  if (study.webSnapshot !== ESRS2026_PRESET) return null;
  const list = answersText(questions, answers, pvt);
  return [
    snapshotText(answers),
    study.webLocalOnly ? `${list}\n\n${LOCAL_ONLY_NOTE}` : list,
    THANK_YOU,
  ];
}
