/**
 * Tests for ESMira `relevance` → engine `show_if` (conditional questions).
 * Motivating bug: the evening nap-duration question kept showing after a
 * participant answered "0" to the nap-count question, because the adapter
 * never mapped relevance and the study config never set one.
 *
 * Run with `npm test` (Node's built-in runner + native TS type-stripping).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { EsmiraQuestionnaire } from '../types.ts';
import { adaptQuestionnaire, parseRelevance } from './esmiraAdapter.ts';
import { OfflineSurveyEngine } from './surveyEngine.ts';

const COUNT = 'eveningNapCount';
const DURATION = 'eveningNapDuration';

/** Mirrors the live SSRC evening nap pair: count, then a relevance-gated duration. */
function makeQuestionnaire(): EsmiraQuestionnaire {
  return {
    internalId: 1,
    title: 'Evening',
    pages: [{
      inputs: [
        {
          name: COUNT,
          responseType: 'list_single',
          required: true,
          text: 'How many times did you nap or doze today?',
          listChoices: ['0', '1', '2', '3', '4', '5+'],
        },
        {
          name: DURATION,
          responseType: 'time',
          required: true,
          relevance: `${COUNT} != 0`,
          text: 'In total, how long did you nap or doze?',
        },
        {
          name: 'eveningKss',
          responseType: 'likert',
          required: true,
          text: 'How sleepy are you right now?',
          likertSteps: 9,
        },
      ],
    }],
  } as EsmiraQuestionnaire;
}

const freshEngine = () => new OfflineSurveyEngine(adaptQuestionnaire(9727, makeQuestionnaire(), 0));

test('parseRelevance: supported comparisons, quoting, and fail-open fallback', () => {
  assert.deepEqual(parseRelevance('eveningNapCount != 0'),
    { question_id: 'eveningNapCount', operator: 'not_equals', value: '0' });
  assert.deepEqual(parseRelevance('  reason == "Something else"  '),
    { question_id: 'reason', operator: 'equals', value: 'Something else' });
  assert.deepEqual(parseRelevance("count >= '2'"),
    { question_id: 'count', operator: 'gte', value: '2' });
  // Unsupported/absent expressions must fail open (question always shows).
  assert.equal(parseRelevance(undefined), null);
  assert.equal(parseRelevance(''), null);
  assert.equal(parseRelevance('a != 0 and b != 0'), null);
  assert.equal(parseRelevance('if (x == 1) { true }'), null);
});

test('adapter: relevance lands on show_if; unconditioned questions stay null', () => {
  const session = adaptQuestionnaire(9727, makeQuestionnaire(), 0);
  const duration = session.questions.find((q) => q.id === DURATION);
  assert.deepEqual(duration?.show_if, { question_id: COUNT, operator: 'not_equals', value: '0' });
  assert.equal(session.questions.find((q) => q.id === COUNT)?.show_if, null);
});

test('engine: answering 0 naps skips the duration question entirely', () => {
  const engine = freshEngine();
  assert.equal(engine.getCurrentQuestion()?.id, COUNT);
  const next = engine.respond(COUNT, '0');
  assert.equal(next?.id, 'eveningKss');
  assert.equal(engine.getResponseMap()[DURATION], undefined);
});

test('engine: answering 1+ naps asks the duration question', () => {
  const engine = freshEngine();
  const next = engine.respond(COUNT, '2');
  assert.equal(next?.id, DURATION);
});

test('engine: "5+" satisfies the not-equals-0 condition', () => {
  const engine = freshEngine();
  assert.equal(engine.respond(COUNT, '5+')?.id, DURATION);
});

test('engine: changing the count back to 0 drops a stale duration answer', () => {
  const engine = freshEngine();
  engine.respond(COUNT, '2');
  engine.respond(DURATION, '01:30');
  // "Change response" on the count question, then answer 0.
  engine.rewindTo(COUNT);
  const next = engine.respond(COUNT, '0');
  assert.equal(next?.id, 'eveningKss');
  assert.equal(engine.getResponseMap()[DURATION], undefined);
});

test('rich-text page headers become plain section labels', async () => {
  const { headerText } = await import('./esmiraAdapter.ts');
  assert.equal(headerText('<p><b>5 · Media</b> &amp; device</p>'), '5 · Media & device');
  assert.equal(headerText(undefined), '');
  const s = adaptQuestionnaire(1, {
    internalId: 1, title: 'T',
    pages: [{ header: '<p><b>Part 1</b></p>', inputs: [{ name: 'a', responseType: 'text_input' }] }],
  } as EsmiraQuestionnaire, 0);
  assert.equal(s.sections?.[0].description, 'Part 1');
});
