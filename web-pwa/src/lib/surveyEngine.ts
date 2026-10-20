/**
 * Offline survey engine — drives survey question flow entirely client-side.
 *
 * Ported from the iEMAbot PWA. After a study is fetched and a questionnaire
 * adapted into a PreloadedSession, this engine handles question order,
 * show_if evaluation, section transitions, and response tracking locally.
 */

import type { PreloadedSession, PreloadedQuestion, PreloadedSection, ShowIf } from '../types';

export interface SurveyResponse {
  question_id: string;
  response_value: string;
  responded_at: string;
}

export interface SectionTransition {
  section: PreloadedSection;
  description: string;
}

export interface EngineState {
  /** Current question index (into the ordered questions array) */
  currentIndex: number;
  /** Collected responses keyed by question_id */
  responses: Record<string, string>;
  /** Whether the survey is complete */
  complete: boolean;
}

/** Parse the leading integer of an option string ("5+" -> 5), or null. */
function leadingInt(value: string | undefined): number | null {
  if (value === undefined) return null;
  const m = /^\s*(\d+)/.exec(value);
  return m ? parseInt(m[1], 10) : null;
}

/**
 * Evaluate a show_if condition against collected responses.
 */
function evaluateShowIf(condition: ShowIf, responses: Record<string, string>): boolean {
  const value = responses[condition.question_id];
  if (value === undefined) return false;
  if (condition.operator === 'equals') return value === condition.value;
  if (condition.operator === 'not_equals') return value !== condition.value;
  if (condition.operator === 'gte') {
    const answered = leadingInt(value);
    const threshold = leadingInt(condition.value);
    if (answered === null || threshold === null) return false;
    return answered >= threshold;
  }
  return true;
}

/**
 * Find which section a question at `questionIndex` belongs to.
 */
function findSectionForOriginalIndex(
  sections: PreloadedSection[] | null,
  originalIndex: number,
): PreloadedSection | null {
  if (!sections) return null;
  for (const section of sections) {
    if (originalIndex >= section.start && originalIndex < section.start + section.length) {
      return section;
    }
  }
  return null;
}

export interface PendingSpecify {
  questionId: string;
  baseValue: string;
  prompt: string;
}

export class OfflineSurveyEngine {
  readonly session: PreloadedSession;
  private state: EngineState;
  private pendingSpecify: PendingSpecify | null = null;
  /**
   * Free-text elaborations for "other"-style choices, keyed by question id.
   * Kept separate from `state.responses` (which holds the chosen option) so the
   * pair submits as ESMira's native `name` + `name~other` CSV columns rather
   * than a single blended value. See `buildEsmiraResponses`.
   */
  private specifyTexts: Record<string, string> = {};
  /**
   * Ids of skip-fallback questions (e.g. a keystroke_text paired to a voice memo) that have
   * been revealed. A fallback stays hidden until its paired question is skipped, at which point
   * the skip handler calls `activateFallback`. See `isVisible`.
   */
  private activatedFallbacks = new Set<string>();

  constructor(session: PreloadedSession) {
    this.session = session;
    const responses: Record<string, string> = {};
    if (session.answered) {
      Object.assign(responses, session.answered);
    }
    this.state = {
      currentIndex: session.current_question_index,
      responses,
      complete: false,
    };
    this.advanceToNextUnanswered();
  }

  getState(): Readonly<EngineState> {
    return this.state;
  }

  getResponses(): SurveyResponse[] {
    return Object.entries(this.state.responses).map(([question_id, response_value]) => ({
      question_id,
      response_value,
      responded_at: new Date().toISOString(),
    }));
  }

  /** Raw responses map keyed by question id. */
  getResponseMap(): Readonly<Record<string, string>> {
    return this.state.responses;
  }

  /**
   * Whether a question renders in the current flow: its show_if (if any) must hold, and if it's
   * a skip fallback it must have been activated (its paired question was skipped).
   */
  private isVisible(q: PreloadedQuestion): boolean {
    if (q.show_if && !evaluateShowIf(q.show_if, this.state.responses)) return false;
    if (q.is_fallback && !this.activatedFallbacks.has(q.id)) return false;
    return true;
  }

  /** Reveal a skip-fallback question (called when its paired question is skipped). */
  activateFallback(questionId: string): void {
    this.activatedFallbacks.add(questionId);
  }

  getCurrentQuestion(): PreloadedQuestion | null {
    if (this.state.complete) return null;

    const questions = this.session.questions;
    let idx = this.state.currentIndex;

    while (idx < questions.length) {
      const q = questions[idx];
      if (!this.isVisible(q)) {
        idx++;
        continue;
      }
      this.state.currentIndex = idx;
      return q;
    }

    this.state.complete = true;
    return null;
  }

