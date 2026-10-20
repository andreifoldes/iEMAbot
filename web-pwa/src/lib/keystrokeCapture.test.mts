/**
 * Tests for the privacy-preserving keystroke-dynamics recorder (keystrokeCapture.ts).
 *
 * Covers class bucketing (no literal characters retained), the physical keydown/keyup
 * path (hold/release/press), the soft-keyboard beforeinput path (press-only), desktop
 * dedup (a usable keydown covers its beforeinput), auto-repeat filtering, paste + focus
 * markers, and captureMode. Run with `npm test` (Node's runner + native type-stripping).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  KeystrokeRecorderCore,
  classifyKey,
  classifyInput,
} from './keystrokeCapture.ts';

test('classifyKey buckets keys into classes and never returns the character', () => {
  assert.equal(classifyKey('a'), 'letter');
  assert.equal(classifyKey('Z'), 'letter');
  assert.equal(classifyKey('é'), 'letter');
  assert.equal(classifyKey('5'), 'digit');
  assert.equal(classifyKey(' '), 'whitespace');
  assert.equal(classifyKey('Enter'), 'whitespace');
  assert.equal(classifyKey('Tab'), 'whitespace');
  assert.equal(classifyKey('Backspace'), 'backspace');
  assert.equal(classifyKey('Delete'), 'delete');
  assert.equal(classifyKey('ArrowLeft'), 'navigation');
  assert.equal(classifyKey('Shift'), 'modifier');
  assert.equal(classifyKey('.'), 'punctuation');
  assert.equal(classifyKey('!'), 'punctuation');
  assert.equal(classifyKey('Escape'), 'other');
});

test('classifyInput maps soft-keyboard inputTypes', () => {
  assert.equal(classifyInput('insertText', 'a'), 'letter');
  assert.equal(classifyInput('insertText', '7'), 'digit');
  assert.equal(classifyInput('insertText', ''), null);
  assert.equal(classifyInput('insertText', null), null);
  assert.equal(classifyInput('insertText', 'the'), 'other'); // multi-char commit (autocomplete/IME)
  assert.equal(classifyInput('insertLineBreak', null), 'whitespace');
  assert.equal(classifyInput('deleteContentBackward', null), 'backspace');
  assert.equal(classifyInput('deleteContentForward', null), 'delete');
  assert.equal(classifyInput('insertFromPaste', 'pasted'), 'paste');
});

test('physical path records hold/release/press and dedups the covered beforeinput', () => {
  const r = new KeystrokeRecorderCore();
  r.onFocus(1000);
  r.onKeyDown({ key: 'h', code: 'KeyH', timeStamp: 1100 });
  r.onBeforeInput({ inputType: 'insertText', data: 'h', timeStamp: 1105 }); // covered → skipped
  r.onKeyUp({ key: 'h', code: 'KeyH', timeStamp: 1200 });
  r.onKeyDown({ key: 'i', code: 'KeyI', timeStamp: 1300 });
  r.onKeyUp({ key: 'i', code: 'KeyI', timeStamp: 1380 });
  r.onKeyDown({ key: 'Backspace', code: 'Backspace', timeStamp: 1500 });
  r.onBeforeInput({ inputType: 'deleteContentBackward', data: null, timeStamp: 1505 }); // covered
  r.onKeyUp({ key: 'Backspace', code: 'Backspace', timeStamp: 1560 });
  const res = r.finish('hi');

  assert.equal(res.captureMode, 'physical');
  assert.equal(res.transcript, 'hi');
  assert.equal(
    res.csv,
    [
      'class,hold,release,press',
      'focus_gained,,,0',
      'letter,0.1,0.2,0.1',
      'letter,0.08,0.38,0.3',
      'backspace,0.06,0.56,0.5',
      '',
    ].join('\n'),
  );
  // The literal answer must never appear in the dynamics log.
  assert.ok(!res.csv.includes('hi'));
});

test('soft path records press-only rows with blank hold/release', () => {
  const r = new KeystrokeRecorderCore();
  r.onFocus(500);
  // Android soft keyboard: keydown reports 229/Unidentified, then beforeinput carries the edit.
  r.onKeyDown({ key: 'Unidentified', code: '', keyCode: 229, timeStamp: 600 });
  r.onBeforeInput({ inputType: 'insertText', data: 'a', timeStamp: 610 });
  r.onBeforeInput({ inputType: 'deleteContentBackward', data: null, timeStamp: 800 });
  const res = r.finish('');

  assert.equal(res.captureMode, 'soft');
  assert.equal(
    res.csv,
    [
      'class,hold,release,press',
      'focus_gained,,,0',
      'letter,,,0.11',
      'backspace,,,0.3',
      '',
    ].join('\n'),
  );
});

test('auto-repeat keydowns are ignored (hold measured from the first press)', () => {
  const r = new KeystrokeRecorderCore();
  r.onKeyDown({ key: 'a', code: 'KeyA', timeStamp: 100, repeat: false });
  r.onKeyDown({ key: 'a', code: 'KeyA', timeStamp: 150, repeat: true }); // ignored
  r.onKeyUp({ key: 'a', code: 'KeyA', timeStamp: 200 });
  const res = r.finish('aaaa');

  const rows = res.csv.trim().split('\n').slice(1); // drop header
  assert.equal(rows.length, 1);
  assert.equal(rows[0], 'letter,0.1,0.1,0'); // hold 0.1 from first keydown (t0=100), not 150
});

test('paste always emits a marker even on the physical path', () => {
  const r = new KeystrokeRecorderCore();
  r.onKeyDown({ key: 'v', code: 'KeyV', timeStamp: 100 });
  r.onBeforeInput({ inputType: 'insertFromPaste', data: 'a long pasted passage', timeStamp: 110 });
  r.onKeyUp({ key: 'v', code: 'KeyV', timeStamp: 180 });
  const res = r.finish('a long pasted passage');

  assert.ok(res.csv.includes('paste,,,0.01'));
  assert.equal(res.captureMode, 'physical');
  assert.ok(!res.csv.includes('pasted')); // pasted content never in the log
});

test('blur drops dangling keydowns and marks the gap', () => {
  const r = new KeystrokeRecorderCore();
  r.onFocus(0);
  r.onKeyDown({ key: 'a', code: 'KeyA', timeStamp: 100 }); // never released before blur
  r.onBlur(300);
  r.onFocus(2000);
  r.onKeyDown({ key: 'b', code: 'KeyB', timeStamp: 2100 });
  r.onKeyUp({ key: 'b', code: 'KeyB', timeStamp: 2160 });
  const res = r.finish('b');

  const classes = res.csv.trim().split('\n').slice(1).map((l) => l.split(',')[0]);
  assert.deepEqual(classes, ['focus_gained', 'focus_lost', 'focus_gained', 'letter']);
});
