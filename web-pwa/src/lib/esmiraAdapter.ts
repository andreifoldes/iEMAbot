/**
 * Adapts a raw ESMira questionnaire (from api/studies.php) into the
 * PreloadedSession shape the survey engine consumes, and maps each ESMira
 * `responseType` to the chat UI's internal question type.
 *
 * ESMira web-renderable responseTypes and their CSV value formats (matched to
 * QuestionnaireSaver.php so submitted data is identical to the stock web client):
 *   likert        -> integer 1..likertSteps
 *   list_single   -> the chosen choice string
 *   list_multiple -> per-choice booleans under keys `name~1`..`name~N`
 *   binary        -> "0" (left) | "1" (right)
 *   text_input    -> free text
 *   number        -> numeric string
 *   time/duration -> "HH:MM"
 *   date          -> "YYYY-MM-DD"
 *   va_scale      -> integer 0..maxValue
 *   text          -> display-only (no stored value)  [cognitive link-outs live here]
 */

import type {
  EsmiraInput,
  EsmiraQuestionnaire,
  PreloadedQuestion,
  PreloadedSection,
  PreloadedSession,
  ShowIf,
} from '../types';

/**
 * Prompt shown in the free-text modal when a participant picks the "other"
 * option of a list_single that has ESMira's `other` flag enabled. Generic
 * because the chosen option's label (e.g. "Something else") is shown alongside.
 */
const OTHER_SPECIFY_PROMPT = 'Please describe your answer.';

/** responseTypes that contribute a value AND render in this web UI. */
const RENDERABLE = new Set([
  'likert', 'list_single', 'list_multiple', 'binary',
  'text_input', 'number', 'time', 'duration', 'date', 'va_scale',
  'text', 'image', 'webapp', 'record_audio', 'record_keystrokes',
]);

/**
 * Detect a cognitive/link-out `text` item (HTML with an anchor) and pull out the
 * launch URL, title (first <b>), and description. Returns null for plain text.
 */
export function extractCognitive(html: string): { url: string; title: string; description: string } | null {
  const a = html.match(/<a\b[^>]*href="([^"]+)"[^>]*>.*?<\/a>/is);
  if (!a) return null;
  const url = a[1];
  const titleM = html.match(/<b>(.*?)<\/b>/is);
  const title = titleM ? titleM[1].replace(/<[^>]+>/g, '').trim() : 'Assessment';
  const description = html
    .replace(a[0], '')
    .replace(/<b>.*?<\/b>/is, '')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<[^>]+>/g, '')
    .trim();
  return { url, title, description };
}

/**
 * Parse an ESMira `relevance` condition into the engine's ShowIf shape, supporting
 * the single-comparison subset this PWA evaluates: `name == value`, `name != value`
 * or `name >= value` (value optionally quoted). Native apps treat relevance as a
 * full Merlin script and ESMira's stock web client ignores it entirely, so studies
 * served through this PWA author the simple form. Anything else parses to null and
 * the question always shows — fail open rather than silently dropping a question
 * over an unsupported expression. The controlling question must come earlier in
 * flow (same or prior non-randomized page); until it's answered the dependent
 * question stays hidden (surveyEngine.evaluateShowIf).
 */
export function parseRelevance(relevance: string | undefined): ShowIf | null {
  if (!relevance) return null;
  const m = /^\s*([A-Za-z_]\w*)\s*(==|!=|>=)\s*(?:"([^"]*)"|'([^']*)'|(\S+))\s*$/.exec(relevance);
  if (!m) return null;
  const operator = m[2] === '==' ? 'equals' : m[2] === '!=' ? 'not_equals' : 'gte';
  return { question_id: m[1], operator, value: m[3] ?? m[4] ?? m[5] ?? '' };
}

/** Plain text of a rich-text page header (tags dropped, common entities decoded). */
export function headerText(html: string | undefined): string {
  return (html ?? '')
    .replace(/<br\s*\/?>/gi, ' ')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/\s+/g, ' ')
    .trim();
}

