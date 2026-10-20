/**
 * Tests for the "other, please specify" flow on list_single choices that have
 * ESMira's `other` flag enabled (e.g. the "Something else" reason on the morning
 * bed-exit question). Covers the adapter → engine path; the engine exposes the
 * chosen option and its free text separately, which `buildEsmiraResponses` then
 * emits as the `name` + `name~other` CSV columns.
 *
 * Run with `npm test` (Node's built-in runner + native TS type-stripping). The
 * submission mapping (esmiraApi.ts) isn't imported here — its extensionless value
 * imports don't resolve under Node's ESM loader.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { EsmiraQuestionnaire } from '../types.ts';
import { adaptQuestionnaire } from './esmiraAdapter.ts';
import { OfflineSurveyEngine } from './surveyEngine.ts';

const REASON = 'morningBedExitReason';
const CHOICES = ['Needed the bathroom', 'Discomfort or pain', "Couldn't sleep / restless", 'Something else'];

/** One page: an `other`-enabled reason question followed by a plain choice. */
function makeQuestionnaire(): EsmiraQuestionnaire {
  return {
    internalId: 1,
    title: 'Morning',
    pages: [{
      inputs: [
        {
          name: REASON,
          responseType: 'list_single',
          required: true,
          other: true,
          text: 'What was the main reason you got up? (choose one)',
          listChoices: CHOICES,
        },
        {
          name: 'plainChoice',
          responseType: 'list_single',
          required: true,
          text: 'Pick one',
          listChoices: ['A', 'B'],
        },
      ],
    }],
  } as EsmiraQuestionnaire;
}

const freshEngine = () => new OfflineSurveyEngine(adaptQuestionnaire(7, makeQuestionnaire(), 0));

test('adapter: `other` makes the LAST choice an other-specify trigger', () => {
  const session = adaptQuestionnaire(7, makeQuestionnaire(), 0);
  const reason = session.questions.find((q) => q.id === REASON);
  assert.deepEqual(reason?.other_specify, { options: ['Something else'], prompt: 'Please describe your answer.' });
  // A list_single without the flag stays a plain choice.
  const plain = session.questions.find((q) => q.id === 'plainChoice');
  assert.equal(plain?.other_specify, null);
});

test('engine: a normal choice does not open a specify prompt and advances', () => {
  const engine = freshEngine();
  assert.equal(engine.getCurrentQuestion()?.id, REASON);
  engine.respond(REASON, 'Needed the bathroom');
  assert.equal(engine.getPendingSpecify(), null);
  assert.equal(engine.getCurrentQuestion()?.id, 'plainChoice');
});

test('engine: the other option opens a specify prompt and stays on the question', () => {
  const engine = freshEngine();
  engine.respond(REASON, 'Something else');
  const pending = engine.getPendingSpecify();
  assert.equal(pending?.questionId, REASON);
  assert.equal(pending?.baseValue, 'Something else');
  assert.equal(pending?.prompt, 'Please describe your answer.');
  assert.equal(engine.getCurrentQuestion()?.id, REASON); // not advanced yet
});

test('engine: submitting specify stores choice + trimmed text separately, advances', () => {
  const engine = freshEngine();
  engine.respond(REASON, 'Something else');
  engine.submitSpecify('  Felt too hot  ');
  assert.equal(engine.getPendingSpecify(), null);
  assert.equal(engine.getResponseMap()[REASON], 'Something else');
  assert.equal(engine.getSpecifyTexts()[REASON], 'Felt too hot');
  assert.equal(engine.getCurrentQuestion()?.id, 'plainChoice');
});

test('engine: empty specify text keeps just the choice (no ~other detail)', () => {
  const engine = freshEngine();
  engine.respond(REASON, 'Something else');
  engine.submitSpecify('   ');
  assert.equal(engine.getResponseMap()[REASON], 'Something else');
  assert.equal(engine.getSpecifyTexts()[REASON], undefined);
  assert.equal(engine.getCurrentQuestion()?.id, 'plainChoice');
});

test('engine: cancelling specify clears the choice and re-shows the question', () => {
  const engine = freshEngine();
  engine.respond(REASON, 'Something else');
  const reopened = engine.cancelSpecify();
  assert.equal(reopened?.id, REASON);
  assert.equal(engine.getResponseMap()[REASON], undefined);
  assert.equal(engine.getPendingSpecify(), null);
});

test('engine: rewinding a specified question drops both the choice and its detail', () => {
  const engine = freshEngine();
  engine.respond(REASON, 'Something else');
  engine.submitSpecify('Felt too hot');
  engine.rewindTo(REASON);
  assert.equal(engine.getResponseMap()[REASON], undefined);
  assert.equal(engine.getSpecifyTexts()[REASON], undefined);
  assert.equal(engine.getCurrentQuestion()?.id, REASON);
});
