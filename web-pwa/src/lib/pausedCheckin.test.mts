/**
 * Tests for saving a half-finished check-in on this device (after every answer, and "Remind me later" on a task card,
 * study field `webTaskActions`), resuming it only in its own window, and restoring it into the survey engine.
 *
 * Run with `npm test`.
 */
import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { canResume, clearPaused, loadPaused, pausedKey, savePaused, type PausedCheckin } from './pausedCheckin.ts';
import type { Availability } from './schedule.ts';
import { OfflineSurveyEngine } from './surveyEngine.ts';
import type { PreloadedQuestion, PreloadedSession } from '../types.ts';

const realLocalStorage = Object.getOwnPropertyDescriptor(globalThis, 'localStorage');
afterEach(() => {
  if (realLocalStorage) Object.defineProperty(globalThis, 'localStorage', realLocalStorage);
  else delete (globalThis as Record<string, unknown>).localStorage;
});

function fakeLocalStorage(store: Record<string, string> = {}, failWrites = false) {
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (k: string) => (k in store ? store[k] : null),
      setItem: (k: string, v: string) => {
        if (failWrites) throw new Error('QuotaExceededError');
        store[k] = v;
      },
      removeItem: (k: string) => { delete store[k]; },
    },
  });
  return store;
}

const paused: PausedCheckin = {
  questionnaireId: 202601,
  currentIndex: 2,
  responses: { a: '3', b: 'Coffee' },
  specifyTexts: { b: 'Tea' },
  audioIds: [7],
  keystrokeIds: [],
  keystrokeTexts: {},
  keystrokeModes: {},
  pausedAt: 1,
};

describe('saved check-in storage', () => {
  it('round-trips and is keyed by study, participant and questionnaire', () => {
    const store = fakeLocalStorage();
    assert.equal(savePaused(2026, 'u1', paused), true);
    assert.ok(pausedKey(2026, 'u1', 202601) in store);
    assert.deepEqual(loadPaused(2026, 'u1', 202601), paused);
    assert.equal(loadPaused(2026, 'u2', 202601), null);
    assert.equal(loadPaused(4001, 'u1', 202601), null);
    assert.equal(loadPaused(2026, 'u1', 202602), null);
  });

  it('keeps one check-in per questionnaire', () => {
    fakeLocalStorage();
    savePaused(2026, 'u1', paused);
    savePaused(2026, 'u1', { ...paused, questionnaireId: 202602, responses: { c: '1' } });
    assert.deepEqual(loadPaused(2026, 'u1', 202601), paused);
    assert.deepEqual(loadPaused(2026, 'u1', 202602)?.responses, { c: '1' });
    clearPaused(2026, 'u1', 202602);
    assert.equal(loadPaused(2026, 'u1', 202602), null);
    assert.deepEqual(loadPaused(2026, 'u1', 202601), paused);
  });

  it('clears', () => {
    fakeLocalStorage();
    savePaused(2026, 'u1', paused);
    clearPaused(2026, 'u1', 202601);
    assert.equal(loadPaused(2026, 'u1', 202601), null);
  });

  it('reads, replaces and clears a check-in saved by older versions (one slot per study and participant)', () => {
    const legacy = 'esmira_paused_2026_u1';
    const store = fakeLocalStorage({ [legacy]: JSON.stringify(paused) });
    assert.deepEqual(loadPaused(2026, 'u1', 202601), paused);
    assert.equal(loadPaused(2026, 'u1', 202602), null);
    // Clearing another questionnaire leaves it alone.
    clearPaused(2026, 'u1', 202602);
    assert.ok(legacy in store);
    // Saving the same questionnaire replaces it.
    savePaused(2026, 'u1', { ...paused, currentIndex: 3 });
    assert.ok(!(legacy in store));
    assert.equal(loadPaused(2026, 'u1', 202601)?.currentIndex, 3);
    fakeLocalStorage({ [legacy]: JSON.stringify(paused) });
    clearPaused(2026, 'u1', 202601);
    assert.equal(loadPaused(2026, 'u1', 202601), null);
  });

  it('reports a refused write, and ignores corrupt or wrong-shaped values', () => {
    fakeLocalStorage({}, true);
    assert.equal(savePaused(2026, 'u1', paused), false);
    const key = pausedKey(2026, 'u1', 202601);
    fakeLocalStorage({ [key]: '{not json' });
    assert.equal(loadPaused(2026, 'u1', 202601), null);
    fakeLocalStorage({ [key]: JSON.stringify({ ...paused, responses: { a: 3 } }) });
    assert.equal(loadPaused(2026, 'u1', 202601), null);
    fakeLocalStorage({ [key]: JSON.stringify({ ...paused, occKey: 7 }) });
    assert.equal(loadPaused(2026, 'u1', 202601), null);
    fakeLocalStorage({ [key]: JSON.stringify({ ...paused, fallbacks: [1] }) });
    assert.equal(loadPaused(2026, 'u1', 202601), null);
    fakeLocalStorage({ [key]: 'null' });
    assert.equal(loadPaused(2026, 'u1', 202601), null);
  });
});

