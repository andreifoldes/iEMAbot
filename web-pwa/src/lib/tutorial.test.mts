/**
 * Tests for the questionnaires the tutorial overview offers a practice run of.
 *
 * Run with `npm test`.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { tutorialQuestionnaires } from './tutorial.ts';
import type { EsmiraQuestionnaire, EsmiraStudy } from '../types';

const qn = (internalId: number, title: string, extra: Partial<EsmiraQuestionnaire> = {}): EsmiraQuestionnaire =>
  ({ internalId, title, pages: [], ...extra });

describe('tutorialQuestionnaires', () => {
  it('lists every questionnaire by default', () => {
    const study = { questionnaires: [qn(1, 'Morning'), qn(2, 'Daytime 1'), qn(3, 'Daytime 2')] } as EsmiraStudy;
    assert.deepEqual(tutorialQuestionnaires(study).map((q) => q.internalId), [1, 2, 3]);
  });

  it('leaves out the ones the researcher excluded from the tutorial', () => {
    const study = {
      questionnaires: [qn(1, 'Daytime 1', { includeInTutorial: true }), qn(2, 'Daytime 2', { includeInTutorial: false })],
    } as EsmiraStudy;
    assert.deepEqual(tutorialQuestionnaires(study).map((q) => q.internalId), [1]);
  });

  it('leaves out the hidden trials questionnaire and auto-started onboarding', () => {
    const study = {
      questionnaires: [qn(1, 'Onboarding'), qn(2, 'Evening'), qn(3, 'Cognitive Trials')],
      schedule: { checkins: [{ questionnaireId: 1, autoStart: true }, { questionnaireId: 2 }] },
    } as unknown as EsmiraStudy;
    assert.deepEqual(tutorialQuestionnaires(study).map((q) => q.internalId), [2]);
  });
});
