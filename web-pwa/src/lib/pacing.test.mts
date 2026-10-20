/**
 * Tests for the reading-speed pacing of chat bubbles (webPacedMessages).
 *
 * Run with `npm test`.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { MAX_PAUSE_MS, MIN_PAUSE_MS, READING_WPM, countWords, readingPauseMs } from './pacing.ts';

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