/** Fisher–Yates shuffle (per-session randomization of randomized pages). */
function shuffle<T>(arr: T[], rand: () => number = Math.random): T[] {
  const a = arr.slice();
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(rand() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

/** Escape a value for use inside a double-quoted HTML attribute. */
export function escapeAttr(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** Map a single ESMira input to a PreloadedQuestion, or null if unsupported. */
function mapInput(input: EsmiraInput): PreloadedQuestion | null {
  const rt = input.responseType;
  if (!RENDERABLE.has(rt)) return null;

  // Explanatory helper text (rich HTML). Only carried through when it holds real
  // content — TipTap serialises "empty" as `<p></p>`/`<div><br></div>`, which would
  // otherwise render as a stray muted gap under the question.
  const descHtml = input.description ?? '';
  const hasDescText = descHtml.replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').trim().length > 0;

  const base = {
    id: input.name,
    text: input.text ?? '',
    subtext: hasDescText ? descHtml : '',
    aria_label: input.screenReaderLabel?.trim() || undefined,
    spoken_text: input.spokenText?.trim() || undefined,
    required: input.required ?? false,
    // Every ESMira `text` field is authored in the rich-text (TipTap) editor, so it
    // may contain HTML (<div>/<br>/<b>…) regardless of input type. Flag it globally
    // so both the live question card and the settled thread bubble render it as rich
    // text instead of dumping literal markup. Stripped back to plain text where a
    // string is needed (cognitive titles, aria-labels).
    is_html: true,
    show_if: parseRelevance(input.relevance),
    other_specify: null,
  };

  // First-class "webapp" item: launch URL + title + instructions live in dedicated
  // fields (no HTML parsing). This is the canonical shape for cognitive tasks; the
  // anchor-in-text heuristic below stays only for legacy text_input items not yet
  // migrated. Renders as the same 'cognitive' launch card.
  if (rt === 'webapp') {
    const url = input.url ?? '';
    const title = (input.text ?? '').replace(/<[^>]+>/g, '').trim() || 'Assessment';
    const sep = url.includes('?') ? '&' : '?';
    return {
      ...base,
      type: 'cognitive',
      title,
      description: input.webappDescription ?? '',
      // `v` busts any wrapper HTML cached before embed-mode shipped (see below).
      launch_url: url ? `${url}${sep}embed=1&v=3` : '',
      launch_label: `Start the ${title}`,
    };
  }

  // Cognitive/link-out tasks are detected by an anchor in the item text,
  // regardless of responseType. They need a value-holding type (e.g.
  // text_input) on the ESMira side so the captured result gets a CSV column.
  const cog = extractCognitive(input.text ?? '');
  if (cog) {
    const sep = cog.url.includes('?') ? '&' : '?';
    return {
      ...base,
      type: 'cognitive',
      title: cog.title,
      description: cog.description,
      // `v` busts any wrapper HTML the browser cached before embed-mode + the
      // no-cache headers shipped (heuristic freshness keeps old entries "fresh"
      // for days otherwise). Paired with `index.js?v=…` inside the wrapper HTML.
      // Bump both if the wrappers change again.
      launch_url: `${cog.url}${sep}embed=1&v=3`,
      launch_label: `Start the ${cog.title}`,
    };
  }

  switch (rt) {
    case 'likert':
      return {
        ...base,
        type: 'likert',
        scale_min: 1,
        scale_max: input.likertSteps ?? 5,
        scale_min_label: input.leftSideLabel ?? '',
        scale_max_label: input.rightSideLabel ?? '',
      };
    case 'list_single': {
      const choices = input.listChoices ?? [];
      // ESMira's `other` flag turns the final choice into an "other, please
      // specify" option: picking it opens a free-text modal whose text is stored
      // in the `name~other` CSV column (StudyDataValues.php), matching the native
      // apps. The last choice is the catch-all by convention — researchers add an
      // explicit label like "Something else" / "Other" as the last item.
      const otherSpecify = input.other && choices.length > 0
        ? { options: [choices[choices.length - 1]], prompt: OTHER_SPECIFY_PROMPT }
        : null;
      return { ...base, type: 'choice', options: choices, other_specify: otherSpecify };
    }
    case 'list_multiple':
      return { ...base, type: 'multi_choice', options: input.listChoices ?? [] };
    case 'binary':
      return {
        ...base,
        type: 'yesno',
        no_label: input.leftSideLabel || 'No',
        no_value: '0',
        yes_label: input.rightSideLabel || 'Yes',
        yes_value: '1',
      };
    case 'text_input':
      return { ...base, type: 'text' };
    case 'number':
      return { ...base, type: 'number', decimal: input.numberHasDecimal ?? false };
    case 'time': {
      // Some studies (including iEMAbot-ported ones) define duration questions
      // with responseType='time' because ESMira's original native client uses
      // the same picker for both. Detect "how long…" phrasing and render as
      // a duration picker instead of a 24h clock picker.
      const plainText = (input.text ?? '').replace(/<[^>]+>/g, '').toLowerCase();
      const isDuration = /\bhow long\b/.test(plainText);
      if (isDuration) {
        return { ...base, type: 'duration', max_hours: 16, minute_step: 5 };
      }
      return { ...base, type: 'time', minute_step: 5 };
    }
    case 'duration':
      return { ...base, type: 'duration', max_hours: 24, minute_step: 5 };
    case 'date':
      return { ...base, type: 'date' };
    case 'record_audio':
      // Voice memo. The response value is an integer upload identifier (set when
      // the participant saves the recording); the audio bytes upload separately to
      // file_uploads.php and the backend links them to this key's CSV column.
      // (Prompt text renders as rich text via the `base.is_html` flag.)
      return {
        ...base,
        type: 'audio',
        max_recording_seconds: input.maxLength && input.maxLength > 0 ? input.maxLength : 300,
      };
    case 'record_keystrokes':
      // Text answer with content-free keystroke-dynamics logging. Typically authored right
      // after a record_audio item as its skip fallback (paired in adaptQuestionnaire below).
      return {
        ...base,
        type: 'keystroke_text',
        min_length: input.minLength && input.minLength > 0 ? input.minLength : 0,
      };
    case 'va_scale':
      return {
        ...base,
        type: 'va_scale',
        max_value: input.maxValue && input.maxValue > 1 ? input.maxValue : 100,
        scale_min_label: input.leftSideLabel ?? '',
        scale_max_label: input.rightSideLabel ?? '',
      };
    case 'image':
      return {
        ...base,
        type: 'info',
        text: `${input.text ?? ''}${input.url ? `<br/><img src="${escapeAttr(input.url)}" alt="${escapeAttr(input.imageAlt ?? '')}" style="max-width:100%;border-radius:12px"/>` : ''}`,
      };
    case 'text':
    default:
      // Static display text (no anchor): show as an info bubble, no value.
      return { ...base, type: 'info' };
  }
}

/** Live runs show this many tasks of a page made up only of cognitive tasks; practice shows all. */
export const LIVE_BATTERY_SIZE = 2;

export interface AdaptOptions {
  /** Practice runs let the participant try every task, so no subset is drawn. */
  practice?: boolean;
  /** Task ids drawn earlier for this run (a resumed check-in), so the same ones come back. */
  battery?: string[];
  /** Random source in [0, 1); injectable for tests. */
  random?: () => number;
}

/**
 * A "battery" is a page of more than LIVE_BATTERY_SIZE items that are all cognitive tasks. A live
 * run draws LIVE_BATTERY_SIZE of them at random (kept in authored order); a resumed run reuses the
 * earlier draw.
 */
function drawBattery(items: PreloadedQuestion[], opts: AdaptOptions): PreloadedQuestion[] {
  if (opts.practice || items.length <= LIVE_BATTERY_SIZE || !items.every((x) => x.type === 'cognitive')) return items;
  const earlier = items.filter((x) => opts.battery?.includes(x.id));
  if (earlier.length === LIVE_BATTERY_SIZE) return earlier;
  const rand = opts.random ?? Math.random;
  const keep = new Set(shuffle(items, rand).slice(0, LIVE_BATTERY_SIZE).map((x) => x.id));
  return items.filter((x) => keep.has(x.id));
}

/** Build a PreloadedSession from a questionnaire (pages -> sections). */
export function adaptQuestionnaire(
  studyId: number,
  q: EsmiraQuestionnaire,
  nowMs: number,
  opts: AdaptOptions = {},
): PreloadedSession {
  const questions: PreloadedQuestion[] = [];
  const sections: PreloadedSection[] = [];

  q.pages.forEach((page, pageIdx) => {
    let mapped = page.inputs.map(mapInput).filter((x): x is PreloadedQuestion => x !== null);
    if (page.randomized) mapped = shuffle(mapped);
    mapped = drawBattery(mapped, opts);
    if (mapped.length === 0) return;

    const start = questions.length;
    sections.push({
      id: `page-${pageIdx}`,
      // The page header is authored in the rich-text editor but shown as a small plain
      // label between pages, so drop its markup (otherwise "<p><b>…</b></p>" shows literally).
      name: headerText(page.header),
      description: headerText(page.header),
      start,
      length: mapped.length,
      allow_change_all: false,
    });
    questions.push(...mapped);
  });

  // Pair each voice memo with an immediately-following keystroke_text item as its skip
  // fallback: that keystroke question is hidden in normal flow and revealed only when the
  // memo is skipped (surveyEngine.activateFallback). The fallback inherits the memo's prompt
  // if left blank, so "same question, answered by typing" needs no double-authoring.
  // (Constraint: the pair must be adjacent and on a non-randomized page.)
  for (let i = 0; i < questions.length - 1; i++) {
    const q = questions[i];
    const next = questions[i + 1];
    if (q.type === 'audio' && next.type === 'keystroke_text' && !next.is_fallback) {
      q.skip_fallback_id = next.id;
      next.is_fallback = true;
      if (!next.text || !next.text.trim()) next.text = q.text;
    }
  }

  return {
    session_id: `${studyId}-${q.internalId}-${nowMs}`,
    survey_id: String(q.internalId),
    survey_name: q.title,
    survey_description: '',
    questions,
    sections: sections.length ? sections : null,
    question_order: null,
    allow_response_editing: false,
    allow_change_all: false,
    answered: null,
    current_question_index: 0,
  };
}

/** Whether a question type contributes a value to the response payload. */
export function contributesValue(q: PreloadedQuestion): boolean {
  return q.type !== 'info';
}
