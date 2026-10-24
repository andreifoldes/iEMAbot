import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { pickVoice } from './speech.ts';

describe('pickVoice', () => {
  it('prefers a natural/enhanced voice in the exact language', () => {
    const voices = [
      { name: 'Fred', lang: 'en-US', localService: true },
      { name: 'Daniel', lang: 'en-GB', localService: true },
      { name: 'Serena (Enhanced)', lang: 'en-GB', localService: true },
      { name: 'Google US English', lang: 'en-US' },
    ];
    assert.equal(pickVoice(voices, 'en-GB')?.name, 'Serena (Enhanced)');
  });
  it('never picks novelty or robotic voices over a normal one', () => {
    const voices = [{ name: 'Zarvox', lang: 'en-US' }, { name: 'eSpeak English', lang: 'en' }, { name: 'Samantha', lang: 'en-US', localService: true }];
    assert.equal(pickVoice(voices, 'en-US')?.name, 'Samantha');
  });
  it('falls back to the same base language, and to undefined for another one', () => {
    assert.equal(pickVoice([{ name: 'Anna', lang: 'de-DE' }], 'de-AT')?.name, 'Anna');
    assert.equal(pickVoice([{ name: 'Anna', lang: 'de-DE' }], 'fr-FR'), undefined);
  });
});

describe('pickVoice offline', () => {
  const voices = [
    { name: 'Google UK English Female', lang: 'en-GB', localService: false },
    { name: 'Device English', lang: 'en-GB', localService: true },
  ];
  it('skips network voices so the read-aloud is not silent', () => {
    assert.equal(pickVoice(voices, 'en-GB')?.name, 'Google UK English Female'); // online: the nicer one
    assert.equal(pickVoice(voices, 'en-GB', true)?.name, 'Device English');
  });
  it('falls back to the browser default when no on-device voice exists', () => {
    assert.equal(pickVoice([voices[0]], 'en-GB', true), undefined);
  });
});
