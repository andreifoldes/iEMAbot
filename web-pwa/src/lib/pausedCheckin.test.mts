/**
 * Tests for pausing a check-in on this device ("Remind me later" on a task card, study field
 * `webTaskActions`) and for restoring it into the survey engine.
 *
 * Run with `npm test`.
 */
import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { clearPaused, loadPaused, pausedKey, savePaused, type PausedCheckin } from './pausedCheckin.ts';
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

describe('paused check-in storage', () => {
  it('round-trips and is keyed by study and participant', () => {
    const store = fakeLocalStorage();
    assert.equal(savePaused(2026, 'u1', paused), true);
    assert.ok(pausedKey(2026, 'u1') in store);
    assert.deepEqual(loadPaused(2026, 'u1'), paused);
    assert.equal(loadPaused(2026, 'u2'), null);
    assert.equal(loadPaused(9727, 'u1'), null);
  });

  it('clears', () => {
    fakeLocalStorage();
    savePaused(2026, 'u1', paused);
    clearPaused(2026, 'u1');
    assert.equal(loadPaused(2026, 'u1'), null);
  });

  it('reports a refused write, and ignores corrupt or wrong-shaped values', () => {
    fakeLocalStorage({}, true);
    assert.equal(savePaused(2026, 'u1', paused), false);
    fakeLocalStorage({ [pausedKey(2026, 'u1')]: '{not json' });
    assert.equal(loadPaused(2026, 'u1'), null);
    fakeLocalStorage({ [pausedKey(2026, 'u1')]: JSON.stringify({ ...paused, responses: { a: 3 } }) });
    assert.equal(loadPaused(2026, 'u1'), null);
    fakeLocalStorage({ [pausedKey(2026, 'u1')]: 'null' });
    assert.equal(loadPaused(2026, 'u1'), null);
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

  it('restores "other, please specify" texts', () => {
    const engine = new OfflineSurveyEngine(session());
    engine.restoreSpecifyTexts({ b: 'Tea' });
    assert.deepEqual({ ...engine.getSpecifyTexts() }, { b: 'Tea' });
  });
});
