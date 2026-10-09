/**
 * End-of-questionnaire "snapshot" for the ESRS 2026 mini check-in.
 *
 * A study opts in with the `webSnapshot` field set to `esrs2026` (source-edit only, no editor
 * control). After the participant submits, the app adds a short, playful read-out of their own
 * answers to the chat. It is computed in the browser from the answers just given; nothing extra
 * is stored or sent. Mirrors the snapshot of the Telegram bot's esrs2026 study (telegram-ema-bot,
 * src/anonymous.py), reading the same question ids.
 */
import type { EsmiraStudy } from '../types';

export const ESRS2026_PRESET = 'esrs2026';

const PA_ITEMS = ['alert', 'inspired', 'active'] as const;
const NA_ITEMS = ['nervous', 'upset', 'afraid'] as const;

const THANK_YOU = 'Thank you so much for trying out iEMAbot at ESRS 2026! Enjoy the rest of the conference, '
  + 'and come and say hello at our poster. If you fancy another go, just open the check-in again.';

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

/** The snapshot text for one finished check-in, or null when the study has no snapshot. */
export function snapshotFor(
  study: Pick<EsmiraStudy, 'webSnapshot'>,
  answers: Readonly<Record<string, string>>,
): string | null {
  if (study.webSnapshot !== ESRS2026_PRESET) return null;
  const lines = ['Your ESRS 2026 snapshot'];
  const pa = mean(answers, PA_ITEMS);
  const na = mean(answers, NA_ITEMS);
  if (pa !== null && na !== null) lines.push(moodLine(pa, na));
  const kss = leadingNumber(answers.kss);
  if (kss !== null) lines.push(sleepinessLine(kss));
  return lines.length > 1 ? `${lines.join('\n')}\n\n${THANK_YOU}` : THANK_YOU;
}
