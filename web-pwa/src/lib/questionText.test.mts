/**
 * Tests for how an input's text, explanation and instruction reach the participant app:
 * plain-text paragraphs keep their breaks, rich text is left alone, and the instruction
 * (call to action after the explanation) is carried only when it has content.
 *
 * Run with `npm test`.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import type { EsmiraInput, EsmiraQuestionnaire } from '../types.ts';
import { adaptQuestionnaire, keepLineBreaks } from './esmiraAdapter.ts';

function adapt(...inputs: EsmiraInput[]) {
  const q: EsmiraQuestionnaire = { internalId: 1, title: 'Test', pages: [{ inputs }] };
  return adaptQuestionnaire(1, q, 0).questions;
}

describe('question text', () => {
  it('turns newlines in plain text into line breaks', () => {
    assert.equal(keepLineBreaks('How many?\n\nA nap is…\r\nCount all.'), 'How many?<br><br>A nap is…<br>Count all.');
  });

  it('leaves rich text from the editor unchanged', () => {
    const html = '<p>How many?</p>\n<p>A nap is…</p>';
    assert.equal(keepLineBreaks(html), html);
  });

  it('carries explanation and instruction, each with its line breaks', () => {
    const [q] = adapt({
      name: 'naps', responseType: 'text_input', text: 'How many times did you nap?',
      description: 'A nap is a time you decided to sleep.\nDozing is unplanned.',
      instruction: '<p>Count all times from when you got up.</p>',
    });
    assert.equal(q.subtext, 'A nap is a time you decided to sleep.<br>Dozing is unplanned.');
    assert.equal(q.instruction, '<p>Count all times from when you got up.</p>');
  });

  it('drops an empty instruction', () => {
    const [q] = adapt({ name: 'a', responseType: 'text_input', text: 'x', instruction: '<p></p>' });
    assert.equal(q.instruction, '');
  });
});
