/**
 * Parity with the chat channels (Telegram, later WhatsApp): the PHP conversation
 * engine re-implements relevance and the `responses` encoding, and must agree with
 * this PWA. Both sides run the same fixtures:
 *   test/fixtures/channels/relevance.json  — parseRelevance + show_if evaluation
 *   test/fixtures/channels/encoding.json   — buildEsmiraResponses columns
 * PHP side: test/backend/channels/ParityFixturesTest.php.
 *
 * Run with `npm test` (Node's built-in runner + native TS type-stripping).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import type { EsmiraQuestionnaire } from '../types.ts';
import { adaptQuestionnaire, parseRelevance } from './esmiraAdapter.ts';
import { OfflineSurveyEngine } from './surveyEngine.ts';
import { buildEsmiraResponses } from './esmiraResponses.ts';

const fixture = (name: string) =>
  JSON.parse(readFileSync(new URL(`../../../test/fixtures/channels/${name}.json`, import.meta.url), 'utf8'));

test('relevance parsing matches the shared fixtures', () => {
  for (const c of fixture('relevance').parse) {
    assert.deepEqual(parseRelevance(c.relevance), c.parsed, c.relevance);
  }
});

test('relevance evaluation matches the shared fixtures', () => {
  for (const c of fixture('relevance').holds) {
    // Evaluate through the engine: a gated item after a controller with the given answers.
    const showIf = parseRelevance(c.relevance)!;
    const q: EsmiraQuestionnaire = {
      internalId: 1,
      title: 'T',
      pages: [{ inputs: [{ name: 'gated', responseType: 'text_input', relevance: c.relevance }] }],
    } as EsmiraQuestionnaire;
    const session = adaptQuestionnaire(1, q, 0);
    session.answered = { ...c.responses };
    const engine = new OfflineSurveyEngine(session);
    const shown = engine.getCurrentQuestion()?.id === 'gated';
    assert.equal(shown, c.expected, `${c.relevance} ${JSON.stringify(c.responses)} (${showIf.operator})`);
  }
});

test('responses encoding matches the shared fixtures', () => {
  for (const c of fixture('encoding').cases) {
    const session = adaptQuestionnaire(1, c.questionnaire as EsmiraQuestionnaire, 0);
    const map: Record<string, string> = {};
    for (const [name, value] of Object.entries(c.answers)) {
      if (Array.isArray(value)) {
        const q = session.questions.find((x) => x.id === name)!;
        map[name] = (value as number[]).map((i) => q.options![i]).join(',');
      } else {
        map[name] = value as string;
      }
    }
    assert.deepEqual(buildEsmiraResponses(session.questions, map, c.other), c.expected, c.name);
  }
});
