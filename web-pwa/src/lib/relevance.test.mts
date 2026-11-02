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

const freshEngine = () => new OfflineSurveyEngine(adaptQuestionnaire(4001, makeQuestionnaire(), 0));

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
  const session = adaptQuestionnaire(4001, makeQuestionnaire(), 0);
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

/** Mirrors the live SSRC "Social Company" page: each rating pair applies to one branch only. */
function makeSocialQuestionnaire(): EsmiraQuestionnaire {
  const likert = (name: string, relevance: string) =>
    ({ name, responseType: 'likert', required: true, relevance, text: name });
  return {
    internalId: 2,
    title: 'Momentary',
    pages: [{
      inputs: [
        {
          name: 'momentaryCheckinWithWhom',
          responseType: 'list_single',
          required: true,
          text: 'Who are you with right now?',
          listChoices: ['Partner', 'Family', 'Friend', 'Nobody'],
        },
        likert('momentaryCheckinLikeCompany', 'momentaryCheckinWithWhom != "Nobody"'),
        likert('momentaryCheckinLikeAlone', 'momentaryCheckinWithWhom == "Nobody"'),
        likert('momentaryCheckinRatherAlone', 'momentaryCheckinWithWhom != "Nobody"'),
        likert('momentaryCheckinRatherCompany', 'momentaryCheckinWithWhom == "Nobody"'),
        { name: 'after', responseType: 'text_input', text: 'After' },
      ],
    }],
  } as EsmiraQuestionnaire;
}

function socialFlow(withWhom: string): string[] {
  const engine = new OfflineSurveyEngine(adaptQuestionnaire(4001, makeSocialQuestionnaire(), 0));
  const asked: string[] = [];
  let q = engine.respond('momentaryCheckinWithWhom', withWhom);
  while (q && q.id !== 'after') {
    asked.push(q.id);
    q = engine.respond(q.id, '3');
  }
  return asked;
}

test('engine: answering "Nobody" asks only the being-alone ratings', () => {
  assert.deepEqual(socialFlow('Nobody'), ['momentaryCheckinLikeAlone', 'momentaryCheckinRatherCompany']);
});

test('engine: being with someone asks only the company ratings', () => {
  assert.deepEqual(socialFlow('Friend'), ['momentaryCheckinLikeCompany', 'momentaryCheckinRatherAlone']);
});

/**
 * Mirrors the live SSRC morning "Sleep Diary" → "Bed Exit Diary" → "Sleepiness Scale" pages: the
 * bed-exit count is gated on the night-wakings count, and the bed-exit reason on the bed-exit count.
 */
function makeBedExitQuestionnaire(): EsmiraQuestionnaire {
  const counts = ['0', '1', '2', '3', '4', '5+'];
  return {
    internalId: 3,
    title: 'Morning',
    pages: [
      {
        header: 'Sleep Diary',
        inputs: [{
          name: 'morningNightWakings', responseType: 'list_single', required: true,
          text: 'How many times did you wake up during the night?', listChoices: counts,
        }],
      },
      {
        header: 'Bed Exit Diary',
        inputs: [
          {
            name: 'morningBedExits', responseType: 'list_single', required: true,
            relevance: 'morningNightWakings != "0"',
            text: 'How many times did you get out of bed during the night?', listChoices: counts,
          },
          {
            name: 'morningBedExitReason', responseType: 'list_single', required: true,
            relevance: 'morningBedExits != "0"',
            text: 'What was the main reason you got up? (choose one)',
            listChoices: ['Needed the bathroom', 'Discomfort or pain', "Couldn't sleep / restless", 'Something else'],
          },
        ],
      },
      {
        header: 'Sleepiness Scale',
        inputs: [{ name: 'morningKss', responseType: 'likert', required: true, text: 'How sleepy?', likertSteps: 9 }],
      },
    ],
  } as EsmiraQuestionnaire;
}

const bedExitEngine = () => new OfflineSurveyEngine(adaptQuestionnaire(4001, makeBedExitQuestionnaire(), 0));

test('engine: 0 night wakings skips the whole Bed Exit Diary page', () => {
  const engine = bedExitEngine();
  assert.equal(engine.respond('morningNightWakings', '0')?.id, 'morningKss');
  // The next header shown is the Sleepiness Scale, not Bed Exit Diary.
  assert.equal(engine.getSectionTransition()?.description, 'Sleepiness Scale');
});

test('engine: wakings but 0 bed exits skips the reason question', () => {
  const engine = bedExitEngine();
  assert.equal(engine.respond('morningNightWakings', '2')?.id, 'morningBedExits');
  assert.equal(engine.getSectionTransition()?.description, 'Bed Exit Diary');
  assert.equal(engine.respond('morningBedExits', '0')?.id, 'morningKss');
});

test('engine: wakings and bed exits asks the reason', () => {
  const engine = bedExitEngine();
  engine.respond('morningNightWakings', '5+');
  assert.equal(engine.respond('morningBedExits', '1')?.id, 'morningBedExitReason');
});

test('engine: changing wakings back to 0 drops the stale bed-exit answers', () => {
  const engine = bedExitEngine();
  engine.respond('morningNightWakings', '2');
  engine.respond('morningBedExits', '1');
  engine.respond('morningBedExitReason', 'Needed the bathroom');
  engine.rewindTo('morningNightWakings');
  assert.equal(engine.respond('morningNightWakings', '0')?.id, 'morningKss');
  const answers = engine.getResponseMap();
  assert.equal(answers.morningBedExits, undefined);
  assert.equal(answers.morningBedExitReason, undefined);
});
