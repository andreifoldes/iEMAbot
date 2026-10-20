/**
 * The ESMira `responses` columns of a finished questionnaire. Kept free of runtime
 * imports so the Node test runner can load it: the chat channels' PHP encoder
 * (src/backend/channels/conversation/AnswerEncoder.php) is checked against the same
 * fixtures (test/fixtures/channels/encoding.json, channelParity.test.mts).
 */
import type { PreloadedQuestion } from '../types';

/**
 * Build the ESMira `responses` object from the engine's response map.
 * Mirrors QuestionnaireSaver value formats; expands multi_choice to `name~i`
 * booleans, adds the `name~other` free-text column for "other"-specify choices,
 * and drops display-only `info` items.
 */
export function buildEsmiraResponses(
  questions: PreloadedQuestion[],
  responseMap: Readonly<Record<string, string>>,
  specifyTexts: Readonly<Record<string, string>> = {},
  keystrokeTexts: Readonly<Record<string, string>> = {},
  keystrokeModes: Readonly<Record<string, string>> = {},
): Record<string, string | boolean | number> {
  const out: Record<string, string | boolean | number> = {};
  for (const q of questions) {
    const v = responseMap[q.id];
    if (v === undefined || q.type === 'info') continue;
    if (q.type === 'multi_choice') {
      const selected = new Set(v ? v.split(',') : []);
      (q.options ?? []).forEach((opt, i) => {
        out[`${q.id}~${i + 1}`] = selected.has(opt);
      });
    } else {
      out[q.id] = v;
    }
    // "other, please specify" free text → its own `name~other` column, matching
    // StudyDataValues.php / the native apps (the chosen option stays in `name`).
    if (q.other_specify) {
      out[`${q.id}~other`] = specifyTexts[q.id] ?? '';
    }
    // record_keystrokes: `name` holds the keystroke-log file identifier (like audio);
    // the typed answer and capture mode travel in the sibling columns (ResponsesIndex.php).
    if (q.type === 'keystroke_text') {
      out[`${q.id}~text`] = keystrokeTexts[q.id] ?? '';
      out[`${q.id}~capture_mode`] = keystrokeModes[q.id] ?? '';
    }
  }
  return out;
}
