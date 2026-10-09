/**
 * Tests for the ESRS 2026 end-of-check-in snapshot (study field `webSnapshot`).
 *
 * Run with `npm test`.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { snapshotFor } from './snapshot.ts';

const study = { webSnapshot: 'esrs2026' };
const answers = (pa: number, na: number, kss: string) => ({
  alert: String(pa), inspired: String(pa), active: String(pa),
  nervous: String(na), upset: String(na), afraid: String(na), kss,
});

describe('snapshotFor', () => {
  it('is off unless the study opts in', () => {
    assert.equal(snapshotFor({}, answers(5, 1, '1: Extremely alert')), null);
    assert.equal(snapshotFor({ webSnapshot: 'other' }, answers(5, 1, '1: Extremely alert')), null);
  });

  it('reports a conference glow for high positive and low negative affect', () => {
    const text = snapshotFor(study, answers(4, 1, '2: Very alert'))!;
    assert.match(text, /^Your ESRS 2026 snapshot\n/);
    assert.match(text, /Mood: positive 4\.0\/5, negative 1\.0\/5\. Conference glow/);
    assert.match(text, /Sleepiness \(KSS\): 2\/9\. Wide awake/);
  });

  it('flags frazzled before low power, and reads sleepiness from the leading number', () => {
    assert.match(snapshotFor(study, answers(2, 4, '8: Sleepy, some effort to keep awake'))!, /A bit frazzled/);
    assert.match(snapshotFor(study, answers(2, 1, '8: Sleepy, some effort to keep awake'))!, /Running on low power/);
    assert.match(snapshotFor(study, answers(3, 2, '8: Sleepy, some effort to keep awake'))!, /Sleep debt detected/);
    assert.match(snapshotFor(study, answers(3, 2, '5: Neither alert nor sleepy'))!, /Steady and balanced/);
    assert.match(snapshotFor(study, answers(3, 2, '5: Neither alert nor sleepy'))!, /Somewhere between alert and drowsy/);
  });

  it('skips lines it has no answers for, but still says thank you', () => {
    const text = snapshotFor(study, { kss: '3: Alert' })!;
    assert.doesNotMatch(text, /Mood:/);
    assert.match(text, /Sleepiness \(KSS\): 3\/9/);
    const bare = snapshotFor(study, {})!;
    assert.doesNotMatch(bare, /snapshot/);
    assert.match(bare, /Thank you so much/);
  });
});
