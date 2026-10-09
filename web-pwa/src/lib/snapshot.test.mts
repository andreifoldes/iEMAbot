/**
 * Tests for the ESRS 2026 end-of-check-in messages (study field `webSnapshot`): the snapshot, the
 * participant's own answers, and the reaction-time line.
 *
 * Run with `npm test`.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { answersText, pvtSummary, snapshotMessages } from './snapshot.ts';

const study = { webSnapshot: 'esrs2026' };
const likert = (id: string, text: string) => ({ id, text, type: 'likert' as const, scale_max: 5 });
const questions = [
  likert('conf_enjoyment', 'How are you enjoying the conference so far?'),
  { id: 'conf_fuel', text: 'What is keeping you going right now?', type: 'choice' as const },
  likert('alert', 'I feel alert right now.'),
  likert('nervous', 'I feel nervous right now.'),
  likert('inspired', 'I feel inspired right now.'),
  likert('upset', 'I feel upset right now.'),
  likert('active', 'I feel active right now.'),
  likert('afraid', 'I feel afraid right now.'),
  { id: 'kss', text: 'How sleepy do you feel right now?', type: 'choice' as const },
  { id: 'pvt', text: 'Reaction-time game', title: 'Reaction-time game', type: 'cognitive' as const },
];
const answers = (pa: number, na: number, kss: string): Record<string, string> => ({
  conf_enjoyment: '1', conf_fuel: 'Coffee',
  alert: String(pa), inspired: String(pa), active: String(pa),
  nervous: String(na), upset: String(na), afraid: String(na), kss, pvt: '{"n_trials":17}',
});

describe('snapshotMessages', () => {
  it('is off unless the study opts in', () => {
    assert.equal(snapshotMessages({}, questions, answers(5, 1, '1: Extremely alert'), null), null);
    assert.equal(snapshotMessages({ webSnapshot: 'other' }, questions, {}, null), null);
  });

  it('gives snapshot, answers and thank-you, in that order', () => {
    const [snapshot, list, thanks] = snapshotMessages(study, questions, answers(4, 1, '2: Very alert'), null)!;
    assert.match(snapshot, /^Your ESRS 2026 snapshot\n/);
    assert.match(snapshot, /Mood: positive 4\.0\/5, negative 1\.0\/5\. Conference glow/);
    assert.match(snapshot, /Sleepiness \(KSS\): 2\/9\. Wide awake/);
    assert.match(list, /^Your answers\n/);
    assert.match(thanks, /^Thank you so much for trying out iEMAbot at ESRS 2026!/);
  });

  it('flags frazzled before low power, and reads sleepiness from the leading number', () => {
    const mood = (pa: number, na: number, kss: string) => snapshotMessages(study, questions, answers(pa, na, kss), null)![0];
    assert.match(mood(2, 4, '8: Sleepy, some effort to keep awake'), /A bit frazzled/);
    assert.match(mood(2, 1, '8: Sleepy, some effort to keep awake'), /Running on low power/);
    assert.match(mood(3, 2, '8: Sleepy, some effort to keep awake'), /Sleep debt detected/);
    assert.match(mood(3, 2, '5: Neither alert nor sleepy'), /Steady and balanced/);
    assert.match(mood(3, 2, '5: Neither alert nor sleepy'), /Somewhere between alert and drowsy/);
  });

  it('adds the no-server note only for local-only studies', () => {
    const plain = snapshotMessages(study, questions, answers(3, 2, '3: Alert'), null)![1];
    assert.doesNotMatch(plain, /sent nothing/);
    const local = snapshotMessages({ ...study, webLocalOnly: true }, questions, answers(3, 2, '3: Alert'), null)![1];
    assert.match(local, /This chat is the only copy: the app sent nothing to the server\.$/);
  });
});

describe('answersText', () => {
  it('lists answers like the bot: short labels, /5 on scales, full text otherwise', () => {
    const text = answersText(questions, answers(1, 1, '3: Alert'), '17 taps, average 555 ms, 7 lapses');
    assert.equal(text, [
      'Your answers',
      '• Conference enjoyment: 1/5',
      '• Keeping you going: Coffee',
      '• I feel alert right now.: 1/5',
      '• I feel nervous right now.: 1/5',
      '• I feel inspired right now.: 1/5',
      '• I feel upset right now.: 1/5',
      '• I feel active right now.: 1/5',
      '• I feel afraid right now.: 1/5',
      '• Sleepiness (KSS): 3: Alert',
      '• Reaction-time game: 17 taps, average 555 ms, 7 lapses',
    ].join('\n'));
  });

  it('leaves out questions without an answer (skipped or hidden by show_if)', () => {
    const partial = { conf_enjoyment: '4', kss: '6: Some signs of sleepiness' };
    const text = answersText(questions, partial, null);
    assert.equal(text, 'Your answers\n• Conference enjoyment: 4/5\n• Sleepiness (KSS): 6: Some signs of sleepiness');
  });

  it('says "completed" when the game ran but gave no usable trials', () => {
    assert.match(answersText(questions, { pvt: '{}' }, null), /• Reaction-time game: completed$/);
  });
});

describe('pvtSummary', () => {
  it('counts taps and lapses, ignoring false starts', () => {
    const trials = [
      { rt_ms: 300, is_lapse: false, is_false_start: false },
      { rt_ms: 700, is_lapse: true, is_false_start: false },
      { rt_ms: 50, is_lapse: false, is_false_start: true },
    ];
    assert.equal(pvtSummary(trials), '2 taps, average 500 ms, 1 lapse');
  });

  it('pluralises lapses and returns null without usable data', () => {
    assert.equal(pvtSummary([{ rt_ms: 400, is_lapse: false }]), '1 taps, average 400 ms, 0 lapses');
    assert.equal(pvtSummary([]), null);
    assert.equal(pvtSummary(undefined), null);
    assert.equal(pvtSummary([{ rt_ms: 'x' }, 7, null]), null);
  });
});
