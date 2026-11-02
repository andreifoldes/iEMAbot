/**
 * Which questionnaires the tutorial overview offers a practice run of: every one except the
 * hidden "Cognitive Trials" sink, those the app starts by itself (onboarding), and those the
 * researcher left out of the tutorial (e.g. a second daytime check-in identical to the first).
 */
import { TRIALS_QN_TITLE } from './cognitiveTrials.ts';
import { autoStartIds } from './schedule.ts';
import type { EsmiraQuestionnaire, EsmiraStudy } from '../types';

export function tutorialQuestionnaires(study: Pick<EsmiraStudy, 'questionnaires' | 'schedule'>): EsmiraQuestionnaire[] {
  const autoStarting = autoStartIds(study);
  return study.questionnaires.filter((q) =>
    q.title !== TRIALS_QN_TITLE && !autoStarting.has(q.internalId) && q.includeInTutorial !== false);
}
