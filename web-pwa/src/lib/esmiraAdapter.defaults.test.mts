/**
 * The dashboard leaves defaults out of the saved study (see DataStructure in src/frontend), so what it saves can lack any
 * field that holds its default. Motivating bug: free text is the default type, so a free-text question saved by the
 * dashboard has no `responseType`, the app dropped it, and a questionnaire with only such questions showed nothing.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import type { EsmiraQuestionnaire } from '../types.ts';
import { adaptQuestionnaire } from './esmiraAdapter.ts';

function questionnaireWith(input: Record<string, unknown>): EsmiraQuestionnaire {
  return { internalId: 1, title: 'Q', pages: [{ inputs: [input] }] } as unknown as EsmiraQuestionnaire;
}

function adaptedQuestions(input: Record<string, unknown>) {
  const session = adaptQuestionnaire(1, questionnaireWith(input), 0);
  return session.questions as unknown as { id: string; type: string; scale_max?: number }[];
}

test('a question saved without a type is free text, as it is in the dashboard', () => {
  const questions = adaptedQuestions({ name: 'sleep_quality', text: 'How did you sleep?', listChoices: [], subInputs: [] });
  assert.equal(questions.length, 1);
  assert.equal(questions[0].id, 'sleep_quality');
  assert.equal(questions[0].type, adaptedQuestions({ name: 'x', text: 'y', responseType: 'text_input' })[0].type);
});

test('a likert question saved without its steps has the default 5', () => {
  assert.equal(adaptedQuestions({ name: 'mood', text: 'Mood?', responseType: 'likert' })[0].scale_max, 5);
});