  getSectionTransition(): SectionTransition | null {
    if (!this.session.sections) return null;

    const questionOrder = this.session.question_order;
    const currentOrigIdx = questionOrder
      ? questionOrder[this.state.currentIndex]
      : this.state.currentIndex;

    if (currentOrigIdx === undefined) return null;
    const currentSection = findSectionForOriginalIndex(this.session.sections, currentOrigIdx);
    if (!currentSection || !currentSection.description) return null;

    // Show on entering the section (first question of the section).
    if (this.state.currentIndex === 0) return { section: currentSection, description: currentSection.description };

    const prevOrigIdx = questionOrder
      ? questionOrder[this.state.currentIndex - 1]
      : this.state.currentIndex - 1;
    if (prevOrigIdx === undefined) return null;
    const prevSection = findSectionForOriginalIndex(this.session.sections, prevOrigIdx);

    if (!prevSection || currentSection.id !== prevSection.id) {
      return { section: currentSection, description: currentSection.description };
    }
    return null;
  }

  respond(questionId: string, value: string): PreloadedQuestion | null {
    const question = this.session.questions.find(q => q.id === questionId);
    if (question?.other_specify?.options.includes(value)) {
      this.state.responses[questionId] = value;
      this.pendingSpecify = {
        questionId,
        baseValue: value,
        prompt: question.other_specify.prompt,
      };
      return question;
    }
    this.state.responses[questionId] = value;
    this.pruneHiddenResponses();
    this.state.currentIndex++;
    return this.getCurrentQuestion();
  }

  getPendingSpecify(): PendingSpecify | null {
    return this.pendingSpecify;
  }

  /** Free-text "other" elaborations keyed by question id (submitted as `id~other`). */
  getSpecifyTexts(): Readonly<Record<string, string>> {
    return this.specifyTexts;
  }

  /** Put back the "other" texts of a paused questionnaire (see lib/pausedCheckin.ts). */
  restoreSpecifyTexts(texts: Readonly<Record<string, string>>): void {
    this.specifyTexts = { ...texts };
  }

  /**
   * Confirm the "other" choice with its free-text detail. The chosen option is
   * already recorded in `state.responses`; the text is stored separately (empty
   * text clears any prior detail) and the survey advances.
   */
  submitSpecify(text: string): PreloadedQuestion | null {
    if (!this.pendingSpecify) return this.getCurrentQuestion();
    const { questionId } = this.pendingSpecify;
    const trimmed = text.trim();
    if (trimmed) this.specifyTexts[questionId] = trimmed;
    else delete this.specifyTexts[questionId];
    this.pendingSpecify = null;
    this.pruneHiddenResponses();
    this.state.currentIndex++;
    return this.getCurrentQuestion();
  }

  /**
   * Back out of the specify prompt: drop the tentative choice (and any earlier
   * detail) and stay on the question so the participant can pick again.
   */
  cancelSpecify(): PreloadedQuestion | null {
    if (!this.pendingSpecify) return this.getCurrentQuestion();
    const { questionId } = this.pendingSpecify;
    delete this.state.responses[questionId];
    delete this.specifyTexts[questionId];
    this.pendingSpecify = null;
    return this.getCurrentQuestion();
  }

  private pruneHiddenResponses(): void {
    let changed = true;
    while (changed) {
      changed = false;
      for (const q of this.session.questions) {
        if (
          q.show_if &&
          this.state.responses[q.id] !== undefined &&
          !evaluateShowIf(q.show_if, this.state.responses)
        ) {
          delete this.state.responses[q.id];
          changed = true;
        }
      }
    }
  }

  skip(): PreloadedQuestion | null {
    this.state.currentIndex++;
    return this.getCurrentQuestion();
  }

  /**
   * Rewind to re-ask a specific question by id (drops its stored response so it
   * appears unanswered). Used by the "Change response" affordance.
   */
  rewindTo(questionId: string): PreloadedQuestion | null {
    const idx = this.session.questions.findIndex(q => q.id === questionId);
    if (idx < 0) return null;
    // Clear this and any later responses so re-answering from here is clean
    // ("any"-mode back-navigation; for the last item this is just the one response).
    for (let i = idx; i < this.session.questions.length; i++) {
      const qid = this.session.questions[i].id;
      delete this.state.responses[qid];
      delete this.specifyTexts[qid];
      // Reset any downstream skip-fallback activation so re-answering (e.g. recording the memo
      // this time) correctly re-hides its keystroke fallback instead of showing both.
      this.activatedFallbacks.delete(qid);
    }
    this.state.currentIndex = idx;
    this.state.complete = false;
    return this.getCurrentQuestion();
  }

  isComplete(): boolean {
    return this.state.complete;
  }

  getProgress(): number {
    const total = this.session.questions.length;
    if (total === 0) return 1;
    const answered = Object.keys(this.state.responses).length;
    let skipped = 0;
    for (let i = 0; i < this.state.currentIndex; i++) {
      const q = this.session.questions[i];
      if (q && !this.isVisible(q) && !this.state.responses[q.id]) {
        skipped++;
      }
    }
    return Math.min((answered + skipped) / total, 1);
  }

  private advanceToNextUnanswered(): void {
    const questions = this.session.questions;
    while (this.state.currentIndex < questions.length) {
      const q = questions[this.state.currentIndex];
      if (this.state.responses[q.id] !== undefined) {
        this.state.currentIndex++;
        continue;
      }
      if (!this.isVisible(q)) {
        this.state.currentIndex++;
        continue;
      }
      break;
    }
    if (this.state.currentIndex >= questions.length) {
      this.state.complete = true;
    }
  }
}
