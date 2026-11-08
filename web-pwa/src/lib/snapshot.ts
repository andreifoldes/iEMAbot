/**
 * End-of-questionnaire read-out for the ESRS 2026 mini check-in.
 *
 * A study opts in with the `webSnapshot` field set to `checkin-readout` (source-edit only, no editor
 * control). After the check-in the chat gets the same three messages as the Telegram bot's check-in
 * study (telegram-ema-bot, src/anonymous.py): a playful snapshot, the participant's own answers, and
 * a thank-you. Everything is computed in the browser from the answers just given.
 *
 * The answers list follows the bot's rule: questions in survey order, only those that actually have
 * an answer. A question hidden by its show_if, or skipped, has no answer and so never appears.
 */
import type { EsmiraStudy, PreloadedQuestion } from '../types';
import { t } from './i18n.ts';

export const CHECKIN_READOUT_PRESET = 'checkin-readout';

const PA_ITEMS = ['alert', 'inspired', 'active'] as const;
const NA_ITEMS = ['nervous', 'upset', 'afraid'] as const;

/** Short names for the answers list (translation keys); any other question falls back to its own text. */
const LABEL_KEYS: Record<string, string> = {
  conf_enjoyment: 'pwa_snapshot_label_conf_enjoyment',
  conf_fuel: 'pwa_snapshot_label_conf_fuel',
  kss: 'pwa_snapshot_label_kss',
};

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
  if (pa >= 3.5 && na <= 2) verdict = t('pwa_snapshot_mood_glow');
  else if (na >= 3) verdict = t('pwa_snapshot_mood_frazzled');
  else if (pa < 2.5) verdict = t('pwa_snapshot_mood_low');
  else verdict = t('pwa_snapshot_mood_steady');
  return t('pwa_snapshot_mood_line', pa.toFixed(1), na.toFixed(1), verdict);
}

function sleepinessLine(kss: number): string {
  let verdict: string;
  if (kss >= 7) verdict = t('pwa_snapshot_kss_high');
  else if (kss >= 5) verdict = t('pwa_snapshot_kss_mid');
  else verdict = t('pwa_snapshot_kss_low');
  return t('pwa_snapshot_kss_line', kss.toFixed(0), verdict);
}

/** The snapshot text, or null when there is nothing to say about this study. */
function snapshotText(answers: Readonly<Record<string, string>>): string {
  const lines = [t('pwa_snapshot_title')];
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
  return t(lapses === 1 ? 'pwa_snapshot_pvt_one' : 'pwa_snapshot_pvt_other', valid.length, average.toFixed(0), lapses);
}

const plain = (html: string): string => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

/**
 * "Your answers": the participant's own answers in question order. Unanswered questions are left out,
 * except a task, which says "skipped" like the bot (a finished check-in has reached every task).
 */
export function answersText(
  questions: readonly Pick<PreloadedQuestion, 'id' | 'text' | 'type' | 'title' | 'scale_max'>[],
  answers: Readonly<Record<string, string>>,
  pvt: string | null,
): string {
  const lines = [t('pwa_snapshot_answers_title')];
  for (const q of questions) {
    const value = answers[q.id];
    if (q.type === 'cognitive') {
      lines.push(`• ${plain(q.title || q.text) || t('pwa_snapshot_task')}: ${value === undefined ? t('pwa_snapshot_skipped') : pvt ?? t('pwa_snapshot_completed')}`);
      continue;
    }
    if (value === undefined) continue;
    const suffix = q.type === 'likert' ? `/${q.scale_max ?? 5}` : '';
    lines.push(`• ${LABEL_KEYS[q.id] ? t(LABEL_KEYS[q.id]) : plain(q.text)}: ${value}${suffix}`);
  }
  return lines.join('\n');
}

/**
 * The chat messages to add after a finished check-in: snapshot, the participant's answers, and a
 * thank-you. Null when the study has no snapshot. `pvt` is the reaction-time line, if the game ran.
 */
export function snapshotMessages(
  study: Pick<EsmiraStudy, 'webSnapshot'>,
  questions: readonly Pick<PreloadedQuestion, 'id' | 'text' | 'type' | 'title' | 'scale_max'>[],
  answers: Readonly<Record<string, string>>,
  pvt: string | null,
): string[] | null {
  if (study.webSnapshot !== CHECKIN_READOUT_PRESET) return null;
  return [snapshotText(answers), answersText(questions, answers, pvt), t('pwa_snapshot_thank_you')];
}
