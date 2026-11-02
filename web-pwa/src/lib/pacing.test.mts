/**
 * Tests for the reading-speed pacing of chat bubbles.
 *
 * Run with `npm test`.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { MAX_PAUSE_MS, MIN_PAUSE_MS, READING_WPM, countWords, readingPauseMs, revealDelayMs, studyPacingWpm } from './pacing.ts';

describe('countWords', () => {
  it('counts plain words and ignores stray punctuation', () => {
    assert.equal(countWords('Welcome to ESRS 2026 mini check-in.'), 6);
    assert.equal(countWords('Hello — world !'), 2);
  });

  it('strips markup and entities from rich text', () => {
    assert.equal(countWords('<p>It takes about</p><p>5&nbsp;minutes</p>', true), 5);
  });
});

describe('readingPauseMs', () => {
  it('scales with length at the reading rate', () => {
    const words = Array.from({ length: 30 }, () => 'word').join(' ');
    assert.equal(readingPauseMs(words), Math.round((30 / READING_WPM) * 60000));
  });

  it('keeps short bubbles at the floor and long ones at the cap', () => {
    assert.equal(readingPauseMs('Hi'), MIN_PAUSE_MS);
    assert.equal(readingPauseMs(Array.from({ length: 500 }, () => 'word').join(' ')), MAX_PAUSE_MS);
  });
});

describe('revealDelayMs', () => {
  const bot = (content: string) => ({ sender: 'bot' as const, content });
  const user = (content: string) => ({ sender: 'user' as const, content });
  const tour = "Here's a quick tour. Below is every questionnaire this study uses — you can try a practice run of any of them (nothing you enter is saved). When you're ready, continue to the study.";

  it('holds a bot bubble that follows a bot bubble for the reading time of the one before', () => {
    assert.equal(revealDelayMs(bot(tour), bot('What to expect'), 1000, 1000), readingPauseMs(tour));
  });

  it('only waits for what is left of that reading time', () => {
    assert.equal(revealDelayMs(bot('Hi'), bot('Next'), 1000, 1000 + 500), MIN_PAUSE_MS - 500);
    assert.equal(revealDelayMs(bot('Hi'), bot('Next'), 0, MAX_PAUSE_MS + 1), 0);
  });

  it('never holds back the first bubble, the reply to the participant, or their own bubble', () => {
    assert.equal(revealDelayMs(undefined, bot(tour), 0, 0), 0);
    assert.equal(revealDelayMs(user('Yes, show me'), bot(tour), 0, 0), 0);
    assert.equal(revealDelayMs(bot(tour), user('Yes'), 0, 0), 0);
  });

  it('shows settled question bubbles and instant bubbles at once', () => {
    assert.equal(revealDelayMs(bot(tour), { ...bot('How are you?'), qid: 'q1' }, 0, 0), 0);
    assert.equal(revealDelayMs(bot(tour), { ...bot('Welcome back!'), instant: true }, 0, 0), 0);
  });
});

describe('per-study reading speed (messagePacingWpm)', () => {
  const words = Array.from({ length: 16 }, () => 'word').join(' ');
  const bot = (content: string) => ({ sender: 'bot' as const, content });

  it('defaults to the average reading rate and treats 0 or nonsense as off', () => {
    assert.equal(studyPacingWpm({}), READING_WPM);
    assert.equal(studyPacingWpm(null), READING_WPM);
    assert.equal(studyPacingWpm({ messagePacingWpm: 120 }), 120);
    assert.equal(studyPacingWpm({ messagePacingWpm: 0 }), 0);
    assert.equal(studyPacingWpm({ messagePacingWpm: -5 }), 0);
  });

  it('scales the pause with the study speed', () => {
    assert.equal(readingPauseMs(words, false, 120), 8000);
    assert.equal(revealDelayMs(bot(words), bot('Next'), 0, 0, 120), 8000);
  });

  it('shows everything at once when pacing is off', () => {
    assert.equal(readingPauseMs(words, false, 0), 0);
    assert.equal(revealDelayMs(bot(words), bot('Next'), 0, 0, 0), 0);
  });
});
