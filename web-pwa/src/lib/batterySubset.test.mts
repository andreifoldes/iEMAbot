/**
 * Tests for the live cognitive battery: a page of only cognitive tasks shows LIVE_BATTERY_SIZE of
 * them in a live run, all of them in a practice run, and the same ones after a resume. Randomized pages also keep
 * their order after a resume.
 *
 * Run with `npm test`.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { adaptQuestionnaire, LIVE_BATTERY_SIZE } from './esmiraAdapter.ts';
import type { EsmiraQuestionnaire } from '../types.ts';

const task = (name: string) => ({ responseType: 'webapp', name, text: name, url: `https://x.test/${name}/index.html` });
const NAMES = ['pvt', 'symbols', 'shapes', 'prices'];

function questionnaire(inputsPerPage: unknown[][]): EsmiraQuestionnaire {
  return { internalId: 1, title: 'Q', pages: inputsPerPage.map((inputs) => ({ inputs })) } as unknown as EsmiraQuestionnaire;
}
const ids = (qn: EsmiraQuestionnaire, opts = {}) => adaptQuestionnaire(1, qn, 0, opts).questions.map((x) => x.id);

describe('cognitive battery subset', () => {
  const battery = questionnaire([[...NAMES.map(task)]]);

  it('draws LIVE_BATTERY_SIZE distinct tasks in authored order for a live run', () => {
    for (let i = 0; i < 50; i++) {
      const got = ids(battery);
      assert.equal(got.length, LIVE_BATTERY_SIZE);
      assert.equal(new Set(got).size, LIVE_BATTERY_SIZE);
      assert.deepEqual(got, NAMES.filter((n) => got.includes(n)));
    }
  });

  it('can draw every task over many runs', () => {
    const seen = new Set<string>();
    for (let i = 0; i < 200; i++) ids(battery).forEach((x) => seen.add(x));
    assert.equal(seen.size, NAMES.length);
  });

  it('keeps all tasks in a practice run', () => {
    assert.deepEqual(ids(battery, { practice: true }), NAMES);
  });

  it('reuses an earlier draw when resuming', () => {
    const first = ids(battery);
    for (let i = 0; i < 20; i++) assert.deepEqual(ids(battery, { battery: first }), first);
  });

  it('leaves pages with up to LIVE_BATTERY_SIZE tasks and mixed pages alone', () => {
    assert.deepEqual(ids(questionnaire([[task('a'), task('b')]])), ['a', 'b']);
    const mixed = questionnaire([[task('a'), task('b'), { responseType: 'text_input', name: 't', text: 't' }]]);
    assert.equal(ids(mixed).length, 3);
  });
});

describe('randomized page order on resume', () => {
  const item = (name: string) => ({ responseType: 'likert', name, text: name });
  const ITEMS = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
  const shuffled = { internalId: 1, title: 'Q', pages: [{ randomized: true, inputs: ITEMS.map(item) }] } as unknown as EsmiraQuestionnaire;

  it('keeps the order shown before, so the saved position still points at the same question', () => {
    const first = ids(shuffled);
    for (let i = 0; i < 20; i++) assert.deepEqual(ids(shuffled, { order: first }), first);
  });

  it('puts items added since at the end', () => {
    const before = ['h', 'g', 'f', 'e', 'd', 'c'];
    const got = ids(shuffled, { order: before });
    assert.deepEqual(got.slice(0, before.length), before);
    assert.deepEqual(new Set(got.slice(before.length)), new Set(['a', 'b']));
  });

  it('leaves pages that are not randomized in authored order', () => {
    const fixed = { internalId: 1, title: 'Q', pages: [{ inputs: ITEMS.map(item) }] } as unknown as EsmiraQuestionnaire;
    assert.deepEqual(ids(fixed, { order: [...ITEMS].reverse() }), ITEMS);
  });
});
