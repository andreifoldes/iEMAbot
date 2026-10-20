/**
 * Tests for the voice-memo → keystroke-text skip-fallback branch.
 *
 * Covers the adapter pairing (an `audio` item immediately followed by `record_keystrokes`
 * becomes its skip fallback, inheriting the memo's prompt) and the engine gate (the fallback
 * stays hidden until `activateFallback`; recording the memo instead leaves it hidden).
 * Run with `npm test` (Node's runner + native type-stripping).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { EsmiraQuestionnaire } from '../types.ts';
import { adaptQuestionnaire } from './esmiraAdapter.ts';
import { OfflineSurveyEngine } from './surveyEngine.ts';

/** One page: a voice memo followed by its keystroke-text fallback (blank prompt → inherits). */
function makeQuestionnaire(): EsmiraQuestionnaire {
  return {
    internalId: 1,
    title: 'Diary',
    pages: [{
      inputs: [
        { name: 'memo', responseType: 'record_audio', required: false, text: 'Talk about your day today.' },
        { name: 'memoText', responseType: 'record_keystrokes', required: false, text: '' },
      ],
    }],
  };
}

test('adapter pairs the memo with an adjacent keystroke_text fallback and inherits the prompt', () => {
  const session = adaptQuestionnaire(1, makeQuestionnaire(), 0);
  const [memo, memoText] = session.questions;

  assert.equal(memo.type, 'audio');
  assert.equal(memoText.type, 'keystroke_text');
  assert.equal(memo.skip_fallback_id, 'memoText');
  assert.equal(memoText.is_fallback, true);
  assert.equal(memoText.text, 'Talk about your day today.'); // inherited (was blank)
});

test('adapter maps minLength to a soft min_length target (0 when absent)', () => {
  const q = makeQuestionnaire();
  const noMin = adaptQuestionnaire(1, q, 0).questions[1];
  assert.equal(noMin.min_length, 0);

  q.pages[0].inputs[1].minLength = 200;
  const withMin = adaptQuestionnaire(1, q, 0).questions[1];
  assert.equal(withMin.min_length, 200);
});

test('the keystroke fallback is hidden until the memo is skipped (activateFallback)', () => {
  const session = adaptQuestionnaire(1, makeQuestionnaire(), 0);
  const engine = new OfflineSurveyEngine(session);

  // Memo is asked first; the fallback is not part of the normal flow.
  assert.equal(engine.getCurrentQuestion()?.id, 'memo');

  // Skipping the memo reveals its fallback.
  engine.activateFallback('memoText');
  assert.equal(engine.skip()?.id, 'memoText');
});

test('recording the memo leaves the keystroke fallback hidden', () => {
  const session = adaptQuestionnaire(1, makeQuestionnaire(), 0);
  const engine = new OfflineSurveyEngine(session);

  // Answer the memo (identifier value) without activating the fallback → survey completes.
  const next = engine.respond('memo', '12345');
  assert.equal(next, null);
  assert.equal(engine.isComplete(), true);
});
