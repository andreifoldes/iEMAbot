/**
 * Tests for the researcher-supplied accessibility fields on an input: image alt text,
 * screen-reader label and read-aloud text. They must reach the participant app intact and
 * stay absent (so existing studies are unchanged) when the researcher leaves them empty.
 *
 * Run with `npm test`.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import type { EsmiraInput, EsmiraQuestionnaire } from '../types.ts';
import { adaptQuestionnaire, escapeAttr } from './esmiraAdapter.ts';

function adapt(...inputs: EsmiraInput[]) {
  const q: EsmiraQuestionnaire = { internalId: 1, title: 'Test', pages: [{ inputs }] };
  return adaptQuestionnaire(1, q, 0).questions;
}

describe('accessibility fields', () => {
  it('carries screen-reader label and spoken text onto the question', () => {
    const [q] = adapt({
      name: 'pain', responseType: 'likert', text: 'Pain?', likertSteps: 10,
      screenReaderLabel: ' Rate your pain from 1, no pain, to 10, worst pain ',
      spokenText: 'How much pain do you have right now?',
    });
    assert.equal(q.aria_label, 'Rate your pain from 1, no pain, to 10, worst pain');
    assert.equal(q.spoken_text, 'How much pain do you have right now?');
  });

  it('leaves both undefined when empty or blank', () => {
    const [q] = adapt({ name: 'a', responseType: 'likert', text: 'x', screenReaderLabel: '  ', spokenText: '' });
    assert.equal(q.aria_label, undefined);
    assert.equal(q.spoken_text, undefined);
  });

  it('writes the researcher alt text on image inputs, escaped', () => {
    const [q] = adapt({
      name: 'pic', responseType: 'image', text: 'Look', url: 'https://x.test/a.png?a=1&b="2"',
      imageAlt: 'A "red" door <open>',
    });
    assert.match(q.text, /alt="A &quot;red&quot; door &lt;open&gt;"/);
    assert.match(q.text, /src="https:\/\/x\.test\/a\.png\?a=1&amp;b=&quot;2&quot;"/);
  });

  it('keeps alt empty (decorative) when the researcher gives none', () => {
    const [q] = adapt({ name: 'pic', responseType: 'image', text: 'Look', url: 'https://x.test/a.png' });
    assert.match(q.text, /alt=""/);
  });

  it('escapeAttr escapes the characters that can break out of an attribute', () => {
    assert.equal(escapeAttr(`"&<>`), '&quot;&amp;&lt;&gt;');
  });
});