describe('resuming only within the window', () => {
  const open = (occKey: string, openedAt = 1000): Availability => ({ state: 'available', reason: 'Available now', occKey, openedAt });

  it('carries on in the window it was opened in', () => {
    assert.equal(canResume({ ...paused, occKey: '2026-11-02:0' }, open('2026-11-02:0')), true);
  });

  it('never carries into a later window (tomorrow, or the next check-in of the day)', () => {
    assert.equal(canResume({ ...paused, occKey: '2026-11-02:0' }, open('2026-11-03:0')), false);
    assert.equal(canResume({ ...paused, occKey: '2026-11-02:0' }, open('2026-11-02:1')), false);
  });

  it('not once the questionnaire is closed, done, or its state is unknown', () => {
    const saved = { ...paused, occKey: '2026-11-02:0' };
    assert.equal(canResume(saved, { state: 'locked', reason: 'Opens at 20:00' }), false);
    assert.equal(canResume(saved, { state: 'completed', reason: 'Completed' }), false);
    assert.equal(canResume(saved, { state: 'ended', reason: 'No longer available' }), false);
    assert.equal(canResume(saved, undefined), false);
  });

  it('a save from an older version (no window) counts when made after the open window opened', () => {
    assert.equal(canResume({ ...paused, pausedAt: 1500 }, open('2026-11-02:0', 1000)), true);
    assert.equal(canResume({ ...paused, pausedAt: 500 }, open('2026-11-02:0', 1000)), false);
  });
});

const q = (id: string, type: PreloadedQuestion['type'] = 'likert'): PreloadedQuestion => ({
  id, text: id, type, required: true, is_html: false, show_if: null, other_specify: null,
}) as PreloadedQuestion;

const session = (overrides: Partial<PreloadedSession> = {}): PreloadedSession => ({
  questions: [q('a'), q('b'), q('pvt', 'cognitive')],
  answered: null,
  current_question_index: 0,
  sections: null,
  question_order: null,
  ...overrides,
}) as PreloadedSession;

describe('resuming into the engine', () => {
  it('continues at the saved question and keeps the earlier answers', () => {
    const engine = new OfflineSurveyEngine(session({
      answered: { a: '3', b: 'x' }, current_question_index: 2,
    }));
    assert.equal(engine.getCurrentQuestion()?.id, 'pvt');
    assert.deepEqual({ ...engine.getResponseMap() }, { a: '3', b: 'x' });
  });

  it('does not re-ask a question that was skipped before the pause', () => {
    const engine = new OfflineSurveyEngine(session({ answered: { b: 'x' }, current_question_index: 2 }));
    assert.equal(engine.getCurrentQuestion()?.id, 'pvt');
  });

  it('keeps a revealed skip fallback revealed', () => {
    const fallback = { ...q('typed', 'keystroke_text'), is_fallback: true } as PreloadedQuestion;
    const s = (fallbacks?: string[]) => new OfflineSurveyEngine(session({
      questions: [q('memo', 'audio'), fallback, q('c')], answered: { memo: '' }, current_question_index: 1,
    }), fallbacks);
    assert.equal(s().getCurrentQuestion()?.id, 'c');
    const resumed = s(['typed']);
    assert.equal(resumed.getCurrentQuestion()?.id, 'typed');
    assert.deepEqual(resumed.getActivatedFallbacks(), ['typed']);
  });

  it('restores "other, please specify" texts', () => {
    const engine = new OfflineSurveyEngine(session());
    engine.restoreSpecifyTexts({ b: 'Tea' });
    assert.deepEqual({ ...engine.getSpecifyTexts() }, { b: 'Tea' });
  });
});
